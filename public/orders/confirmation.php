<?php

$currentPage = 'orders';
$pageTitle = 'Order Confirmation & Invoice - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/functions.php';

$appBase = get_app_base_url();
require_login();

$pdo = get_db_connection();
$orderNumber = trim($_GET['order_number'] ?? '');
$userId = (int)$_SESSION['user_id'];

if (empty($orderNumber)) {
    header("Location: {$appBase}/orders/history.php");
    exit;
}

// Fetch order details ensuring customer can only view their own order unless Admin/Staff
$orderStmt = $pdo->prepare("
    SELECT o.*, u.full_name AS customer_name, u.email AS customer_email,
           c.code AS coupon_code,
           p.payment_method, p.payment_status, p.transaction_reference, p.paid_at
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN coupons c ON o.coupon_id = c.id
    LEFT JOIN payments p ON o.id = p.order_id
    WHERE o.order_number = :num
    LIMIT 1
");
$orderStmt->execute([':num' => $orderNumber]);
$order = $orderStmt->fetch();

if (!$order) {
    http_response_code(404);
    render_error_page(404, 'Order Not Found', 'No order was found with that order number.', "{$appBase}/index.php");
    exit;
}

// Access guard
if (!has_role(['admin', 'kitchen_staff']) && (int)$order['user_id'] !== $userId) {
    http_response_code(403);
    render_error_page(403, 'Unauthorized', 'You do not have permission to view this order confirmation.');
    exit;
}

// Fetch order items (stored snapshot prices)
$itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :oid ORDER BY id ASC");
$itemsStmt->execute([':oid' => $order['id']]);
$items = $itemsStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="site-container" style="margin-top: 1.5rem; margin-bottom: 3.5rem;">
    <!-- Action Bar (Not visible when printed) -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem;" class="no-print">
        <div>
            <span style="color: var(--color-success); font-weight: 700; font-size: 0.9rem;">✅ Order Confirmed & Sent to Kitchen</span>
            <h1 style="font-size: 1.85rem; margin-top: 0.25rem;">Invoice & Receipt: <?= e($order['order_number']) ?></h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <button type="button" class="btn btn-outline" onclick="window.print()">
                🖨️ Print Receipt
            </button>
            <a href="<?= $appBase ?>/orders/tracking.php?order_number=<?= urlencode($order['order_number']) ?>" class="btn btn-primary">
                📍 Track Live Kitchen Status &rarr;
            </a>
        </div>
    </div>

    <!-- Printable Invoice Card -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 3rem 2.5rem; box-shadow: var(--shadow-sm); max-width: 860px; margin: 0 auto;" id="printableInvoice">
        <!-- Invoice Header -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; padding-bottom: 2rem; border-bottom: 2px solid var(--border-color); margin-bottom: 2rem;">
            <div>
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                    <span style="font-size: 2rem;">🍲</span>
                    <span style="font-family: var(--font-heading); font-size: 1.75rem; font-weight: 800; color: var(--color-secondary);">
                        Urban<span style="color: var(--color-primary);">Eats</span>
                    </span>
                </div>
                <p style="font-size: 0.85rem; color: var(--text-muted); line-height: 1.5;">
                    UrbanEats Cloud Kitchen Ltd.<br>
                    45 Galle Road, Colombo 03, Sri Lanka<br>
                    Hotline: +94 11 234 5678 • VAT/Tax ID: LK-987654321
                </p>
            </div>

            <div style="text-align: right;">
                <span class="badge badge-active" style="font-size: 0.85rem; padding: 0.35rem 0.85rem; margin-bottom: 0.5rem;">
                    Status: <?= e($order['status']) ?>
                </span>
                <div style="font-family: var(--font-heading); font-size: 1.25rem; font-weight: 800; color: var(--color-secondary);">
                    <?= e($order['order_number']) ?>
                </div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">
                    Date: <?= format_datetime($order['created_at']) ?>
                </div>
            </div>
        </div>

        <!-- Customer & Order Meta Split -->
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
            <div>
                <h3 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">Customer Information</h3>
                <p style="font-size: 0.95rem; line-height: 1.6;">
                    <strong><?= e($order['customer_name']) ?></strong><br>
                    Email: <?= e($order['customer_email']) ?><br>
                    Phone: <?= e($order['contact_phone']) ?>
                </p>
            </div>

            <div>
                <h3 style="font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">Fulfillment & Payment</h3>
                <p style="font-size: 0.95rem; line-height: 1.6;">
                    <strong>Type:</strong> <?= ucfirst(e($order['order_type'])) ?><br>
                    <?php if ($order['order_type'] === 'delivery'): ?>
                        <strong>Address:</strong> <?= nl2br(e($order['delivery_address'])) ?><br>
                    <?php else: ?>
                        <strong>Pickup:</strong> Direct from Kitchen Counter (Colombo 03)<br>
                    <?php endif; ?>
                    <strong>Payment:</strong> <?= ucfirst(str_replace('_', ' ', $order['payment_method'])) ?> 
                    (Ref: <code><?= e($order['transaction_reference'] ?: 'Pending') ?></code>)
                </p>
            </div>
        </div>

        <?php if (!empty($order['notes'])): ?>
            <div style="background: var(--bg-subtle); padding: 0.75rem 1rem; border-radius: var(--radius-sm); margin-bottom: 2rem; font-size: 0.85rem;">
                <strong>Special Kitchen Notes:</strong> <?= e($order['notes']) ?>
            </div>
        <?php endif; ?>

        <!-- Order Items Table -->
        <table class="data-table" style="margin-bottom: 1.5rem;">
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th style="text-align: right;">Unit Price</th>
                    <th style="text-align: center;">Qty</th>
                    <th style="text-align: right;">Total (LKR)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td>
                            <strong><?= e($item['dish_name']) ?></strong>
                        </td>
                        <td style="text-align: right;"><?= format_price($item['unit_price']) ?></td>
                        <td style="text-align: center;"><?= $item['quantity'] ?></td>
                        <td style="text-align: right;"><strong><?= format_price($item['subtotal']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Totals Breakdown -->
        <div style="margin-left: auto; max-width: 320px;">
            <div class="cart-summary-row">
                <span>Subtotal</span>
                <span><?= format_price($order['subtotal']) ?></span>
            </div>

            <?php if ((float)$order['discount_amount'] > 0): ?>
                <div class="cart-summary-row" style="color: #16a34a; font-weight: 700;">
                    <span>Voucher Discount (<?= e($order['coupon_code'] ?? 'Promo') ?>)</span>
                    <span>- <?= format_price($order['discount_amount']) ?></span>
                </div>
            <?php endif; ?>

            <div class="cart-summary-row">
                <span>Delivery Fee</span>
                <span><?= format_price($order['delivery_fee']) ?></span>
            </div>

            <div class="cart-summary-total" style="border-top: 2px solid var(--color-secondary); padding-top: 0.75rem;">
                <span>Total Amount</span>
                <span><?= format_price($order['total_amount']) ?></span>
            </div>
        </div>

        <!-- Footer Notice -->
        <div style="margin-top: 3rem; padding-top: 1.5rem; border-top: 1px dashed var(--border-color); text-align: center; font-size: 0.8rem; color: var(--text-muted);">
            <p>Thank you for choosing UrbanEats Cloud Kitchen! Your food is being crafted with passion and authentic Ceylon spices.</p>
            <p style="margin-top: 0.25rem;">For real-time updates on your delivery, check your online status timeline anytime.</p>
        </div>
    </div>
</div>

<style>
@media print {
    .site-header, .site-footer, .announcement-bar, .no-print {
        display: none !important;
    }
    body, .site-main {
        background: white !important;
        padding: 0 !important;
        margin: 0 !important;
    }
    #printableInvoice {
        border: none !important;
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
    }
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
