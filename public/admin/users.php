<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: Administrator User Management
 *
 * Implements user listing, search, filtering by role, activation/deactivation,
 * role reassignment, and audit logging.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_role('admin');

$pdo = get_db_connection();
$currentAdminId = (int)$_SESSION['user_id'];

// 1. Handle POST Actions (Status toggle, Role change)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    // Fetch target user
    $tStmt = $pdo->prepare("SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.id = :id");
    $tStmt->execute([':id' => $targetUserId]);
    $targetUser = $tStmt->fetch();

    if (!$targetUser) {
        set_flash('error', 'Target user not found.');
    } else {
        if ($action === 'toggle_status') {
            if ($targetUserId === $currentAdminId) {
                set_flash('error', 'Security Violation: You cannot deactivate your own administrative account.');
            } else {
                $newStatus = ((int)$targetUser['is_active'] === 1) ? 0 : 1;
                $upd = $pdo->prepare("UPDATE users SET is_active = :status WHERE id = :id");
                $upd->execute([':status' => $newStatus, ':id' => $targetUserId]);

                $statusLabel = ($newStatus === 1) ? 'activated' : 'deactivated';
                log_action($pdo, 'USER_STATUS_UPDATE', "Admin toggled user {$targetUser['email']} status to {$statusLabel}", $currentAdminId);
                set_flash('success', "User account for {$targetUser['full_name']} successfully {$statusLabel}.");
            }
        } elseif ($action === 'change_role') {
            $newRoleId = (int)($_POST['new_role_id'] ?? 0);
            if ($targetUserId === $currentAdminId) {
                set_flash('error', 'Security Violation: You cannot modify your own administrative role.');
            } else {
                // Verify valid role ID
                $rCheck = $pdo->prepare("SELECT name FROM roles WHERE id = :id");
                $rCheck->execute([':id' => $newRoleId]);
                $newRoleName = $rCheck->fetchColumn();

                if ($newRoleName) {
                    $upd = $pdo->prepare("UPDATE users SET role_id = :role_id WHERE id = :id");
                    $upd->execute([':role_id' => $newRoleId, ':id' => $targetUserId]);

                    log_action($pdo, 'ROLE_CHANGED', "Admin changed {$targetUser['email']} role from {$targetUser['role_name']} to {$newRoleName}", $currentAdminId);
                    set_flash('success', "Role for {$targetUser['full_name']} updated to {$newRoleName}.");
                } else {
                    set_flash('error', 'Invalid role selected.');
                }
            }
        }
    }
    header("Location: {$appBase}/admin/users.php?" . http_build_query($_GET));
    exit;
}

$currentPage = 'admin';
$pageTitle = 'Manage Users - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// 2. Fetch All Roles for Filter & Modal
$roles = $pdo->query("SELECT * FROM roles ORDER BY id ASC")->fetchAll();

// 3. Search & Filter Parameters (Validated & Whitelisted)
$search = trim($_GET['search'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = ['1=1'];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(u.full_name LIKE :search_name OR u.email LIKE :search_email OR u.phone LIKE :search_phone)";
    $params[':search_name'] = "%{$search}%";
    $params[':search_email'] = "%{$search}%";
    $params[':search_phone'] = "%{$search}%";
}

if (!empty($roleFilter)) {
    $whereClauses[] = "r.name = :role";
    $params[':role'] = $roleFilter;
}

if ($statusFilter !== '') {
    $whereClauses[] = "u.is_active = :status";
    $params[':status'] = (int)$statusFilter;
}

$whereSql = implode(' AND ', $whereClauses);

// Count Total matching
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE $whereSql
");
$countStmt->execute($params);
$totalUsers = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalUsers / $perPage));

// Fetch Users for current page
$listStmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE $whereSql
    ORDER BY u.id ASC
    LIMIT :limit OFFSET :offset
");

