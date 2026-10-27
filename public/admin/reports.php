<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Administrator Sales & Revenue Reports
 *
 * Implements date-range filtered reporting using advanced SQL queries
 * with JOIN, GROUP BY, and aggregate functions (SUM, COUNT, AVG).
 */

$currentPage = 'reports';
$pageTitle = 'Sales Reports & Analytics - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/functions.php';

$appBase = get_app_base_url();
require_role('admin');

$pdo = get_db_connection();

// 1. Date Range Filtering (Default to past 30 days)
$defaultStartDate = date('Y-m-01'); // Start of current month
$defaultEndDate = date('Y-m-d');

$rawStartDate = $_GET['start_date'] ?? $defaultStartDate;
$rawEndDate = $_GET['end_date'] ?? $defaultEndDate;
$startDate = is_string($rawStartDate) ? trim($rawStartDate) : '';
$endDate = is_string($rawEndDate) ? trim($rawEndDate) : '';
$isValidDate = static function (string $date): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    [$year, $month, $day] = array_map('intval', explode('-', $date));
    return $year >= 1000 && checkdate($month, $day, $year);
};

if (!$isValidDate($startDate) || !$isValidDate($endDate) || $startDate > $endDate) {
    http_response_code(400);
    render_error_page(
        400,
        'Invalid Report Date Range',
        'Choose valid start and end dates, with the start date on or before the end date.',
        "{$appBase}/admin/reports.php"
    );
    exit;
}

$filterStart = $startDate . ' 00:00:00';
$filterEnd = $endDate . ' 23:59:59';

// 2. High-Level Aggregates for Completed Orders
$aggStmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COUNT(id) AS completed_orders_count,
        COALESCE(AVG(total_amount), 0) AS average_order_value
    FROM orders
    WHERE status = 'Completed'
      AND created_at BETWEEN :start_date AND :end_date
");
$aggStmt->execute([':start_date' => $filterStart, ':end_date' => $filterEnd]);
$summary = $aggStmt->fetch();

$totalRevenue = (float)$summary['total_revenue'];
$completedCount = (int)$summary['completed_orders_count'];
$averageOrderValue = (float)$summary['average_order_value'];

