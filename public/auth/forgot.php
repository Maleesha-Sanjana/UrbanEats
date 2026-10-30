<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = get_db_connection();
$generatedLink = '';
$error = '';
$emailInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $emailInput = trim(strtolower($_POST['email'] ?? ''));

    if (empty($emailInput) || !filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name FROM users WHERE email = :email AND is_active = 1 LIMIT 1");
        $stmt->execute([':email' => $emailInput]);
        $user = $stmt->fetch();

        if ($user) {
            // Generate 32 bytes cryptographically random token
            $plainToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $plainToken);

            // Invalidate older unused tokens for this user
            $delStmt = $pdo->prepare("DELETE FROM password_resets WHERE user_id = :uid");
            $delStmt->execute([':uid' => $user['id']]);

            // Insert new reset token valid for 1 hour
            $insStmt = $pdo->prepare("
                INSERT INTO password_resets (user_id, token_hash, expires_at, is_used, created_at)
                VALUES (:uid, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), 0, NOW())
            ");
            $insStmt->execute([
                ':uid'        => $user['id'],
                ':token_hash' => $tokenHash
            ]);

            log_action($pdo, 'PASSWORD_RESET_REQUESTED', "Reset token generated for user {$emailInput}", $user['id']);

            $resetUrl = $appBase . '/auth/reset.php?token=' . urlencode($plainToken);
            $generatedLink = $resetUrl;
        } else {
            // Anti-enumeration notice or simulated message
            $error = 'If that email exists in our records, a password reset link has been issued.';
        }
    }
}

$currentPage = 'forgot';
$pageTitle = 'Forgot Password - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <span style="font-size: 2.5rem; display: inline-block; margin-bottom: 0.5rem;">🔑</span>
            <h1 class="auth-title">Reset Password</h1>
            <p class="auth-subtitle">Enter your registered email address and we'll generate a time-limited recovery link.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-warning"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($generatedLink)): ?>
            <div class="alert alert-success" style="display: block;">
                <strong>✅ Password Reset Link Generated!</strong>
                <p style="font-size: 0.85rem; margin-top: 0.35rem;">
                    (Per assignment specification, email simulation displays the link directly on-screen):
                </p>
                <div class="token-banner">
                    <a href="<?= e($generatedLink) ?>" style="font-weight: 700; text-decoration: underline; word-break: break-all;">
                        <?= e($generatedLink) ?>
                    </a>
                </div>
                <p style="font-size: 0.775rem; color: #065f46; margin-top: 0.25rem;">
                    ⏰ This cryptographic single-use token expires in 60 minutes.
                </p>
            </div>
        <?php endif; ?>

        <form action="<?= $appBase ?>/auth/forgot.php" method="POST">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="email" class="form-label">Account Email Address <span class="required">*</span></label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control" 
                       value="<?= e($emailInput) ?>" 
                       placeholder="e.g. amara.j@gmail.com" 
                       required 
                       autofocus>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 1.25rem;">
                Generate Reset Link
            </button>
        </form>

        <div class="auth-footer">
            Remembered your password? <a href="<?= $appBase ?>/auth/login.php" style="font-weight: 700;">Back to Login</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
