<?php

$currentPage = 'admin';
$pageTitle = 'Admin Dashboard - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

require_role('admin');

$pdo = get_db_connection();

// 1. Metric: Total Revenue (LKR) from completed orders
$revStmt = $pdo->query("SELECT COALESCE(SUM(total_amount), 0) FROM orders WHERE status = 'Completed'");
$totalRevenue = (float)$revStmt->fetchColumn();

// 2. Metric: Total Orders count
$ordersStmt = $pdo->query("SELECT COUNT(*) FROM orders");
$totalOrders = (int)$ordersStmt->fetchColumn();

// 3. Metric: Active Users count
$userStmt = $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1");
$activeUsers = (int)$userStmt->fetchColumn();

// 4. Metric: Low / Out of Stock alerts
$stockStmt = $pdo->query("SELECT COUNT(*) FROM inventory WHERE current_stock <= reorder_level");
$lowStockCount = (int)$stockStmt->fetchColumn();

// 5. Recent 6 Orders
$recentOrdersStmt = $pdo->query("
    SELECT o.*, u.full_name AS customer_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    ORDER BY o.created_at DESC
    LIMIT 6
");
$recentOrders = $recentOrdersStmt->fetchAll();

// 6. Recent 6 Audit Log Entries
$recentAuditStmt = $pdo->query("
    SELECT a.*, u.full_name, u.email, r.name AS role_name
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY a.created_at DESC
    LIMIT 6
");
$recentAudits = $recentAuditStmt->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Dashboard Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Executive Administration</span>
            <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">Cloud Kitchen Control Center</h1>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?= $appBase ?>/admin/reports.php" class="btn btn-primary">📈 Detailed Sales Reports</a>
            <a href="<?= $appBase ?>/admin/users.php" class="btn btn-secondary">👥 Manage Users</a>
            <a href="<?= $appBase ?>/orders/kitchen.php" class="btn btn-outline">🍳 Kitchen Queue</a>
        </div>
    </div>

    <!-- KPI Metric Cards Grid -->
    <div class="stats-cards-grid">
        <div class="metric-card">
            <div class="metric-icon">💰</div>
            <div>
                <div class="metric-val"><?= format_price($totalRevenue) ?></div>
                <div class="metric-label">Completed Sales Revenue</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: #f0fdf4; color: #16a34a;">📦</div>
            <div>
                <div class="metric-val"><?= $totalOrders ?></div>
                <div class="metric-label">Total Lifetime Orders</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: #eff6ff; color: #2563eb;">👤</div>
            <div>
                <div class="metric-val"><?= $activeUsers ?></div>
                <div class="metric-label">Active Registered Accounts</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: <?= $lowStockCount > 0 ? '#fef2f2' : '#f0fdf4' ?>; color: <?= $lowStockCount > 0 ? '#dc2626' : '#16a34a' ?>;">⚠️</div>
            <div>
                <div class="metric-val"><?= $lowStockCount ?></div>
                <div class="metric-label">Low/Out of Stock Alerts</div>
            </div>
        </div>
    </div>

    <!-- Quick Operations Navigation -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2.5rem;">
        <a href="<?= $appBase ?>/admin/users.php" class="btn btn-outline" style="justify-content: flex-start; padding: 1rem;">
            <span>👥 User Accounts & Roles</span>
        </a>
        <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline" style="justify-content: flex-start; padding: 1rem;">
            <span>📦 Stock & Restock Dashboard</span>
        </a>
        <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-outline" style="justify-content: flex-start; padding: 1rem;">
            <span>🍲 Dish Catalogue Management</span>
        </a>
        <a href="<?= $appBase ?>/menu/categories.php" class="btn btn-outline" style="justify-content: flex-start; padding: 1rem;">
            <span>📂 Category Hierarchy</span>
        </a>
        <a href="<?= $appBase ?>/admin/audit_log.php" class="btn btn-outline" style="justify-content: flex-start; padding: 1rem;">
            <span>📋 System Audit Trail</span>
        </a>
    </div>

    <!-- Split View: Recent Orders & Recent Audit Activity -->
    <div style="display: grid; grid-template-columns: 1.2fr 0.8fr; gap: 2rem; align-items: start;">
        <!-- Left: Live Orders -->
        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h2 style="font-size: 1.25rem;">Recent Order Activity</h2>
                <a href="<?= $appBase ?>/orders/kitchen.php" style="font-size: 0.85rem; font-weight: 700;">View All Orders &rarr;</a>
            </div>

            <div class="data-table-container" style="margin-bottom: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Order No</th>
                            <th>Customer</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td>
                                    <a href="<?= $appBase ?>/orders/tracking.php?order_number=<?= urlencode($order['order_number']) ?>" style="font-weight: 700;">
                                        <?= e($order['order_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><strong><?= format_price($order['total_amount']) ?></strong></td>
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
                                    $bClass = $statusBadges[$order['status']] ?? 'badge-active';
                                    ?>
                                    <span class="badge <?= $bClass ?>"><?= e($order['status']) ?></span>
                                </td>
                                <td style="font-size: 0.775rem; color: var(--text-muted);"><?= format_datetime($order['created_at'], 'M d, g:i A') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Right: Recent Audit Log Events -->
        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem;">
                <h2 style="font-size: 1.25rem;">System Audit Trail</h2>
                <a href="<?= $appBase ?>/admin/audit_log.php" style="font-size: 0.85rem; font-weight: 700;">Full Log &rarr;</a>
            </div>

            <div style="display: flex; flex-direction: column; gap: 0.85rem;">
                <?php foreach ($recentAudits as $log): ?>
                    <div style="padding: 0.75rem; background: var(--bg-page); border-radius: var(--radius-md); border-left: 3px solid var(--color-primary); font-size: 0.85rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.25rem;">
                            <strong style="color: var(--color-secondary); font-size: 0.8rem; text-transform: uppercase;"><?= e($log['action']) ?></strong>
                            <span style="font-size: 0.7rem; color: var(--text-muted);"><?= format_datetime($log['created_at'], 'g:i A') ?></span>
                        </div>
                        <p style="color: var(--text-muted); margin-bottom: 0.25rem; font-size: 0.8rem;"><?= e($log['details']) ?></p>
                        <div style="font-size: 0.725rem; color: var(--text-subtle);">
                            By: <?= e($log['full_name'] ?? 'System') ?> (<?= e($log['role_name'] ?? 'Guest/Cron') ?>) • IP: <?= e($log['ip_address'] ?? '127.0.0.1') ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
