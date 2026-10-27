<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Live Order Tracking & Workflow Timeline
 *
 * Implements real-time order status tracking with interactive stepper nodes,
 * audit history logs, and customer self-service cancellation when in 'Placed' status.
 */

$currentPage = 'orders';
$pageTitle = 'Live Order Tracking - UrbanEats Cloud Kitchen';
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

// 1. Fetch Order Details
$stmt = $pdo->prepare("
    SELECT o.*, u.full_name AS customer_name, u.phone AS customer_phone,
           p.payment_method, p.payment_status, p.transaction_reference
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN payments p ON o.id = p.order_id
    WHERE o.order_number = :num
    LIMIT 1
");
$stmt->execute([':num' => $orderNumber]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    render_error_page(404, 'Order Not Found', 'No order was found matching that tracking number.', "{$appBase}/orders/history.php");
    exit;
}

// Access check: only owner or Staff/Admin
if (!has_role(['admin', 'kitchen_staff']) && (int)$order['user_id'] !== $userId) {
    http_response_code(403);
    render_error_page(403, 'Access Denied', 'You do not have permission to track this order.');
    exit;
}

// 2. Handle Customer Cancellation (Allowed ONLY when status is 'Placed')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel_order') {
    validate_csrf_or_fail();

    if ($order['status'] !== 'Placed') {
        set_flash('error', 'Orders can only be cancelled while in "Placed" state before cooking begins.');
    } else {
        $pdo->beginTransaction();
        try {
            $lockStmt = $pdo->prepare("SELECT status FROM orders WHERE id = :id FOR UPDATE");
            $lockStmt->execute([':id' => $order['id']]);
            $lockedStatus = $lockStmt->fetchColumn();

            if ($lockedStatus !== 'Placed' || !is_valid_status_transition((string)$lockedStatus, 'Cancelled')) {
                throw new Exception('This order can no longer be cancelled because its status has changed.');
            }

            $upd = $pdo->prepare("UPDATE orders SET status = 'Cancelled', updated_at = NOW() WHERE id = :id");
            $upd->execute([':id' => $order['id']]);

            $itemStmt = $pdo->prepare("SELECT dish_id, quantity FROM order_items WHERE order_id = :oid");
            $itemStmt->execute([':oid' => $order['id']]);
            foreach ($itemStmt->fetchAll() as $item) {
                $restockStmt = $pdo->prepare("UPDATE inventory SET current_stock = current_stock + :qty WHERE dish_id = :id");
                $restockStmt->execute([':qty' => $item['quantity'], ':id' => $item['dish_id']]);
                record_stock_movement(
                    $pdo,
                    (int)$item['dish_id'],
                    $userId,
                    'restock',
                    (int)$item['quantity'],
                    "Restocked due to Order #{$orderNumber} customer cancellation"
                );
            }

            $histStmt = $pdo->prepare("
                INSERT INTO order_status_history (order_id, changed_by_user_id, old_status, new_status, comment, created_at)
                VALUES (:oid, :uid, 'Placed', 'Cancelled', 'Order cancelled by customer', NOW())
            ");
            $histStmt->execute([':oid' => $order['id'], ':uid' => $userId]);

            $payUpd = $pdo->prepare("
                UPDATE payments
                SET payment_status = CASE WHEN payment_status = 'completed' THEN 'refunded' ELSE payment_status END
                WHERE order_id = :oid
            ");
            $payUpd->execute([':oid' => $order['id']]);

            log_action($pdo, 'ORDER_CANCELLED', "Order {$orderNumber} cancelled by customer", $userId);

            $pdo->commit();
            set_flash('info', "Order #{$orderNumber} has been successfully cancelled and stock restored.");
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            set_flash('error', 'Cancellation failed: ' . $e->getMessage());
        }
    }

    header("Location: {$appBase}/orders/tracking.php?order_number=" . urlencode($orderNumber));
    exit;
}

// 3. Fetch Status Workflow History Trail
$histStmt = $pdo->prepare("
    SELECT osh.*, u.full_name AS staff_name, r.name AS role_name
    FROM order_status_history osh
    LEFT JOIN users u ON osh.changed_by_user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE osh.order_id = :oid
    ORDER BY osh.created_at ASC
");
$histStmt->execute([':oid' => $order['id']]);
$statusHistory = $histStmt->fetchAll();

// 4. Determine Stepper Nodes
$isDelivery = ($order['order_type'] === 'delivery');
$flowSteps = $isDelivery 
    ? ['Placed', 'Preparing', 'Ready', 'Out for Delivery', 'Completed']
    : ['Placed', 'Preparing', 'Ready', 'Collected', 'Completed'];

$currentStatus = $order['status'];
$currentIndex = array_search($currentStatus, $flowSteps);
if ($currentIndex === false && $currentStatus === 'Cancelled') {
    $isCancelled = true;
} else {
    $isCancelled = false;
}
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Live Kitchen Tracker</span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Order Status: <?= e($order['order_number']) ?></h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/orders/confirmation.php?order_number=<?= urlencode($order['order_number']) ?>" class="btn btn-outline">
                🧾 View Invoice
            </a>
            <a href="<?= $appBase ?>/orders/history.php" class="btn btn-secondary">
                &larr; Order History
            </a>
        </div>
    </div>

    <!-- Live Stepper Card -->
    <div class="order-tracking-card">
        <div class="tracking-header">
            <div>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Estimated Delivery/Pickup Status</span>
                <div style="display: flex; align-items: center; gap: 0.75rem; margin-top: 0.25rem;">
                    <h2 style="font-size: 1.5rem; margin: 0; color: var(--color-secondary);">
                        <?= $isCancelled ? '❌ Order Cancelled' : 'Current Stage: ' . e($currentStatus) ?>
                    </h2>
                </div>
            </div>

            <div style="text-align: right;">
                <span style="font-size: 0.85rem; color: var(--text-muted);">Total Amount</span>
                <div style="font-family: var(--font-heading); font-size: 1.35rem; font-weight: 800; color: var(--color-primary);">
                    <?= format_price($order['total_amount']) ?>
                </div>
            </div>
        </div>

        <?php if ($isCancelled): ?>
            <div class="alert alert-danger" style="margin: 2rem 0; padding: 1.5rem;">
                <h3 style="font-size: 1.15rem; margin-bottom: 0.25rem;">This order was cancelled</h3>
                <p style="font-size: 0.875rem; margin: 0;">Any simulated payment has been credited or refunded, and stock has been returned to the kitchen.</p>
            </div>
        <?php else: ?>
            <!-- Visual Timeline Stepper -->
            <div class="timeline-stepper">
                <?php foreach ($flowSteps as $idx => $stepName): ?>
                    <?php
                    $isCompleted = ($currentIndex !== false && $idx < $currentIndex);
                    $isActive = ($currentIndex !== false && $idx === $currentIndex);
                    $stepClass = $isCompleted ? 'completed' : ($isActive ? 'active' : '');
                    ?>
                    <div class="timeline-step <?= $stepClass ?>">
                        <div class="step-node">
                            <?php if ($isCompleted): ?>
                                ✓
                            <?php else: ?>
                                <?= $idx + 1 ?>
                            <?php endif; ?>
                        </div>
                        <span class="step-label"><?= e($stepName) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Customer Cancellation Option (Only available if status is Placed) -->
        <?php if ($currentStatus === 'Placed'): ?>
            <div style="background: var(--bg-subtle); border-radius: var(--radius-md); padding: 1.25rem; margin-top: 2rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <strong>Need to change your mind?</strong>
                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 0;">You can cancel this order with a single click before our kitchen starts cooking.</p>
                </div>
                <form action="<?= $appBase ?>/orders/tracking.php?order_number=<?= urlencode($orderNumber) ?>" method="POST" style="margin: 0;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_order">
                    <button type="submit" class="btn btn-outline text-danger" onclick="return confirm('Are you sure you want to cancel Order #<?= e($orderNumber) ?>?');">
                        Cancel This Order
                    </button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Status Progression History Log -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; box-shadow: var(--shadow-sm);">
        <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: var(--color-secondary);">Status Progression Timeline</h2>
        
        <div style="display: flex; flex-direction: column; gap: 1rem;">
            <?php foreach ($statusHistory as $hist): ?>
                <div style="display: flex; gap: 1.25rem; align-items: flex-start; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
                    <div style="width: 32px; height: 32px; border-radius: 50%; background: var(--color-primary-light); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.8rem; flex-shrink: 0;">
                        ⏱️
                    </div>
                    <div style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                            <strong style="font-size: 0.95rem; color: var(--color-secondary);">
                                <?= e($hist['new_status']) ?>
                                <?php if (!empty($hist['old_status'])): ?>
                                    <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 400;">(from <?= e($hist['old_status']) ?>)</span>
                                <?php endif; ?>
                            </strong>
                            <span style="font-size: 0.8rem; color: var(--text-muted);">
                                <?= format_datetime($hist['created_at']) ?>
                            </span>
                        </div>
                        <p style="font-size: 0.85rem; color: var(--color-secondary-surface); margin-bottom: 0.25rem;">
                            <?= e($hist['comment']) ?>
                        </p>
                        <?php if (!empty($hist['staff_name'])): ?>
                            <span style="font-size: 0.75rem; color: var(--text-subtle);">
                                Processed by: <?= e($hist['staff_name']) ?> (<?= ucfirst(str_replace('_', ' ', $hist['role_name'] ?? 'Staff')) ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
