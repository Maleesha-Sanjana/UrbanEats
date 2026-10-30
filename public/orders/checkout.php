<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$appBase = get_app_base_url();
require_login();

$pdo = get_db_connection();
$userId = (int)$_SESSION['user_id'];

// 1. Fetch live user details for pre-filling
$uStmt = $pdo->prepare("SELECT full_name, email, phone, address FROM users WHERE id = :id LIMIT 1");
$uStmt->execute([':id' => $userId]);
$currentUserProfile = $uStmt->fetch();

// 2. Fetch live cart items and totals directly from DB
$cartData = get_cart_details($pdo);
$cartItems = $cartData['items'];
$subtotal = $cartData['subtotal'];

if (empty($cartItems)) {
    set_flash('warning', 'Your shopping cart is empty. Please select dishes before checkout.');
    header("Location: {$appBase}/menu/list.php");
    exit;
}

$checkoutError = '';
$orderType = $_POST['order_type'] ?? 'delivery';
$deliveryAddress = $_POST['delivery_address'] ?? ($currentUserProfile['address'] ?? '');
$contactPhone = $_POST['contact_phone'] ?? ($currentUserProfile['phone'] ?? '');
$notes = trim($_POST['notes'] ?? '');
$paymentMethod = $_POST['payment_method'] ?? 'cash_on_delivery';
$couponCode = trim(strtoupper($_POST['coupon_code'] ?? ($_SESSION['coupon_code'] ?? '')));