// 3. Total Orders (Any Status) & Cancelled Count in Period
$totalOrdersStmt = $pdo->prepare("
    SELECT 
        COUNT(id) AS total_count,
        SUM(CASE WHEN status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN status = 'Cancelled' THEN total_amount ELSE 0 END) AS cancelled_amount
    FROM orders
    WHERE created_at BETWEEN :start_date AND :end_date
");
$totalOrdersStmt->execute([':start_date' => $filterStart, ':end_date' => $filterEnd]);
$totalStats = $totalOrdersStmt->fetch();

// 4. Orders Per Status Breakdown (JOIN, GROUP BY, Aggregates)
$statusStmt = $pdo->prepare("
    SELECT 
        status,
        COUNT(id) AS order_count,
        COALESCE(SUM(total_amount), 0) AS status_revenue
    FROM orders
    WHERE created_at BETWEEN :start_date AND :end_date
    GROUP BY status
    ORDER BY order_count DESC
");
$statusStmt->execute([':start_date' => $filterStart, ':end_date' => $filterEnd]);
$statusBreakdown = $statusStmt->fetchAll();

// 5. Top-Selling Dishes Query (JOIN order_items, dishes, orders, GROUP BY, SUM aggregates)
$topDishesStmt = $pdo->prepare("
    SELECT 
        oi.dish_id,
        oi.dish_name,
        c.name AS category_name,
        d.price AS current_price,
        SUM(oi.quantity) AS total_quantity_sold,
        SUM(oi.subtotal) AS total_dish_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    JOIN dishes d ON oi.dish_id = d.id
    JOIN categories c ON d.category_id = c.id
    WHERE o.status = 'Completed'
      AND o.created_at BETWEEN :start_date AND :end_date
    GROUP BY oi.dish_id, oi.dish_name, c.name, d.price
    ORDER BY total_dish_revenue DESC
    LIMIT 10
");
$topDishesStmt->execute([':start_date' => $filterStart, ':end_date' => $filterEnd]);
$topDishes = $topDishesStmt->fetchAll();

// 6. Daily Sales Trend Query (GROUP BY DATE(created_at))
$dailyStmt = $pdo->prepare("
    SELECT 
        DATE(created_at) AS order_date,
        COUNT(id) AS daily_order_count,
        SUM(total_amount) AS daily_revenue
    FROM orders
    WHERE status = 'Completed'
      AND created_at BETWEEN :start_date AND :end_date
    GROUP BY DATE(created_at)
    ORDER BY order_date ASC
");
$dailyStmt->execute([':start_date' => $filterStart, ':end_date' => $filterEnd]);
$dailyTrend = $dailyStmt->fetchAll();
?>

<?php require_once __DIR__ . '/../../includes/header.php'; ?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component C: Financial Reporting</span>
            <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">Sales Analytics & Performance</h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/admin/dashboard.php" class="btn btn-outline">&larr; Admin Dashboard</a>
            <button type="button" class="btn btn-secondary" onclick="window.print()">🖨️ Print Report</button>
        </div>
    </div>

    <!-- Date Range Filter Form -->
    <div class="menu-controls" style="margin-bottom: 2rem;">
        <form action="<?= $appBase ?>/admin/reports.php" method="GET" class="filter-form">
            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <div>
                    <label for="start_date" style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">From Date:</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?= e($startDate) ?>" required>
                </div>

                <div>
                    <label for="end_date" style="font-size: 0.8rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 0.25rem;">To Date:</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?= e($endDate) ?>" required>
                </div>

                <div style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">Generate Report</button>
                </div>

                <div style="align-self: flex-end;">
                    <a href="<?= $appBase ?>/admin/reports.php" class="btn btn-outline">Current Month</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Executive Metric Cards -->
    <div class="stats-cards-grid" style="margin-bottom: 2.5rem;">
        <div class="metric-card">
            <div class="metric-icon">💰</div>
            <div>
                <div class="metric-val"><?= format_price($totalRevenue) ?></div>
                <div class="metric-label">Completed Sales Revenue</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: #ecfdf5; color: #16a34a;">📦</div>
            <div>
                <div class="metric-val"><?= $completedCount ?></div>
                <div class="metric-label">Completed Delivery & Pickups</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: #f0f9ff; color: #0284c7;">📊</div>
            <div>
                <div class="metric-val"><?= format_price($averageOrderValue) ?></div>
                <div class="metric-label">Average Order Value (AOV)</div>
            </div>
        </div>

        <div class="metric-card">
            <div class="metric-icon" style="background: #fef2f2; color: #dc2626;">❌</div>
            <div>
                <div class="metric-val"><?= (int)$totalStats['cancelled_count'] ?></div>
                <div class="metric-label">Cancelled Orders in Period</div>
            </div>
        </div>
    </div>

    <!-- Split Section: Top-Selling Dishes & Orders per Status -->
    <div style="display: grid; grid-template-columns: 1.3fr 0.7fr; gap: 2rem; align-items: start; margin-bottom: 2.5rem;">
        <!-- Left: Top-Selling Dishes (JOIN, GROUP BY, SUM) -->
        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.75rem; box-shadow: var(--shadow-sm);">
            <div style="margin-bottom: 1.25rem;">
                <h2 style="font-size: 1.25rem;">🏆 Top-Selling Kitchen Dishes</h2>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Aggregated from completed orders in selected date range</span>
            </div>

            <?php if (empty($topDishes)): ?>
                <p style="text-align: center; color: var(--text-muted); padding: 2rem;">No completed sales found in this date range.</p>
            <?php else: ?>
                <div class="data-table-container" style="margin-bottom: 0;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Dish</th>
                                <th>Category</th>
                                <th style="text-align: center;">Qty Sold</th>
                                <th style="text-align: right;">Total Sales (LKR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topDishes as $td): ?>
                                <tr>
                                    <td><strong><?= e($td['dish_name']) ?></strong></td>
                                    <td><span style="font-size: 0.8rem; color: var(--text-muted);"><?= e($td['category_name']) ?></span></td>
                                    <td style="text-align: center;"><strong><?= $td['total_quantity_sold'] ?></strong></td>
                                    <td style="text-align: right; color: var(--color-primary); font-weight: 800;">
                                        <?= format_price($td['total_dish_revenue']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Right: Status Breakdown -->
        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.75rem; box-shadow: var(--shadow-sm);">
            <div style="margin-bottom: 1.25rem;">
                <h2 style="font-size: 1.25rem;">📊 Orders by Status</h2>
                <span style="font-size: 0.8rem; color: var(--text-muted);">Volume and distribution across stages</span>
            </div>

            <div class="data-table-container" style="margin-bottom: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th style="text-align: center;">Orders</th>
                            <th style="text-align: right;">Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusBreakdown as $sb): ?>
                            <tr>
                                <td>
                                    <?php
                                    $bClass = match($sb['status']) {
                                        'Completed' => 'badge-active',
                                        'Cancelled' => 'badge-inactive',
                                        'Preparing' => 'badge-role-staff',
                                        default => 'badge-role-customer'
                                    };
                                    ?>
                                    <span class="badge <?= $bClass ?>"><?= e($sb['status']) ?></span>
                                </td>
                                <td style="text-align: center;"><strong><?= $sb['order_count'] ?></strong></td>
                                <td style="text-align: right; font-size: 0.85rem;"><?= format_price($sb['status_revenue']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Daily Revenue Timeline -->
    <?php if (!empty($dailyTrend)): ?>
        <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.75rem; box-shadow: var(--shadow-sm);">
            <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem;">📅 Daily Completed Revenue Summary</h2>
            <div class="data-table-container" style="margin-bottom: 0;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th style="text-align: center;">Completed Orders</th>
                            <th style="text-align: right;">Total Daily Revenue (LKR)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dailyTrend as $dt): ?>
                            <tr>
                                <td><strong><?= format_datetime($dt['order_date'], 'l, F j, Y') ?></strong></td>
                                <td style="text-align: center;"><?= $dt['daily_order_count'] ?> orders</td>
                                <td style="text-align: right; font-weight: 700; color: var(--color-primary);">
                                    <?= format_price($dt['daily_revenue']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
