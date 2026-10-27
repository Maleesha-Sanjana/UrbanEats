<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: System Audit Trail & Event Logging
 *
 * Provides immutable security auditing of all critical actions performed
 * across Components A (Auth/Admin), B (Menu/Stock), and C (Orders/Checkout).
 */

$currentPage = 'admin';
$pageTitle = 'System Audit Trail - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

require_role('admin');

$pdo = get_db_connection();

$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$whereClauses = ['1=1'];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(a.details LIKE :search OR u.full_name LIKE :search OR u.email LIKE :search OR a.ip_address LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($actionFilter)) {
    $whereClauses[] = "a.action = :action";
    $params[':action'] = $actionFilter;
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch Distinct Actions for Filter Dropdown
$actionTypes = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

// Total Records
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE $whereSql
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Fetch Records
$logStmt = $pdo->prepare("
    SELECT a.*, u.full_name, u.email, r.name AS role_name
    FROM audit_log a
    LEFT JOIN users u ON a.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    WHERE $whereSql
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $k => $v) {
    $logStmt->bindValue($k, $v);
}
$logStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$logStmt->execute();
$logs = $logStmt->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Security & Compliance</span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Centralized System Audit Trail</h1>
        </div>
        <a href="<?= $appBase ?>/admin/dashboard.php" class="btn btn-outline">&larr; Return to Dashboard</a>
    </div>

    <!-- Search & Filter Controls -->
    <div class="menu-controls">
        <form action="<?= $appBase ?>/admin/audit_log.php" method="GET" class="filter-form">
            <div class="search-box">
                <input type="text" 
                       name="search" 
                       class="form-control" 
                       placeholder="Search details, actor name, or IP address..." 
                       value="<?= e($search) ?>">
            </div>

            <div class="filter-selects">
                <select name="action" class="form-control" style="width: auto;">
                    <option value="">All Action Types</option>
                    <?php foreach ($actionTypes as $act): ?>
                        <option value="<?= e($act) ?>" <?= $actionFilter === $act ? 'selected' : '' ?>>
                            <?= e($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" class="btn btn-secondary">Filter Audit Logs</button>
                <?php if (!empty($search) || !empty($actionFilter)): ?>
                    <a href="<?= $appBase ?>/admin/audit_log.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Audit Log Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Actor / User</th>
                    <th>Role</th>
                    <th>Details & Description</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                            No audit log entries found matching your query.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="white-space: nowrap; font-size: 0.8rem; color: var(--text-muted);">
                                <?= format_datetime($log['created_at']) ?>
                            </td>
                            <td>
                                <span class="badge" style="background: var(--bg-subtle); color: var(--color-secondary); font-family: monospace; font-size: 0.75rem;">
                                    <?= e($log['action']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($log['full_name'])): ?>
                                    <strong><?= e($log['full_name']) ?></strong><br>
                                    <span style="font-size: 0.75rem; color: var(--text-muted);"><?= e($log['email']) ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-subtle); font-style: italic;">Guest / System Task</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($log['role_name'])): ?>
                                    <span class="badge badge-role-<?= e($log['role_name']) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $log['role_name'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: var(--text-subtle);">-</span>
                                <?php endif; ?>
                            </td>
                            <td style="max-width: 420px; font-size: 0.85rem;">
                                <?= e($log['details']) ?>
                            </td>
                            <td style="font-family: monospace; font-size: 0.775rem; color: var(--text-muted);">
                                <?= e($log['ip_address'] ?: '127.0.0.1') ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" 
               class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&larr; Prev</a>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                   class="page-btn <?= $page === $i ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>

            <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>" 
               class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next &rarr;</a>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