// 3. Process Checkout Transaction on POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'place_order') {
    validate_csrf_or_fail();

    // Basic Validation
    if (!in_array($orderType, ['delivery', 'pickup'], true)) {
        $checkoutError = 'Please choose a valid delivery or pickup option.';
    } elseif ($orderType === 'delivery' && empty(trim($deliveryAddress))) {
        $checkoutError = 'Delivery address is required for delivery orders.';
    } elseif (empty(trim($contactPhone))) {
        $checkoutError = 'Contact phone number is required.';
    } elseif (strlen($contactPhone) > 20) {
        $checkoutError = 'Contact phone number must be 20 characters or fewer.';
    } elseif (strlen($deliveryAddress) > 2000 || strlen($notes) > 2000) {
        $checkoutError = 'Address and preparation instructions must be 2,000 characters or fewer.';
    } elseif (!in_array($paymentMethod, ['cash_on_delivery', 'card', 'bank_transfer'], true)) {
        $checkoutError = 'Please choose a valid payment method.';
    } else {
        $requestedQuantities = [];
        foreach ($_SESSION['cart'] as $dishId => $requestedQty) {
            $dishId = (int)$dishId;
            $requestedQty = (int)$requestedQty;
            if ($dishId > 0 && $requestedQty > 0) {
                $requestedQuantities[$dishId] = $requestedQty;
            }
        }

        if (empty($requestedQuantities)) {
            $checkoutError = 'Your shopping cart is empty. Please select dishes before checkout.';
        } else {
            ksort($requestedQuantities, SORT_NUMERIC);
            $pdo->beginTransaction();
            try {
                $dishIds = array_keys($requestedQuantities);
                sort($dishIds, SORT_NUMERIC);
                $placeholders = implode(',', array_fill(0, count($dishIds), '?'));
                $dishStmt = $pdo->prepare("
                    SELECT d.id, d.name, d.price, d.is_active,
                           COALESCE(i.current_stock, 0) AS current_stock
                    FROM dishes d
                    JOIN inventory i ON i.dish_id = d.id
                    WHERE d.id IN ($placeholders)
                    ORDER BY d.id
                    FOR UPDATE
                ");
                $dishStmt->execute($dishIds);
                $lockedDishes = $dishStmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

                $verifiedItems = [];
                $subtotal = 0.0;
                foreach ($requestedQuantities as $dishId => $qty) {
                    if (!isset($lockedDishes[$dishId]) || (int)$lockedDishes[$dishId]['is_active'] !== 1) {
                        throw new Exception("A selected dish is no longer available. Please review your cart.");
                    }

                    $dish = $lockedDishes[$dishId];
                    if ((int)$dish['current_stock'] < $qty) {
                        throw new Exception("Insufficient stock for '{$dish['name']}'. Please review your cart.");
                    }

                    $unitPrice = (float)$dish['price'];
                    $lineTotal = round($unitPrice * $qty, 2);
                    $subtotal += $lineTotal;
                    $verifiedItems[] = [
                        'dish_id' => $dishId,
                        'dish_name' => $dish['name'],
                        'unit_price' => $unitPrice,
                        'quantity' => $qty,
                        'subtotal' => $lineTotal
                    ];
                }
                $subtotal = round($subtotal, 2);

                $couponId = null;
                $discountAmount = 0.0;
                if ($couponCode !== '') {
                    $couponCheck = validate_coupon($pdo, $couponCode, $subtotal, true);
                    if (!$couponCheck['valid']) {
                        throw new Exception('Voucher Error: ' . $couponCheck['error']);
                    }
                    $couponId = (int)$couponCheck['coupon']['id'];
                    $discountAmount = (float)$couponCheck['discount_amount'];
                }

                $deliveryFee = ($orderType === 'delivery') ? 300.00 : 0.00;
                $grandTotal = round(max(0.00, ($subtotal - $discountAmount) + $deliveryFee), 2);
                $orderNumber = sprintf('UE-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(4))));

                // 1. Insert order using totals calculated from the locked database rows.
                $orderStmt = $pdo->prepare("
                    INSERT INTO orders (
                        order_number, user_id, order_type, delivery_address, contact_phone, notes,
                        subtotal, discount_amount, coupon_id, delivery_fee, total_amount, status, created_at
                    ) VALUES (
                        :order_num, :user_id, :order_type, :address, :phone, :notes,
                        :subtotal, :discount, :coupon_id, :fee, :total, 'Placed', NOW()
                    )
                ");
                $orderStmt->execute([
                    ':order_num' => $orderNumber,
                    ':user_id' => $userId,
                    ':order_type' => $orderType,
                    ':address' => ($orderType === 'delivery') ? trim($deliveryAddress) : null,
                    ':phone' => trim($contactPhone),
                    ':notes' => $notes !== '' ? $notes : null,
                    ':subtotal' => $subtotal,
                    ':discount' => $discountAmount,
                    ':coupon_id' => $couponId,
                    ':fee' => $deliveryFee,
                    ':total' => $grandTotal
                ]);
                $orderId = (int)$pdo->lastInsertId();

                // 2. Insert price snapshots and deduct stock in the same transaction.
                $itemStmt = $pdo->prepare("
                    INSERT INTO order_items (order_id, dish_id, dish_name, unit_price, quantity, subtotal)
                    VALUES (:order_id, :dish_id, :dish_name, :unit_price, :qty, :line_subtotal)
                ");

                foreach ($verifiedItems as $item) {
                    $itemStmt->execute([
                        ':order_id' => $orderId,
                        ':dish_id' => $item['dish_id'],
                        ':dish_name' => $item['dish_name'],
                        ':unit_price' => $item['unit_price'],
                        ':qty' => $item['quantity'],
                        ':line_subtotal' => $item['subtotal']
                    ]);

                    reduce_stock($pdo, $item['dish_id'], $item['quantity']);
                    record_stock_movement(
                        $pdo,
                        $item['dish_id'],
                        $userId,
                        'sale',
                        -$item['quantity'],
                        "Deducted for Order #{$orderNumber}"
                    );
                }

                // 3. Record Simulated Payment
                $paymentStatus = ($paymentMethod === 'cash_on_delivery') ? 'pending' : 'completed';
                $txnRef = match ($paymentMethod) {
                    'card'          => 'TXN-CARD-' . strtoupper(bin2hex(random_bytes(4))),
                    'bank_transfer' => 'BFT-' . date('Ymd') . '-' . random_int(10000, 99999),
                    default         => 'COD-' . $orderNumber
                };
                $paidAt = ($paymentStatus === 'completed') ? date('Y-m-d H:i:s') : null;

                $payStmt = $pdo->prepare("
                    INSERT INTO payments (order_id, payment_method, payment_status, transaction_reference, amount, paid_at, created_at)
                    VALUES (:order_id, :method, :status, :ref, :amount, :paid_at, NOW())
                ");
                $payStmt->execute([
                    ':order_id' => $orderId,
                    ':method'   => $paymentMethod,
                    ':status'   => $paymentStatus,
                    ':ref'      => $txnRef,
                    ':amount'   => $grandTotal,
                    ':paid_at'  => $paidAt
                ]);

                // 4. Record Initial Workflow Status History
                $histStmt = $pdo->prepare("
                    INSERT INTO order_status_history (order_id, changed_by_user_id, old_status, new_status, comment, created_at)
                    VALUES (:order_id, :user_id, NULL, 'Placed', 'Order placed by customer via web portal', NOW())
                ");
                $histStmt->execute([
                    ':order_id' => $orderId,
                    ':user_id'  => $userId
                ]);

                // 5. Update coupon usage while its row remains locked.
                if ($couponId !== null) {
                    $updCoupon = $pdo->prepare("
                        UPDATE coupons
                        SET times_used = times_used + 1
                        WHERE id = :cid
                          AND is_active = 1
                          AND expires_at >= NOW()
                          AND (usage_limit IS NULL OR times_used < usage_limit)
                    ");
                    $updCoupon->execute([':cid' => $couponId]);
                    if ($updCoupon->rowCount() !== 1) {
                        throw new Exception('This coupon is no longer available. Please remove it and try again.');
                    }
                }

                // 6. Central Audit Trail
                log_action($pdo, 'ORDER_PLACED', "Order {$orderNumber} placed for " . format_price($grandTotal) . " via {$paymentMethod}", $userId);

                // Commit Transaction
                $pdo->commit();

                // Clear Session Cart
                $_SESSION['cart'] = [];
                unset($_SESSION['coupon_code']);

                set_flash('success', "Order #{$orderNumber} placed successfully! Kitchen has received your ticket.");
                header("Location: {$appBase}/orders/confirmation.php?order_number=" . urlencode($orderNumber));
                exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $checkoutError = 'Checkout Failed: ' . $e->getMessage();
            }
        }
    }
}

// Compute live totals for display
$couponDiscount = 0.0;
$appliedCouponCode = $couponCode;
if (!empty($appliedCouponCode) && $subtotal > 0) {
    $cVal = validate_coupon($pdo, $appliedCouponCode, $subtotal);
    if ($cVal['valid']) {
        $couponDiscount = $cVal['discount_amount'];
    }
}
$estimatedDeliveryFee = ($orderType === 'delivery') ? 300.00 : 0.00;
$estimatedTotal = max(0.00, ($subtotal - $couponDiscount) + $estimatedDeliveryFee);

$currentPage = 'checkout';
$pageTitle = 'Secure Checkout - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="margin-bottom: 2rem;">
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component C: Checkout Engine</span>
        <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">Finalize Your Order</h1>
    </div>

    <?php if (!empty($checkoutError)): ?>
        <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
            <strong>Transaction Error:</strong> <?= e($checkoutError) ?>
        </div>
    <?php endif; ?>

    <form action="<?= $appBase ?>/orders/checkout.php" method="POST" id="checkoutForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="place_order">

        <div class="checkout-grid">
            <!-- Left: Delivery & Payment Details -->
            <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; box-shadow: var(--shadow-sm);">
                <!-- Step 1: Order Type -->
                <div style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
                    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">1. Choose Fulfillment Method</h2>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <label class="payment-radio-card" style="padding: 1rem;">
                            <input type="radio" name="order_type" value="delivery" <?= $orderType === 'delivery' ? 'checked' : '' ?> onchange="updateFulfillment(this.value)">
                            <div class="pay-label-text">
                                <strong style="display: block;">🚀 Express Delivery</strong>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">Colombo Area • Flat LKR 300.00</span>
                            </div>
                        </label>

                        <label class="payment-radio-card" style="padding: 1rem;">
                            <input type="radio" name="order_type" value="pickup" <?= $orderType === 'pickup' ? 'checked' : '' ?> onchange="updateFulfillment(this.value)">
                            <div class="pay-label-text">
                                <strong style="display: block;">🥡 Kitchen Pickup</strong>
                                <span style="font-size: 0.8rem; color: var(--text-muted);">45 Galle Road, Colombo 03 • Free</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Step 2: Contact & Address Details -->
                <div style="margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border-color);">
                    <h2 style="font-size: 1.25rem; margin-bottom: 1rem;">2. Contact & Delivery Location</h2>

                    <div class="form-group" id="addressGroup" style="<?= $orderType === 'pickup' ? 'display: none;' : '' ?>">
                        <label for="delivery_address" class="form-label">Delivery Street Address <span class="required">*</span></label>
                        <textarea id="delivery_address" name="delivery_address" rows="3" class="form-control" placeholder="House/Apartment number, Street name, City (e.g. 12/4 Flower Road, Colombo 07)"><?= e($deliveryAddress) ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="contact_phone" class="form-label">Active Mobile Phone Number <span class="required">*</span></label>
                        <input type="tel" id="contact_phone" name="contact_phone" class="form-control" value="<?= e($contactPhone) ?>" placeholder="+94 77 123 4567" required>
                        <p class="form-hint">Our dispatch rider or kitchen manager will call you if needed.</p>
                    </div>

                    <div class="form-group">
                        <label for="notes" class="form-label">Special Preparation / Delivery Instructions (Optional)</label>
                        <input type="text" id="notes" name="notes" class="form-control" value="<?= e($notes) ?>" placeholder="e.g. Extra spicy, no cutlery needed, leave at security desk...">
                    </div>
                </div>

                <!-- Step 3: Simulated Payment Method -->
                <div>
                    <h2 style="font-size: 1.25rem; margin-bottom: 0.5rem;">3. Select Payment Method</h2>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                        Per assignment specifications: Payment is simulated and recorded in the database.
                    </p>

                    <div class="payment-radio-group">
                        <label class="payment-radio-card">
                            <input type="radio" name="payment_method" value="cash_on_delivery" <?= $paymentMethod === 'cash_on_delivery' ? 'checked' : '' ?>>
                            <div class="pay-label-text">
                                <span style="font-size: 1.25rem;">💵</span>
                                <strong>Cash on Delivery</strong>
                            </div>
                        </label>

                        <label class="payment-radio-card">
                            <input type="radio" name="payment_method" value="card" <?= $paymentMethod === 'card' ? 'checked' : '' ?>>
                            <div class="pay-label-text">
                                <span style="font-size: 1.25rem;">💳</span>
                                <strong>Credit / Debit Card</strong>
                            </div>
                        </label>

                        <label class="payment-radio-card">
                            <input type="radio" name="payment_method" value="bank_transfer" <?= $paymentMethod === 'bank_transfer' ? 'checked' : '' ?>>
                            <div class="pay-label-text">
                                <span style="font-size: 1.25rem;">🏦</span>
                                <strong>Bank Transfer</strong>
                            </div>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Right: Order Items & Financial Totals -->
            <div class="summary-card">
                <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                    Order Details (<?= count($cartItems) ?> Items)
                </h2>

                <div style="max-height: 260px; overflow-y: auto; margin-bottom: 1.25rem;">
                    <?php foreach ($cartItems as $item): ?>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.75rem; font-size: 0.875rem;">
                            <div>
                                <strong><?= $item['quantity'] ?>x <?= e($item['name']) ?></strong>
                                <span style="display: block; font-size: 0.75rem; color: var(--text-muted);"><?= format_price($item['price']) ?> each</span>
                            </div>
                            <span><strong><?= format_price($item['line_total']) ?></strong></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="cart-summary-row">
                    <span>Subtotal</span>
                    <span><?= format_price($subtotal) ?></span>
                </div>

                <?php if ($couponDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #16a34a; font-weight: 700;">
                        <span>Voucher (<?= e($appliedCouponCode) ?>)</span>
                        <span>- <?= format_price($couponDiscount) ?></span>
                    </div>
                <?php endif; ?>

                <div class="cart-summary-row">
                    <span>Delivery Fee</span>
                    <span id="deliveryFeeDisplay"><?= format_price($estimatedDeliveryFee) ?></span>
                </div>

                <div class="cart-summary-total">
                    <span>Grand Total</span>
                    <span id="grandTotalDisplay"><?= format_price($estimatedTotal) ?></span>
                </div>

                <div style="margin-top: 1.75rem;">
                    <button type="submit" class="btn btn-primary btn-block btn-lg" style="box-shadow: var(--shadow-md);">
                        Confirm & Place Order 🔥
                    </button>
                </div>

                <div style="margin-top: 1.25rem; text-align: center;">
                    <a href="<?= $appBase ?>/orders/cart.php" style="font-size: 0.85rem; color: var(--text-muted);">
                        &larr; Back to Shopping Cart
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
    function updateFulfillment(type) {
        const addressGroup = document.getElementById('addressGroup');
        const deliveryFeeDisplay = document.getElementById('deliveryFeeDisplay');
        const grandTotalDisplay = document.getElementById('grandTotalDisplay');

        const subtotal = <?= (float)$subtotal ?>;
        const discount = <?= (float)$couponDiscount ?>;
        const fee = (type === 'delivery') ? 300.00 : 0.00;
        const total = Math.max(0, (subtotal - discount) + fee);

        if (type === 'delivery') {
            addressGroup.style.display = 'block';
            deliveryFeeDisplay.textContent = 'LKR 300.00';
        } else {
            addressGroup.style.display = 'none';
            deliveryFeeDisplay.textContent = 'LKR 0.00 (Free Pickup)';
        }

        grandTotalDisplay.textContent = 'LKR ' + total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
