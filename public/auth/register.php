<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: Customer Registration Page
 *
 * Implements server-side validation, duplicate email checks,
 * password hashing with password_hash(), and automated session login.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

if (is_logged_in()) {
    header('Location: ' . $appBase . '/index.php');
    exit;
}

$pdo = get_db_connection();
$errors = [];
$name = '';
$email = '';
$phone = '';
$address = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $name = trim($_POST['full_name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // Validation
    if (empty($name)) {
        $errors['full_name'] = 'Full name is required.';
    } elseif (strlen($name) < 3) {
        $errors['full_name'] = 'Full name must be at least 3 characters.';
    }

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'A valid email address is required.';
    } else {
        // Check for duplicate email
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->execute([':email' => $email]);
        if ($checkStmt->fetch()) {
            $errors['email'] = 'An account with this email address already exists. Please log in.';
        }
    }

    if (empty($phone)) {
        $errors['phone'] = 'Contact phone number is required.';
    } elseif (!preg_match('/^[0-9+\s\-()]{9,20}$/', $phone)) {
        $errors['phone'] = 'Please enter a valid phone number (e.g., +94771234567 or 0771234567).';
    }

    if (empty($address)) {
        $errors['address'] = 'Delivery/residential address is required.';
    }

    if (empty($password)) {
        $errors['password'] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters long.';
    }

    if ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Password confirmation does not match.';
    }

    // If validation passes, create account
    if (empty($errors)) {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Fetch customer role ID
        $roleStmt = $pdo->prepare("SELECT id FROM roles WHERE name = 'customer' LIMIT 1");
        $roleStmt->execute();
        $customerRoleId = (int)($roleStmt->fetchColumn() ?: 3);

        $insertStmt = $pdo->prepare("
            INSERT INTO users (role_id, full_name, email, phone, password_hash, address, is_active, created_at)
            VALUES (:role_id, :full_name, :email, :phone, :password_hash, :address, 1, NOW())
        ");

        $insertStmt->execute([
            ':role_id'       => $customerRoleId,
            ':full_name'     => $name,
            ':email'         => $email,
            ':phone'         => $phone,
            ':password_hash' => $passwordHash,
            ':address'       => $address
        ]);

        $newUserId = (int)$pdo->lastInsertId();

        // Audit log entry
        log_action($pdo, 'USER_REGISTERED', "New customer registration: {$email}", $newUserId);

        // Auto login
        session_regenerate_id(true);
        $_SESSION['user_id']       = $newUserId;
        $_SESSION['user_name']     = $name;
        $_SESSION['user_email']    = $email;
        $_SESSION['role_id']       = $customerRoleId;
        $_SESSION['role_name']     = 'customer';
        $_SESSION['last_activity'] = time();

        set_flash('success', "Welcome to UrbanEats, {$name}! Your account has been registered successfully.");
        header("Location: {$appBase}/index.php");
        exit;
    }
}

$currentPage = 'register';
$pageTitle = 'Register Account - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="auth-wrapper" style="max-width: 540px;">
    <div class="auth-card">
        <div class="auth-header">
            <span style="font-size: 2.5rem; display: inline-block; margin-bottom: 0.5rem;">✨</span>
            <h1 class="auth-title">Create Account</h1>
            <p class="auth-subtitle">Join UrbanEats to order gourmet Sri Lankan meals with express delivery tracking.</p>
        </div>

        <?php if (!empty($errors['general'])): ?>
            <div class="alert alert-danger"><?= e($errors['general']) ?></div>
        <?php endif; ?>

        <form action="<?= $appBase ?>/auth/register.php" method="POST" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="full_name" class="form-label">Full Name <span class="required">*</span></label>
                <input type="text" 
                       id="full_name" 
                       name="full_name" 
                       class="form-control <?= isset($errors['full_name']) ? 'is-invalid' : '' ?>" 
                       value="<?= e($name) ?>" 
                       placeholder="e.g. Kasun Kalhara" 
                       required>
                <?php if (isset($errors['full_name'])): ?>
                    <p class="form-hint text-danger" style="color: var(--color-danger);"><?= e($errors['full_name']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email" class="form-label">Email Address <span class="required">*</span></label>
                    <input type="email" 
                           id="email" 
                           name="email" 
                           class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>" 
                           value="<?= e($email) ?>" 
                           placeholder="name@example.com" 
                           required>
                    <?php if (isset($errors['email'])): ?>
                        <p class="form-hint" style="color: var(--color-danger);"><?= e($errors['email']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="phone" class="form-label">Phone Number <span class="required">*</span></label>
                    <input type="tel" 
                           id="phone" 
                           name="phone" 
                           class="form-control <?= isset($errors['phone']) ? 'is-invalid' : '' ?>" 
                           value="<?= e($phone) ?>" 
                           placeholder="+94 77 123 4567" 
                           required>
                    <?php if (isset($errors['phone'])): ?>
                        <p class="form-hint" style="color: var(--color-danger);"><?= e($errors['phone']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="address" class="form-label">Delivery Address <span class="required">*</span></label>
                <textarea id="address" 
                          name="address" 
                          rows="2" 
                          class="form-control <?= isset($errors['address']) ? 'is-invalid' : '' ?>" 
                          placeholder="House/Apt No, Street Name, City (e.g. 12/4 Flower Road, Colombo 07)" 
                          required><?= e($address) ?></textarea>
                <?php if (isset($errors['address'])): ?>
                    <p class="form-hint" style="color: var(--color-danger);"><?= e($errors['address']) ?></p>
                <?php endif; ?>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="password" class="form-label">Password <span class="required">*</span></label>
                    <input type="password" 
                           id="password" 
                           name="password" 
                           class="form-control <?= isset($errors['password']) ? 'is-invalid' : '' ?>" 
                           placeholder="At least 8 characters" 
                           required>
                    <?php if (isset($errors['password'])): ?>
                        <p class="form-hint" style="color: var(--color-danger);"><?= e($errors['password']) ?></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="password_confirm" class="form-label">Confirm Password <span class="required">*</span></label>
                    <input type="password" 
                           id="password_confirm" 
                           name="password_confirm" 
                           class="form-control <?= isset($errors['password_confirm']) ? 'is-invalid' : '' ?>" 
                           placeholder="Re-enter password" 
                           required>
                    <?php if (isset($errors['password_confirm'])): ?>
                        <p class="form-hint" style="color: var(--color-danger);"><?= e($errors['password_confirm']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top: 1rem;">
                Create My Account
            </button>
        </form>

        <div class="auth-footer">
            Already registered? <a href="<?= $appBase ?>/auth/login.php" style="font-weight: 700;">Sign In Here</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