foreach ($params as $k => $v) {
    $listStmt->bindValue($k, $v);
}
$listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$listStmt->execute();
$usersList = $listStmt->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component A: Administration</span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">User Accounts & Role Permissions</h1>
        </div>
        <a href="<?= $appBase ?>/admin/dashboard.php" class="btn btn-outline">&larr; Return to Dashboard</a>
    </div>

    <!-- Search & Filters Card -->
    <div class="menu-controls">
        <form action="<?= $appBase ?>/admin/users.php" method="GET" class="filter-form">
            <div class="search-box">
                <input type="text" 
                       name="search" 
                       class="form-control" 
                       placeholder="Search by name, email, or phone..." 
                       value="<?= e($search) ?>">
            </div>

            <div class="filter-selects">
                <select name="role" class="form-control" style="width: auto;">
                    <option value="">All Roles</option>
                    <?php foreach ($roles as $r): ?>
                        <option value="<?= e($r['name']) ?>" <?= $roleFilter === $r['name'] ? 'selected' : '' ?>>
                            <?= ucfirst(str_replace('_', ' ', $r['name'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="status" class="form-control" style="width: auto;">
                    <option value="">All Statuses</option>
                    <option value="1" <?= $statusFilter === '1' ? 'selected' : '' ?>>Active Only</option>
                    <option value="0" <?= $statusFilter === '0' ? 'selected' : '' ?>>Deactivated Only</option>
                </select>

                <button type="submit" class="btn btn-secondary">Filter Users</button>
                <?php if (!empty($search) || !empty($roleFilter) || $statusFilter !== ''): ?>
                    <a href="<?= $appBase ?>/admin/users.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Users Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Full Name</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Joined</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($usersList)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2.5rem; color: var(--text-muted);">
                            No user accounts matched your search criteria.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($usersList as $u): ?>
                        <tr>
                            <td>#<?= $u['id'] ?></td>
                            <td>
                                <strong><?= e($u['full_name']) ?></strong>
                                <?php if ($u['id'] === $currentAdminId): ?>
                                    <span style="font-size: 0.7rem; color: var(--color-primary); font-weight: 700; margin-left: 0.25rem;">(You)</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e($u['email']) ?></td>
                            <td><?= e($u['phone']) ?></td>
                            <td>
                                <span class="badge badge-role-<?= e($u['role_name']) ?>">
                                    <?= ucfirst(str_replace('_', ' ', $u['role_name'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ((int)$u['is_active'] === 1): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Deactivated</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8rem; color: var(--text-muted);"><?= format_datetime($u['created_at'], 'M d, Y') ?></td>
                            <td style="text-align: right;">
                                <?php if ($u['id'] !== $currentAdminId): ?>
                                    <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                                        <!-- Role Change Inline Form -->
                                        <form action="<?= $appBase ?>/admin/users.php?<?= http_build_query($_GET) ?>" method="POST" style="margin: 0; display: inline-flex; align-items: center; gap: 0.25rem;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <select name="new_role_id" class="form-control" style="font-size: 0.75rem; padding: 0.2rem 0.4rem; height: auto;" onchange="this.form.submit()">
                                                <?php foreach ($roles as $r): ?>
                                                    <option value="<?= $r['id'] ?>" <?= $u['role_id'] === $r['id'] ? 'selected' : '' ?>>
                                                        Role: <?= ucfirst(str_replace('_', ' ', $r['name'])) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>

                                        <!-- Toggle Status Form -->
                                        <form action="<?= $appBase ?>/admin/users.php?<?= http_build_query($_GET) ?>" method="POST" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <?php if ((int)$u['is_active'] === 1): ?>
                                                <button type="submit" class="btn btn-outline-sm text-danger" style="color: var(--color-danger); border-color: #fca5a5;" onclick="return confirm('Are you sure you want to deactivate account: <?= e($u['full_name']) ?>?');">
                                                    Deactivate
                                                </button>
                                            <?php else: ?>
                                                <button type="submit" class="btn btn-primary-sm" style="background: var(--color-success);">
                                                    Activate
                                                </button>
                                            <?php endif; ?>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: var(--text-muted); font-style: italic;">Current Session</span>
                                <?php endif; ?>
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
