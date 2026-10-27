<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Customer Order History
 *
 * Displays personal order history, status progressions,
 * and direct links to live tracking timelines and invoices.
 */

$currentPage = 'orders';
$pageTitle = 'My Order History - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/functions.php';

$appBase = get_app_base_url();
require_login();

$pdo = get_db_connection();
$userId = (int)$_SESSION['user_id'];

// Pagination parameters
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 8;
$offset = ($page - 1) * $perPage;

// Total Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE user_id = :uid");
$countStmt->execute([':uid' => $userId]);
$totalOrders = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalOrders / $perPage));

// Query Orders with Items Count and Payment Status
$ordersStmt = $pdo->prepare("
    SELECT o.*, p.payment_method, p.payment_status,
           COUNT(oi.id) AS item_count,
           GROUP_CONCAT(CONCAT(oi.quantity, 'x ', oi.dish_name) SEPARATOR ', ') AS items_summary
    FROM orders o
    LEFT JOIN payments p ON o.id = p.order_id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    WHERE o.user_id = :uid
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT :limit OFFSET :offset
");
$ordersStmt->bindValue(':uid', $userId, PDO::PARAM_INT);
$ordersStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$ordersStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$ordersStmt->execute();
$orders = $ordersStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Account Activity</span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">My Order History</h1>
        </div>
        <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary">+ Place New Order</a>
    </div>

    <?php if (empty($orders)): ?>
        <div style="background: white; border: 1px dashed var(--border-color); border-radius: var(--radius-lg); padding: 4rem 2rem; text-align: center;">
            <span style="font-size: 3rem; display: block; margin-bottom: 0.5rem;">📦</span>
            <h2 style="font-size: 1.35rem; margin-bottom: 0.5rem;">You haven't placed any orders yet</h2>
            <p style="color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.9rem;">
                Browse our cloud kitchen menu to order authentic Sri Lankan kottu, lamprais, and biryani!
            </p>
            <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary">Browse Full Menu 🍛</a>
        </div>
    <?php else: ?>
        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order Number</th>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Dishes Ordered</th>
                        <th>Total Paid</th>
                        <th>Payment</th>
                        <th>Kitchen Status</th>
                        <th style="text-align: right;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>
                                <strong>
                                    <a href="<?= $appBase ?>/orders/tracking.php?order_number=<?= urlencode($o['order_number']) ?>">
                                        <?= e($o['order_number']) ?>
                                    </a>
                                </strong>
                            </td>
                            <td style="font-size: 0.8rem; color: var(--text-muted); white-space: nowrap;">
                                <?= format_datetime($o['created_at']) ?>
                            </td>
                            <td>
                                <span style="text-transform: capitalize; font-weight: 600; font-size: 0.85rem;">
                                    <?= e($o['order_type']) === 'delivery' ? '🚀 Delivery' : '🥡 Pickup' ?>
                                </span>
                            </td>
                            <td style="max-width: 280px; font-size: 0.85rem; color: var(--color-secondary);">
                                <?= e($o['items_summary']) ?>
                            </td>
                            <td>
                                <strong style="font-size: 0.95rem;"><?= format_price($o['total_amount']) ?></strong>
                            </td>
                            <td>
                                <?php if ($o['payment_status'] === 'completed'): ?>
                                    <span class="badge badge-active">Paid</span>
                                <?php elseif ($o['payment_status'] === 'pending'): ?>
                                    <span class="badge" style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a;">COD Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><?= ucfirst($o['payment_status'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $statusBadges = [
                                    'Placed' => 'badge-role-customer',
                                    'Preparing' => 'badge-role-staff',
                                    'Ready' => 'badge-active',
                                    'Out for Delivery' => 'badge-role-customer',
                                    'Collected' => 'badge-active',
                                    'Completed' => 'badge-active',
                                    'Cancelled' => 'badge-inactive'
                                ];
                                $bClass = $statusBadges[$o['status']] ?? 'badge-active';
                                ?>
                                <span class="badge <?= $bClass ?>"><?= e($o['status']) ?></span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 0.5rem;">
                                    <a href="<?= $appBase ?>/orders/tracking.php?order_number=<?= urlencode($o['order_number']) ?>" class="btn btn-outline-sm">
                                        Track
                                    </a>
                                    <a href="<?= $appBase ?>/orders/confirmation.php?order_number=<?= urlencode($o['order_number']) ?>" class="btn btn-outline-sm" title="Invoice Receipt">
                                        Invoice
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?page=<?= max(1, $page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&larr; Prev</a>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?= $i ?>" class="page-btn <?= $page === $i ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($totalPages, $page + 1) ?>" class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next &rarr;</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
