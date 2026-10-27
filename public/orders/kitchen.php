<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Kitchen Order Queue & Workflow Engine
 *
 * Implements Kanban-style order ticket queue, transition validation
 * (Placed -> Preparing -> Ready -> Out for Delivery/Collected -> Completed),
 * state machine integrity, and audit tracking.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentStaffId = (int)$_SESSION['user_id'];

// 1. Handle Status Transition Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $orderId = (int)($_POST['order_id'] ?? 0);
    $newStatus = trim($_POST['new_status'] ?? '');
    $staffComment = trim($_POST['comment'] ?? '');

    // Fetch current order status
    $stmt = $pdo->prepare("SELECT id, order_number, status, order_type FROM orders WHERE id = :id");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        set_flash('error', 'Order ticket not found.');
    } else {
        $currentStatus = $order['status'];

        // Enforce State Machine Validation
        if (!is_valid_status_transition($currentStatus, $newStatus)) {
            set_flash('error', "Invalid workflow transition from '{$currentStatus}' to '{$newStatus}'.");
        } else {
            $pdo->beginTransaction();
            try {
                // Update Order Status
                $upd = $pdo->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id");
                $upd->execute([':status' => $newStatus, ':id' => $orderId]);

                // Default comment if empty
                if (empty($staffComment)) {
                    $staffComment = "Status updated to {$newStatus} by kitchen staff";
                }

                // Insert into order_status_history
                $hStmt = $pdo->prepare("
                    INSERT INTO order_status_history (order_id, changed_by_user_id, old_status, new_status, comment, created_at)
                    VALUES (:oid, :uid, :old_status, :new_status, :comment, NOW())
                ");
                $hStmt->execute([
                    ':oid'        => $orderId,
                    ':uid'        => $currentStaffId,
                    ':old_status' => $currentStatus,
                    ':new_status' => $newStatus,
                    ':comment'    => $staffComment
                ]);

                // Audit log
                log_action(
                    $pdo,
                    'ORDER_STATUS_UPDATE',
                    "Order {$order['order_number']} advanced from {$currentStatus} to {$newStatus}",
                    $currentStaffId
                );

                $pdo->commit();
                set_flash('success', "Order #{$order['order_number']} successfully advanced to '{$newStatus}'!");
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('error', 'Failed to update order status: ' . $e->getMessage());
            }
        }
    }

    header("Location: {$appBase}/orders/kitchen.php?" . http_build_query($_GET));
    exit;
}

$currentPage = 'kitchen';
$pageTitle = 'Kitchen Order Queue - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// 2. Filter Tab Selection
$filterTab = trim($_GET['tab'] ?? 'active');

$whereSql = match ($filterTab) {
    'placed'    => "o.status = 'Placed'",
    'preparing' => "o.status = 'Preparing'",
    'ready'     => "o.status IN ('Ready', 'Out for Delivery', 'Collected')",
    'history'   => "o.status IN ('Completed', 'Cancelled')",
    default     => "o.status IN ('Placed', 'Preparing', 'Ready', 'Out for Delivery', 'Collected')"
};

// 3. Fetch Orders matching filter
$ordersQuery = "
    SELECT o.*, u.full_name AS customer_name, u.phone AS customer_phone,
           p.payment_method, p.payment_status
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN payments p ON o.id = p.order_id
    WHERE $whereSql
    ORDER BY CASE o.status
        WHEN 'Placed' THEN 1
        WHEN 'Preparing' THEN 2
        WHEN 'Ready' THEN 3
        WHEN 'Out for Delivery' THEN 4
        WHEN 'Collected' THEN 5
        ELSE 6
    END ASC, o.created_at ASC
";
$orders = $pdo->query($ordersQuery)->fetchAll();

