<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

// If already logged in, redirect based on role
if (is_logged_in()) {
    if (has_role('admin')) {
        header('Location: ' . $appBase . '/admin/dashboard.php');
    } elseif (has_role('kitchen_staff')) {
        header('Location: ' . $appBase . '/orders/kitchen.php');
    } else {
        header('Location: ' . $appBase . '/index.php');
    }
    exit;
}

$pdo = get_db_connection();
$error = '';
$emailInput = '';

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $emailInput = trim($_POST['email'] ?? '');
    $passwordInput = $_POST['password'] ?? '';

    if (empty($emailInput) || empty($passwordInput)) {
        $error = 'Please enter both your email address and password.';
    } else {
        $result = attempt_login($pdo, $emailInput, $passwordInput);
        if ($result['success']) {
            $user = $result['user'];
            set_flash('success', "Welcome back, {$user['full_name']}!");

            // Determine redirect destination
            $redirect = $_SESSION['redirect_after_login'] ?? '';
            unset($_SESSION['redirect_after_login']);

            if (!empty($redirect) && str_starts_with($redirect, $appBase)) {
                header("Location: $redirect");
            } else {
                if ($user['role_name'] === 'admin') {
                    header("Location: {$appBase}/admin/dashboard.php");
                } elseif ($user['role_name'] === 'kitchen_staff') {
                    header("Location: {$appBase}/orders/kitchen.php");
                } else {
                    header("Location: {$appBase}/index.php");
                }
            }
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$currentPage = 'login';
$pageTitle = 'Login - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <div class="auth-header">
            <span style="font-size: 2.5rem; display: inline-block; margin-bottom: 0.5rem;">🔐</span>
            <h1 class="auth-title">Welcome Back</h1>
            <p class="auth-subtitle">Sign in to your UrbanEats account to place orders or manage kitchen operations.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form action="<?= $appBase ?>/auth/login.php" method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                <input type="email" 
                       id="email" 
                       name="email" 
                       class="form-control <?= !empty($error) ? 'is-invalid' : '' ?>" 
                       value="<?= e($emailInput) ?>" 
                       placeholder="name@example.com" 
                       required 
                       autofocus>
            </div>

            <div class="form-group">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.4rem;">
                    <label for="password" class="form-label" style="margin-bottom: 0;">Password <span class="required">*</span></label>
                    <a href="<?= $appBase ?>/auth/forgot.php" style="font-size: 0.8125rem; font-weight: 600;">Forgot Password?</a>
                </div>
                <input type="password" 
                       id="password" 
                       name="password" 
                       class="form-control <?= !empty($error) ? 'is-invalid' : '' ?>" 
                       placeholder="Enter your password" 
                       required>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 1.5rem;">
                Sign In to Account
            </button>
        </form>

        <div class="auth-footer">
            Don't have an account yet? <a href="<?= $appBase ?>/auth/register.php" style="font-weight: 700;">Create Customer Account</a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
