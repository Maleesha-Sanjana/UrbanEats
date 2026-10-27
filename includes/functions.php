<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Shared Helper Functions for Components A, B, and C
 *
 * Provides shared utilities for sanitization, currency formatting,
 * flash messaging, secure file uploads, stock control, and order workflow.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php';

/**
 * Dynamically determine application base path
 */
function get_app_base(): string {
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $publicPos = strpos($scriptPath, '/public');
    return ($publicPos !== false) ? substr($scriptPath, 0, $publicPos + 7) : '/UrbanEats/public';
}
$appBase = get_app_base();

/**
 * Escape string for safe HTML output (XSS prevention)
 */
function e(?string $value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format currency in Sri Lankan Rupees (LKR)
 */
function format_price(float|int|string $amount): string {
    $num = is_numeric($amount) ? (float)$amount : 0.0;
    return 'LKR ' . number_format($num, 2, '.', ',');
}

/**
 * Format human-readable date & time
 */
function format_datetime(?string $datetime, string $format = 'M j, Y - g:i A'): string {
    if (empty($datetime)) return 'N/A';
    $time = strtotime($datetime);
    return $time ? date($format, $time) : 'N/A';
}

/**
 * Set flash message for display on next page request
 */
function set_flash(string $type, string $message): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear flash messages
 */
function get_flashes(): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    // Check legacy individual keys
    foreach (['success', 'warning', 'error', 'info'] as $key) {
        if (!empty($_SESSION["flash_$key"])) {
            $flashes[] = ['type' => $key, 'message' => $_SESSION["flash_$key"]];
            unset($_SESSION["flash_$key"]);
        }
    }
    return $flashes;
}

/**
 * Render flash alert banner HTML
 */
function render_flash_messages(): string {
    $flashes = get_flashes();
    if (empty($flashes)) return '';

    $html = '<div class="flash-container">';
    foreach ($flashes as $flash) {
        $type = e($flash['type']);
        $msg = e($flash['message']);
        $html .= "<div class=\"alert alert-{$type} animate-fade-in\">";
        $html .= "<span>{$msg}</span>";
        $html .= "<button type=\"button\" class=\"alert-close\" onclick=\"this.parentElement.remove();\">&times;</button>";
        $html .= "</div>";
    }
    $html .= '</div>';
    return $html;
}

/* ==========================================================================
   COMPONENT B SHARED CONTRACT FUNCTIONS (Menu & Inventory)
   Student 2: Denuka (Component B Owner)
   ========================================================================== */

/**
 * Transaction-Safe Stock Deduction
 * Component C calls this contract function at checkout.
 * 
 * VIVA QUESTIONS & EXPLANATIONS:
 * 1. Why conditional UPDATE instead of read-then-write?
 *    If we did `SELECT stock` then `if (stock >= qty) UPDATE stock`, two concurrent requests
 *    could both read stock = 1 simultaneously, both pass the check, and both decrement,
 *    causing stock to drop to -1 (race condition / overselling).
 * 2. How does atomic conditional UPDATE fix this?
 *    The query `WHERE dish_id = :dish_id AND current_stock >= :min_qty` is executed under MySQL
 *    row-level lock. Only the first transaction decrements and gets rowCount = 1; the second transaction
 *    sees current_stock < min_qty, gets rowCount = 0, and triggers an exception that rolls back the order.
 *
 * @param PDO $pdo Active PDO connection with ongoing transaction
 * @param int $dishId Dish primary key
 * @param int $quantity Units to deduct
 * @return bool True if deduction succeeded
 * @throws Exception If stock is insufficient or update fails
 */
function reduce_stock(PDO $pdo, int $dishId, int $quantity): bool {
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Quantity to reduce must be greater than zero.");
    }

    // Atomic conditional decrement: only updates if current_stock >= quantity
    $stmt = $pdo->prepare("
        UPDATE inventory
        SET current_stock = current_stock - :qty,
            updated_at = NOW()
        WHERE dish_id = :dish_id AND current_stock >= :min_qty
    ");

    $stmt->execute([
        ':qty'     => $quantity,
        ':min_qty' => $quantity,
        ':dish_id' => $dishId
    ]);

    if ($stmt->rowCount() === 0) {
        // Fetch dish name for descriptive error message
        $nameStmt = $pdo->prepare("SELECT name FROM dishes WHERE id = :id");
        $nameStmt->execute([':id' => $dishId]);
        $dishName = $nameStmt->fetchColumn() ?: "Dish #$dishId";
        throw new Exception("Insufficient stock for '{$dishName}'. The item may have just sold out.");
    }

    return true;
}

