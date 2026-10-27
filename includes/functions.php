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
 * Generate Next Sequential GRN Number (e.g. GRN-20261027-1005)
 * Student 2: Denuka (Component B Owner)
 */
function generate_grn_number(PDO $pdo): string {
    $todayPrefix = 'GRN-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT grn_number FROM grn_headers WHERE grn_number LIKE :prefix ORDER BY id DESC LIMIT 1");
    $stmt->execute([':prefix' => $todayPrefix . '%']);
    $lastNum = $stmt->fetchColumn();

    if ($lastNum) {
        $parts = explode('-', $lastNum);
        $lastSeq = (int)end($parts);
        $nextSeq = $lastSeq + 1;
    } else {
        $nextSeq = 1001;
    }
    return $todayPrefix . $nextSeq;
}

/**
 * Process and record an official Goods Received Note (GRN)
 * Student 2: Denuka (Component B Owner)
 *
 * Executes inside an atomic transaction:
 * - Validates supplier details (linked to suppliers table)
 * - Identifies Internal Kitchen Store (Supplier #1) for Production Receipts
 * - Inserts header record into grn_headers with supplier_id FK
 * - Inserts line records into grn_items
 * - Increments inventory stock in inventory table
 * - Records audited movements in stock_movements (type='restock')
 * - Writes to central audit_log
 * - Full ACID rollback on any failure
 */
