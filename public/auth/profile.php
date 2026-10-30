<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

require_login();

$pdo = get_db_connection();
$userId = (int)$_SESSION['user_id'];

// Fetch current user details from DB
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    set_flash('error', 'User record not found.');
    header("Location: {$appBase}/index.php");
    exit;
}

$profileSuccess = '';
$profileError = '';
$pwdSuccess = '';
$pwdError = '';

// Handle Profile Details Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'update_profile') {
    validate_csrf_or_fail();

    $name = trim($_POST['full_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (empty($name) || strlen($name) < 3) {
        $profileError = 'Full name must be at least 3 characters long.';
    } elseif (empty($phone)) {
        $profileError = 'Phone number is required.';
    } else {
        $updStmt = $pdo->prepare("
            UPDATE users
            SET full_name = :name, phone = :phone, address = :address
            WHERE id = :id
        ");
        $updStmt->execute([
            ':name'    => $name,
            ':phone'   => $phone,
            ':address' => $address,
            ':id'      => $userId
        ]);

        $_SESSION['user_name'] = $name;
        $user['full_name'] = $name;
        $user['phone'] = $phone;
        $user['address'] = $address;

        log_action($pdo, 'PROFILE_UPDATED', "User updated profile information", $userId);
        $profileSuccess = 'Profile details updated successfully!';
    }
}

// Handle Change Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'change_password') {
    validate_csrf_or_fail();

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $pwdError = 'All password fields are required.';
    } elseif (!password_verify($currentPassword, $user['password_hash'])) {
        $pwdError = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 8) {
        $pwdError = 'New password must be at least 8 characters long.';
    } elseif ($newPassword !== $confirmPassword) {
        $pwdError = 'New password and confirmation do not match.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pwdStmt = $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
        $pwdStmt->execute([':hash' => $newHash, ':id' => $userId]);

        log_action($pdo, 'PASSWORD_CHANGED', "User changed their password", $userId);
        $pwdSuccess = 'Password updated successfully!';
    }
}

$currentPage = 'profile';
$pageTitle = 'My Profile - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="site-container" style="max-width: 900px; margin: 1.5rem auto 3rem;">
    <div style="margin-bottom: 2rem;">
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Account Settings</span>
        <h1 style="font-size: 2rem; margin-top: 0.25rem;">User Profile & Security</h1>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 2rem; align-items: start;">
        <!-- Card 1: Personal Details -->
        <div class="auth-card" style="box-shadow: var(--shadow-sm);">
            <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
                <div class="user-avatar" style="width: 3.5rem; height: 3.5rem; font-size: 1.5rem;">
                    <?= strtoupper(substr($user['full_name'], 0, 1)) ?>
                </div>
                <div>
                    <h2 style="font-size: 1.25rem;"><?= e($user['full_name']) ?></h2>
                    <span class="badge badge-role-<?= e($user['role_name']) ?>">
                        Role: <?= ucfirst(str_replace('_', ' ', $user['role_name'])) ?>
                    </span>
                </div>
            </div>

            <?php if (!empty($profileSuccess)): ?>
                <div class="alert alert-success"><?= e($profileSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($profileError)): ?>
                <div class="alert alert-danger"><?= e($profileError) ?></div>
            <?php endif; ?>

            <form action="<?= $appBase ?>/auth/profile.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="update_profile">

                <div class="form-group">
                    <label class="form-label">Email Address (Read-only)</label>
                    <input type="email" class="form-control" value="<?= e($user['email']) ?>" readonly style="background-color: var(--bg-subtle);">
                    <p class="form-hint">Email address cannot be changed for security audit purposes.</p>
                </div>

                <div class="form-group">
                    <label for="full_name" class="form-label">Full Name <span class="required">*</span></label>
                    <input type="text" id="full_name" name="full_name" class="form-control" value="<?= e($user['full_name']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number <span class="required">*</span></label>
                    <input type="tel" id="phone" name="phone" class="form-control" value="<?= e($user['phone']) ?>" required>
                </div>

                <div class="form-group">
                    <label for="address" class="form-label">Default Delivery Address</label>
                    <textarea id="address" name="address" rows="3" class="form-control"><?= e($user['address']) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Save Profile Changes</button>
            </form>
        </div>

        <!-- Card 2: Security & Password Change -->
        <div class="auth-card" style="box-shadow: var(--shadow-sm);">
            <div style="margin-bottom: 1.5rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border-color);">
                <h2 style="font-size: 1.25rem;">Change Password</h2>
                <p style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                    Ensure your account is protected with a secure password at least 8 characters long.
                </p>
            </div>

            <?php if (!empty($pwdSuccess)): ?>
                <div class="alert alert-success"><?= e($pwdSuccess) ?></div>
            <?php endif; ?>
            <?php if (!empty($pwdError)): ?>
                <div class="alert alert-danger"><?= e($pwdError) ?></div>
            <?php endif; ?>

            <form action="<?= $appBase ?>/auth/profile.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="action_type" value="change_password">

                <div class="form-group">
                    <label for="current_password" class="form-label">Current Password <span class="required">*</span></label>
                    <input type="password" id="current_password" name="current_password" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="new_password" class="form-label">New Password <span class="required">*</span></label>
                    <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Min. 8 characters" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password <span class="required">*</span></label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-type new password" required>
                </div>

                <button type="submit" class="btn btn-secondary btn-block">Update Password</button>
            </form>

            <div style="margin-top: 1.75rem; padding-top: 1.25rem; border-top: 1px dashed var(--border-color); font-size: 0.8rem; color: var(--text-muted);">
                <span>Member Since: <?= format_datetime($user['created_at'], 'F j, Y') ?></span>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