/**
 * Record stock movement (restock, wastage, sale, adjustment)
 */
function record_stock_movement(
    PDO $pdo,
    int $dishId,
    ?int $userId,
    string $movementType,
    int $quantity,
    string $reason
): bool {
    $stmt = $pdo->prepare("
        INSERT INTO stock_movements (dish_id, user_id, movement_type, quantity, reason, created_at)
        VALUES (:dish_id, :user_id, :movement_type, :qty, :reason, NOW())
    ");
    return $stmt->execute([
        ':dish_id'       => $dishId,
        ':user_id'       => $userId,
        ':movement_type' => $movementType,
        ':qty'           => $quantity,
        ':reason'        => $reason
    ]);
}

/**
 * Secure image upload helper for dishes
 * Student 2: Denuka (Component B Owner)
 *
 * VIVA QUESTIONS & EXPLANATIONS:
 * 1. What checks does image upload perform?
 *    - Checks PHP upload error codes (ensures partial/corrupted uploads are rejected).
 *    - Enforces maximum file size limit (2MB) preventing denial-of-service via huge payloads.
 *    - Server-side MIME type verification using PHP's `finfo(FILEINFO_MIME_TYPE)`.
 *    - File extension check against a strict whitelist (jpg, jpeg, png, webp).
 *    - Generates a cryptographically random, unguessable filename (`random_bytes(16)`).
 * 2. Why is file extension alone not enough?
 *    An attacker could rename a malicious executable PHP script (e.g. `shell.php` -> `shell.jpg`).
 *    Checking extension alone would accept it, which could lead to remote code execution (RCE).
 *    Using `finfo` inspects magic bytes at the start of the binary file to verify it is genuinely an image.
 */
function upload_dish_image(array $file): array {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Image exceeds server upload_max_filesize limit.',
            UPLOAD_ERR_FORM_SIZE  => 'Image exceeds maximum allowed form size.',
            UPLOAD_ERR_PARTIAL    => 'Image was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No image was selected for upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
        ];
        return ['success' => false, 'error' => $errors[$file['error']] ?? 'Upload failed.'];
    }

    $maxSize = get_config('app.max_upload_size', 2 * 1024 * 1024);
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'Image size exceeds maximum limit of 2MB.'];
    }

    // Validate MIME type using finfo
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp'
    ];

    if (!array_key_exists($mime, $allowedMimes)) {
        return ['success' => false, 'error' => 'Invalid image format. Allowed formats: JPG, PNG, WEBP.'];
    }

    // Double check file extension
    $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $validExts = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($originalExt, $validExts, true)) {
        return ['success' => false, 'error' => 'Invalid file extension.'];
    }

    $targetExt = $allowedMimes[$mime];
    $uploadDir = rtrim(get_config('app.upload_dir'), '/\\') . '/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    // Generate cryptographically unique, unguessable filename
    $uniqueName = 'dish_' . bin2hex(random_bytes(16)) . '.' . $targetExt;
    $targetPath = $uploadDir . $uniqueName;

    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded image. Check directory permissions.'];
    }

    return [
        'success'  => true,
        'filename' => $uniqueName,
        'url'      => '/UrbanEats/public/uploads/' . $uniqueName
    ];
}

/* ==========================================================================
   COMPONENT C SHARED CONTRACT FUNCTIONS (Cart, Orders, Workflow)
   ========================================================================== */

/**
 * Get Cart Items populated with live database data
 * Re-reads dish details, pricing, and live inventory directly from DB.
 * Never trusts prices stored in session or sent from browser.
 */