function process_grn_receipt(
    PDO $pdo,
    int $userId,
    string|int $supplier,
    ?string $invoiceReference,
    ?string $notes,
    array $items
): array {
    if (empty($items)) {
        throw new InvalidArgumentException("At least one valid dish item must be included in the GRN.");
    }

    // Resolve supplier ID, name, and internal vs external classification
    $supplierId = 1;
    $supplierName = '';
    $isInternalProduction = false;

    if (is_numeric($supplier)) {
        $supplierId = (int)$supplier;
        $sStmt = $pdo->prepare("SELECT id, name, category FROM suppliers WHERE id = :id LIMIT 1");
        $sStmt->execute([':id' => $supplierId]);
        $sRow = $sStmt->fetch();
        if ($sRow) {
            $supplierName = $sRow['name'];
            $isInternalProduction = ((int)$sRow['id'] === 1 || $sRow['category'] === 'internal');
        } else {
            $supplierId = 1;
            $supplierName = 'Central Kitchen Production (Internal Store / Hub)';
            $isInternalProduction = true;
        }
    } else {
        $supplierName = trim((string)$supplier);
        if ($supplierName === '') {
            throw new InvalidArgumentException("Supplier name or ID is required to process a Goods Received Note.");
        }
        $sStmt = $pdo->prepare("SELECT id, name, category FROM suppliers WHERE name = :name LIMIT 1");
        $sStmt->execute([':name' => $supplierName]);
        $sRow = $sStmt->fetch();
        if ($sRow) {
            $supplierId = (int)$sRow['id'];
            $isInternalProduction = ((int)$sRow['id'] === 1 || $sRow['category'] === 'internal');
        } else {
            $code = 'SUP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $supplierName) ?: 'GEN', 0, 3)) . '-' . random_int(10, 99);
            $insSup = $pdo->prepare("INSERT INTO suppliers (code, name, category, is_active, created_at) VALUES (:code, :name, 'external', 1, NOW())");
            $insSup->execute([':code' => $code, ':name' => $supplierName]);
            $supplierId = (int)$pdo->lastInsertId();
        }
    }

    $grnNumber = generate_grn_number($pdo);
    $totalCost = 0.0;

    // Pre-calculate line totals and validate
    $validatedItems = [];
    foreach ($items as $idx => $item) {
        $dishId   = (int)($item['dish_id'] ?? 0);
        $qty      = (int)($item['quantity'] ?? 0);
        $unitCost = (float)($item['unit_cost'] ?? 0.0);
        $batchNo  = trim($item['batch_number'] ?? '');
        $expiry   = !empty($item['expiry_date']) ? trim($item['expiry_date']) : null;

        if ($dishId <= 0 || $qty <= 0) {
            continue; // Skip invalid or blank rows
        }

        $lineTotal = round($qty * $unitCost, 2);
        $totalCost += $lineTotal;

        $validatedItems[] = [
            'dish_id'           => $dishId,
            'quantity_received' => $qty,
            'unit_cost'         => $unitCost,
            'line_total'        => $lineTotal,
            'batch_number'      => $batchNo ?: null,
            'expiry_date'       => $expiry
        ];
    }

    if (empty($validatedItems)) {
        throw new InvalidArgumentException("Please specify at least one item with a positive quantity.");
    }

    $pdo->beginTransaction();
    try {
        // 1. Insert Master GRN Header with foreign key supplier_id
        $headerStmt = $pdo->prepare("
            INSERT INTO grn_headers (grn_number, supplier_id, supplier_name, invoice_reference, received_by_user_id, notes, total_cost, received_at, created_at)
            VALUES (:grn_num, :supplier_id, :supplier_name, :invoice_ref, :user_id, :notes, :total_cost, NOW(), NOW())
        ");
        $headerStmt->execute([
            ':grn_num'       => $grnNumber,
            ':supplier_id'   => $supplierId,
            ':supplier_name' => $supplierName,
            ':invoice_ref'   => !empty($invoiceReference) ? trim($invoiceReference) : null,
            ':user_id'       => $userId,
            ':notes'         => !empty($notes) ? trim($notes) : null,
            ':total_cost'    => $totalCost
        ]);
        $grnId = (int)$pdo->lastInsertId();

        // 2. Insert GRN Items and update Inventory
        $itemStmt = $pdo->prepare("
            INSERT INTO grn_items (grn_id, dish_id, quantity_received, unit_cost, line_total, batch_number, expiry_date)
            VALUES (:grn_id, :dish_id, :qty, :cost, :total, :batch, :expiry)
        ");

        $updInvStmt = $pdo->prepare("
            UPDATE inventory 
            SET current_stock = current_stock + :qty, updated_at = NOW() 
            WHERE dish_id = :dish_id
        ");

        $checkInvStmt = $pdo->prepare("SELECT id FROM inventory WHERE dish_id = :dish_id");
        $createInvStmt = $pdo->prepare("
            INSERT INTO inventory (dish_id, current_stock, reorder_level, updated_at)
            VALUES (:dish_id, :qty, 10, NOW())
        ");

        foreach ($validatedItems as $vItem) {
            // Insert line item
            $itemStmt->execute([
                ':grn_id'  => $grnId,
                ':dish_id' => $vItem['dish_id'],
                ':qty'     => $vItem['quantity_received'],
                ':cost'    => $vItem['unit_cost'],
                ':total'   => $vItem['line_total'],
                ':batch'   => $vItem['batch_number'],
                ':expiry'  => $vItem['expiry_date']
            ]);

            // Ensure inventory row exists; update or insert
            $checkInvStmt->execute([':dish_id' => $vItem['dish_id']]);
            if ($checkInvStmt->fetch()) {
                $updInvStmt->execute([
                    ':qty'     => $vItem['quantity_received'],
                    ':dish_id' => $vItem['dish_id']
                ]);
            } else {
                $createInvStmt->execute([
                    ':dish_id' => $vItem['dish_id'],
                    ':qty'     => $vItem['quantity_received']
                ]);
            }

            // Distinguish internal production receipt from external vendor receipt in audit trail
            if ($isInternalProduction) {
                $movementReason = "Goods Received from Production via {$grnNumber}";
                if (!empty($invoiceReference)) {
                    $movementReason .= " [Batch/Job: {$invoiceReference}]";
                }
                if (!empty($vItem['batch_number'])) {
                    $movementReason .= " (Lot: {$vItem['batch_number']})";
                }
            } else {
                $movementReason = "Received via {$grnNumber} (Supplier: {$supplierName})";
                if (!empty($invoiceReference)) {
                    $movementReason .= " [Invoice: {$invoiceReference}]";
                }
                if (!empty($vItem['batch_number'])) {
                    $movementReason .= " [Batch: {$vItem['batch_number']}]";
                }
            }

            record_stock_movement(
                $pdo,
                $vItem['dish_id'],
                $userId,
                'restock',
                $vItem['quantity_received'],
                $movementReason
            );
        }

        // 3. Central Audit Log
        $receiptTypeLabel = $isInternalProduction ? 'Internal Production Receipt' : 'External Supplier Delivery';
        log_action(
            $pdo,
            'GRN_CREATED',
            "Generated {$receiptTypeLabel} {$grnNumber} from {$supplierName} (" . count($validatedItems) . " items, Total: LKR " . number_format($totalCost, 2) . ")",
            $userId
        );

        $pdo->commit();

        return [
            'success'       => true,
            'grn_id'        => $grnId,
            'grn_number'    => $grnNumber,
            'supplier_id'   => $supplierId,
            'supplier_name' => $supplierName,
            'is_internal'   => $isInternalProduction,
            'total_cost'    => $totalCost,
            'items_count'   => count($validatedItems)
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Generate Next Sequential Stock Adjustment Note Number (e.g. SAN-20261027-1005)
 * Student 2: Denuka (Component B Owner)
 */
function generate_san_number(PDO $pdo): string {
    $todayPrefix = 'SAN-' . date('Ymd') . '-';
    $stmt = $pdo->prepare("SELECT san_number FROM stock_adjustment_notes WHERE san_number LIKE :prefix ORDER BY id DESC LIMIT 1");
    $stmt->execute([':prefix' => $todayPrefix . '%']);
    $lastNum = $stmt->fetchColumn();

    if ($lastNum) {
        $parts = explode('-', $lastNum);
        $lastSeq = (int)end($parts);
        $nextSeq = $lastSeq + 1;
    } else {
        $nextSeq = 1001;
    }
    return $todayPrefix . $nextSeq;
}

/**
 * Process and record an official Stock Adjustment Note (SAN)
 * Student 2: Denuka (Component B Owner)
 *
 * Professional Inventory Adjustment Workflow:
 * - Full ACID transaction with row-level lock (SELECT ... FOR UPDATE)
 * - Directional integrity: 'decrease' (wastage/expired/damage) vs 'increase' (count overage)
 * - Refuses to allow inventory to drop below zero
 * - Writes to stock_adjustment_notes with full audit snapshots (stock_before, stock_after, root_cause, corrective_action)
 * - Updates inventory.current_stock
 * - Records audited stock movement in stock_movements
 * - Logs to centralized audit_log
 *
 * @throws InvalidArgumentException
 * @throws Exception
 */
function process_stock_adjustment_note(
    PDO $pdo,
    int $userId,
    int $dishId,
    string $type,
    string $direction,
    int $quantity,
    string $rootCause,
    ?string $correctiveAction = null
): array {
    if ($dishId <= 0) {
        throw new InvalidArgumentException("A valid dish must be selected for stock adjustment.");
    }
    if ($quantity <= 0) {
        throw new InvalidArgumentException("Adjustment quantity must be greater than zero.");
    }
    if (empty(trim($rootCause))) {
        throw new InvalidArgumentException("A documented root cause is required for a formal Stock Adjustment Note.");
    }

    $validTypes = ['wastage', 'spillage', 'expired', 'count_overage', 'count_shortage', 'internal_sampling'];
    if (!in_array($type, $validTypes, true)) {
        throw new InvalidArgumentException("Invalid adjustment type: '{$type}'.");
    }

    if (!in_array($direction, ['increase', 'decrease'], true)) {
        throw new InvalidArgumentException("Adjustment direction must be either 'increase' or 'decrease'.");
    }

    $sanNumber = generate_san_number($pdo);

    $pdo->beginTransaction();
    try {
        // 1. Row-level lock to prevent concurrent stock calculation race conditions
        $lockStmt = $pdo->prepare("
            SELECT i.current_stock, d.name AS dish_name 
            FROM inventory i
            JOIN dishes d ON i.dish_id = d.id
            WHERE i.dish_id = :dish_id 
            FOR UPDATE
        ");
        $lockStmt->execute([':dish_id' => $dishId]);
        $row = $lockStmt->fetch();

        if (!$row) {
            throw new Exception("Dish #{$dishId} has no inventory record.");
        }

        $stockBefore = (int)$row['current_stock'];
        $dishName = $row['dish_name'];

        if ($direction === 'decrease') {
            if ($stockBefore < $quantity) {
                throw new Exception("Stock reduction of {$quantity} units exceeds current available inventory ({$stockBefore} units in stock for '{$dishName}'). Stock cannot go below zero.");
            }
            $stockAfter = $stockBefore - $quantity;
            $stockDelta = -$quantity;
            $movementType = 'wastage';
        } else {
            $stockAfter = $stockBefore + $quantity;
            $stockDelta = $quantity;
            $movementType = 'adjustment';
        }

        // 2. Update master inventory
        $updInv = $pdo->prepare("
            UPDATE inventory 
            SET current_stock = :new_stock, updated_at = NOW() 
            WHERE dish_id = :dish_id
        ");
        $updInv->execute([
            ':new_stock' => $stockAfter,
            ':dish_id'   => $dishId
        ]);

        // 3. Insert official Stock Adjustment Note voucher record
        $sanStmt = $pdo->prepare("
            INSERT INTO stock_adjustment_notes 
            (san_number, dish_id, adjusted_by_user_id, adjustment_type, direction, quantity, stock_before, stock_after, root_cause, corrective_action, created_at)
            VALUES 
            (:san_num, :dish_id, :user_id, :type, :direction, :qty, :before, :after, :cause, :action, NOW())
        ");
        $sanStmt->execute([
            ':san_num'   => $sanNumber,
            ':dish_id'   => $dishId,
            ':user_id'   => $userId,
            ':type'      => $type,
            ':direction' => $direction,
            ':qty'       => $quantity,
            ':before'    => $stockBefore,
            ':after'     => $stockAfter,
            ':cause'     => trim($rootCause),
            ':action'    => !empty($correctiveAction) ? trim($correctiveAction) : null
        ]);
        $sanId = (int)$pdo->lastInsertId();

        // 4. Record in stock_movements for audit history
        $movementReason = "Adjusted via {$sanNumber} [{$type} / {$direction}]: " . trim($rootCause);
        record_stock_movement(
            $pdo,
            $dishId,
            $userId,
            $movementType,
            $stockDelta,
            $movementReason
        );

        // 5. Central Audit Log
        $dirLabel = ($direction === 'increase') ? 'INCREASE (Overage)' : 'DECREASE (Wastage)';
        log_action(
            $pdo,
            'STOCK_ADJUSTMENT_NOTE',
            "Generated {$sanNumber} for '{$dishName}' ({$dirLabel}: {$quantity} units. Stock: {$stockBefore} -> {$stockAfter}). Cause: " . trim($rootCause),
            $userId
        );

        $pdo->commit();

        return [
            'success'         => true,
            'san_id'          => $sanId,
            'san_number'      => $sanNumber,
            'dish_id'         => $dishId,
            'dish_name'       => $dishName,
            'direction'       => $direction,
            'adjustment_type' => $type,
            'quantity'        => $quantity,
            'stock_before'    => $stockBefore,
            'stock_after'     => $stockAfter
        ];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
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

    $saved = is_uploaded_file($file['tmp_name'])
        ? move_uploaded_file($file['tmp_name'], $targetPath)
        : copy($file['tmp_name'], $targetPath);

    if (!$saved) {
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
