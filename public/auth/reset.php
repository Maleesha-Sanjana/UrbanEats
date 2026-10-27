<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: Password Reset Submission
 *
 * Validates the time-limited cryptographic token against password_resets,
 * updates user password hash, and marks token as used.
 */

$currentPage = 'reset';
$pageTitle = 'Create New Password - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

$pdo = get_db_connection();
$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$isValidToken = false;
$resetRecord = null;
$error = '';
$userEmail = '';

if (empty($token)) {
    $error = 'No reset token was provided. Please request a new recovery link.';
} else {
    $tokenHash = hash('sha256', $token);

    $stmt = $pdo->prepare("
        SELECT pr.*, u.email, u.full_name
        FROM password_resets pr
        JOIN users u ON pr.user_id = u.id
        WHERE pr.token_hash = :token_hash
          AND pr.expires_at > NOW()
          AND pr.is_used = 0
        LIMIT 1
    ");
    $stmt->execute([':token_hash' => $tokenHash]);
    $resetRecord = $stmt->fetch();

    if ($resetRecord) {
        $isValidToken = true;
        $userEmail = $resetRecord['email'];
    } else {
        $error = 'This password reset link is invalid, expired, or has already been used.';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isValidToken) {
    validate_csrf_or_fail();

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($newPassword) || strlen($newPassword) < 8) {
        $error = 'New password must be at least 8 characters in length.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update password and reset lockout
        $updUser = $pdo->prepare("
            UPDATE users
            SET password_hash = :hash, failed_login_attempts = 0, lockout_until = NULL
            WHERE id = :id
        ");
        $updUser->execute([
            ':hash' => $newHash,
            ':id'   => $resetRecord['user_id']
        ]);

        // Mark token as used
        $updToken = $pdo->prepare("UPDATE password_resets SET is_used = 1 WHERE id = :id");
        $updToken->execute([':id' => $resetRecord['id']]);

        log_action($pdo, 'PASSWORD_RESET_COMPLETED', "Password reset successfully completed for user {$userEmail}", $resetRecord['user_id']);

        set_flash('success', 'Your password has been reset successfully! Please sign in with your new password.');
        header('Location: ' . $appBase . '/auth/login.php');
        exit;
    }
}
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <span style="font-size: 2.5rem; display: inline-block; margin-bottom: 0.5rem;">🔒</span>
            <h1 class="auth-title">Set New Password</h1>
            <?php if ($isValidToken): ?>
                <p class="auth-subtitle">Creating new credentials for: <strong><?= e($userEmail) ?></strong></p>
            <?php else: ?>
                <p class="auth-subtitle">Password Reset Validation</p>
            <?php endif; ?>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($isValidToken): ?>
            <form action="<?= $appBase ?>/auth/reset.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="token" value="<?= e($token) ?>">

                <div class="form-group">
                    <label for="new_password" class="form-label">New Password <span class="required">*</span></label>
                    <input type="password" 
                           id="new_password" 
                           name="new_password" 
                           class="form-control" 
                           placeholder="At least 8 characters" 
                           required 
                           autofocus>
                </div>

                <div class="form-group">
                    <label for="confirm_password" class="form-label">Confirm New Password <span class="required">*</span></label>
                    <input type="password" 
                           id="confirm_password" 
                           name="confirm_password" 
                           class="form-control" 
                           placeholder="Re-enter new password" 
                           required>
                </div>

                <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 1.25rem;">
                    Update Password & Sign In
                </button>
            </form>
        <?php else: ?>
            <div style="text-align: center; margin-top: 1.5rem;">
                <a href="<?= $appBase ?>/auth/forgot.php" class="btn btn-secondary">Request New Reset Link</a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <a href="<?= $appBase ?>/auth/login.php">Return to Login Page</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