function get_cart_details(PDO $pdo): array {
    if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        return [
            'items'     => [],
            'subtotal'  => 0.0,
            'item_count'=> 0,
            'has_stock_issue' => false
        ];
    }

    $dishIds = array_keys($_SESSION['cart']);
    if (empty($dishIds)) {
        return ['items' => [], 'subtotal' => 0.0, 'item_count' => 0, 'has_stock_issue' => false];
    }

    $placeholders = implode(',', array_fill(0, count($dishIds), '?'));
    $stmt = $pdo->prepare("
        SELECT d.id, d.name, d.price, d.image_url, d.is_active,
               c.name AS category_name,
               COALESCE(i.current_stock, 0) AS current_stock
        FROM dishes d
        JOIN categories c ON d.category_id = c.id
        LEFT JOIN inventory i ON d.id = i.dish_id
        WHERE d.id IN ($placeholders)
    ");
    $stmt->execute($dishIds);
    $dishes = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $items = [];
    $subtotal = 0.0;
    $itemCount = 0;
    $hasStockIssue = false;

    foreach ($_SESSION['cart'] as $id => $requestedQty) {
        $id = (int)$id;
        $qty = max(1, (int)$requestedQty);

        if (!isset($dishes[$id]) || (int)$dishes[$id]['is_active'] !== 1) {
            // Dish no longer active or deleted
            unset($_SESSION['cart'][$id]);
            continue;
        }

        $dish = $dishes[$id];
        $currentStock = (int)$dish['current_stock'];
        $unitPrice = (float)$dish['price'];
        $lineTotal = $unitPrice * $qty;
        $isStockLow = ($qty > $currentStock);

        if ($isStockLow) {
            $hasStockIssue = true;
        }

        $items[] = [
            'dish_id'       => $id,
            'name'          => $dish['name'],
            'category_name' => $dish['category_name'],
            'price'         => $unitPrice,
            'quantity'      => $qty,
            'current_stock' => $currentStock,
            'line_total'    => $lineTotal,
            'image_url'     => $dish['image_url'],
            'is_stock_low'  => $isStockLow
        ];

        $subtotal += $lineTotal;
        $itemCount += $qty;
    }

    return [
        'items'           => $items,
        'subtotal'        => $subtotal,
        'item_count'      => $itemCount,
        'has_stock_issue' => $hasStockIssue
    ];
}

/**
 * Validate order status workflow state machine
 * Allowed transitions:
 * - Placed -> Preparing, Cancelled
 * - Preparing -> Ready, Cancelled
 * - Ready -> Out for Delivery (if delivery), Collected (if pickup), Cancelled
 * - Out for Delivery -> Completed, Cancelled
 * - Collected -> Completed
 * - Completed -> (Terminal)
 * - Cancelled -> (Terminal)
 */
function is_valid_status_transition(string $currentStatus, string $newStatus): bool {
    $transitions = [
        'Placed' => ['Preparing', 'Cancelled'],
        'Preparing' => ['Ready', 'Cancelled'],
        'Ready' => ['Out for Delivery', 'Collected', 'Cancelled'],
        'Out for Delivery' => ['Completed', 'Cancelled'],
        'Collected' => ['Completed'],
        'Completed' => [],
        'Cancelled' => []
    ];

    if (!isset($transitions[$currentStatus])) {
        return false;
    }

    return in_array($newStatus, $transitions[$currentStatus], true);
}

/**
 * Retrieve coupon details and validate constraints
 */
function validate_coupon(PDO $pdo, string $code, float $orderSubtotal): array {
    $code = trim(strtoupper($code));
    if (empty($code)) {
        return ['valid' => false, 'error' => 'Please enter a coupon code.'];
    }

    $stmt = $pdo->prepare("
        SELECT * FROM coupons
        WHERE UPPER(code) = :code AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([':code' => $code]);
    $coupon = $stmt->fetch();

    if (!$coupon) {
        return ['valid' => false, 'error' => 'Invalid coupon code.'];
    }

    // Expiry check
    if (strtotime($coupon['expires_at']) < time()) {
        return ['valid' => false, 'error' => 'This coupon has expired.'];
    }

    // Usage limit check
    if ($coupon['usage_limit'] !== null && (int)$coupon['times_used'] >= (int)$coupon['usage_limit']) {
        return ['valid' => false, 'error' => 'This coupon has reached its maximum usage limit.'];
    }

    // Minimum order amount check
    if ($orderSubtotal < (float)$coupon['min_order_amount']) {
        return [
            'valid' => false,
            'error' => 'Minimum order amount for this coupon is ' . format_price((float)$coupon['min_order_amount']) . '.'
        ];
    }

    // Calculate discount amount
    $discount = 0.0;
    if ($coupon['discount_type'] === 'percentage') {
        $discount = ($orderSubtotal * (float)$coupon['discount_value']) / 100.0;
        if (!empty($coupon['max_discount']) && $discount > (float)$coupon['max_discount']) {
            $discount = (float)$coupon['max_discount'];
        }
    } else {
        // Fixed amount
        $discount = min($orderSubtotal, (float)$coupon['discount_value']);
    }

    return [
        'valid'          => true,
        'coupon'         => $coupon,
        'discount_amount'=> round($discount, 2)
    ];
}