// 4. Fetch Order Items for All Listed Orders
$orderIds = array_column($orders, 'id');
$itemsByOrder = [];
if (!empty($orderIds)) {
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $iStmt = $pdo->prepare("
        SELECT oi.*, d.image_url
        FROM order_items oi
        LEFT JOIN dishes d ON oi.dish_id = d.id
        WHERE oi.order_id IN ($placeholders)
        ORDER BY oi.id ASC
    ");
    $iStmt->execute($orderIds);
    $rawItems = $iStmt->fetchAll();
    foreach ($rawItems as $it) {
        $itemsByOrder[$it['order_id']][] = $it;
    }
}
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Header -->
    <div class="kitchen-queue-header">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component C: Kitchen Workflow</span>
            <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">Live Kitchen Order Queue</h1>
        </div>

        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-secondary">📦 Stock Dashboard</a>
            <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-outline">🍲 Dishes</a>
        </div>
    </div>

    <!-- Queue Filter Tabs -->
    <div style="display: flex; gap: 0.5rem; margin-bottom: 2rem; overflow-x: auto; padding-bottom: 0.5rem;">
        <a href="?tab=active" class="queue-tab <?= $filterTab === 'active' ? 'active' : '' ?>">
            🔥 Active Tickets (All Live)
        </a>
        <a href="?tab=placed" class="queue-tab <?= $filterTab === 'placed' ? 'active' : '' ?>">
            📥 Placed (Incoming)
        </a>
        <a href="?tab=preparing" class="queue-tab <?= $filterTab === 'preparing' ? 'active' : '' ?>">
            🍳 Preparing (Cooking)
        </a>
        <a href="?tab=ready" class="queue-tab <?= $filterTab === 'ready' ? 'active' : '' ?>">
            📦 Ready / Dispatched
        </a>
        <a href="?tab=history" class="queue-tab <?= $filterTab === 'history' ? 'active' : '' ?>">
            ✅ Completed & Cancelled History
        </a>
    </div>

    <!-- Orders Queue Grid -->
    <?php if (empty($orders)): ?>
        <div style="background: white; border: 1px dashed var(--border-color); border-radius: var(--radius-lg); padding: 4rem 2rem; text-align: center;">
            <span style="font-size: 3.5rem; display: block; margin-bottom: 0.5rem;">🎉</span>
            <h2 style="font-size: 1.35rem; margin-bottom: 0.5rem;">All Clear! No Orders In This Stage</h2>
            <p style="color: var(--text-muted); font-size: 0.9rem;">
                There are currently no tickets matching the selected kitchen filter.
            </p>
        </div>
    <?php else: ?>
        <div class="kitchen-orders-grid">
            <?php foreach ($orders as $ord): ?>
                <?php
                $ordId = $ord['id'];
                $items = $itemsByOrder[$ordId] ?? [];
                $status = $ord['status'];
                $isDelivery = ($ord['order_type'] === 'delivery');
                ?>
                <article class="order-ticket" style="border-top-color: <?= match($status) {
                    'Placed' => 'var(--color-primary)',
                    'Preparing' => 'var(--color-accent)',
                    'Ready' => 'var(--color-success)',
                    'Out for Delivery' => '#0284c7',
                    'Collected' => '#0284c7',
                    'Completed' => '#10b981',
                    default => '#94a3b8'
                } ?>;">
                    <div class="ticket-header">
                        <div>
                            <span class="ticket-id"><?= e($ord['order_number']) ?></span>
                            <span style="display: block; font-size: 0.75rem; color: var(--text-muted);">
                                <?= format_datetime($ord['created_at'], 'g:i A (M d)') ?>
                            </span>
                        </div>
                        <span class="badge" style="background: var(--bg-subtle); color: var(--color-secondary); font-weight: 700;">
                            <?= e($status) ?>
                        </span>
                    </div>

                    <!-- Customer & Fulfillment summary -->
                    <div style="font-size: 0.85rem; margin-bottom: 0.5rem;">
                        <strong><?= e($ord['customer_name']) ?></strong> (<?= e($ord['customer_phone']) ?>)<br>
                        <span style="color: var(--color-primary); font-weight: 700;">
                            <?= $isDelivery ? '🚀 Delivery' : '🥡 Kitchen Pickup' ?>
                        </span>
                        <?php if ($isDelivery && !empty($ord['delivery_address'])): ?>
                            <div style="font-size: 0.775rem; color: var(--text-muted); margin-top: 0.2rem;">
                                📍 <?= e($ord['delivery_address']) ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($ord['notes'])): ?>
                        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-sm); padding: 0.4rem 0.6rem; font-size: 0.8rem; color: #92400e; margin-bottom: 0.5rem;">
                            <strong>Note:</strong> <?= e($ord['notes']) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Ordered Dishes Ticket -->
                    <ul class="ticket-items">
                        <?php foreach ($items as $it): ?>
                            <li class="ticket-item">
                                <span><strong><?= $it['quantity'] ?>x</strong> <?= e($it['dish_name']) ?></span>
                                <span style="color: var(--text-muted); font-size: 0.8rem;"><?= format_price($it['subtotal']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <div style="display: flex; justify-content: space-between; align-items: center; font-size: 0.9rem; margin-bottom: 1rem;">
                        <span style="color: var(--text-muted);">Total Value:</span>
                        <strong><?= format_price($ord['total_amount']) ?></strong>
                    </div>

                    <!-- Action Transition Controls -->
                    <div class="ticket-actions">
                        <?php if ($status === 'Placed'): ?>
                            <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="flex: 1; margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                <input type="hidden" name="new_status" value="Preparing">
                                <button type="submit" class="btn btn-primary btn-block btn-sm">
                                    Start Preparing 🍳
                                </button>
                            </form>
                            <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                <input type="hidden" name="new_status" value="Cancelled">
                                <input type="hidden" name="comment" value="Cancelled by kitchen management">
                                <button type="submit" class="btn btn-outline-sm text-danger" onclick="return confirm('Cancel Order #<?= e($ord['order_number']) ?>?');">
                                    Reject
                                </button>
                            </form>

                        <?php elseif ($status === 'Preparing'): ?>
                            <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="flex: 1; margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                <input type="hidden" name="new_status" value="Ready">
                                <button type="submit" class="btn btn-primary btn-block btn-sm" style="background: var(--color-success);">
                                    Mark Ready for Dispatch ✅
                                </button>
                            </form>

                        <?php elseif ($status === 'Ready'): ?>
                            <?php if ($isDelivery): ?>
                                <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="flex: 1; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                    <input type="hidden" name="new_status" value="Out for Delivery">
                                    <button type="submit" class="btn btn-primary btn-block btn-sm">
                                        Hand to Driver (Out for Delivery) 🛵
                                    </button>
                                </form>
                            <?php else: ?>
                                <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="flex: 1; margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                    <input type="hidden" name="new_status" value="Collected">
                                    <button type="submit" class="btn btn-primary btn-block btn-sm">
                                        Handed to Customer (Collected) 🥡
                                    </button>
                                </form>
                            <?php endif; ?>

                        <?php elseif (in_array($status, ['Out for Delivery', 'Collected'], true)): ?>
                            <form action="<?= $appBase ?>/orders/kitchen.php?<?= http_build_query($_GET) ?>" method="POST" style="flex: 1; margin: 0;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="order_id" value="<?= $ordId ?>">
                                <input type="hidden" name="new_status" value="Completed">
                                <button type="submit" class="btn btn-primary btn-block btn-sm" style="background: #10b981;">
                                    Mark Completed 🎉
                                </button>
                            </form>

                        <?php else: ?>
                            <a href="<?= $appBase ?>/orders/confirmation.php?order_number=<?= urlencode($ord['order_number']) ?>" class="btn btn-outline btn-block btn-sm">
                                View Order Archive Receipt
                            </a>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
