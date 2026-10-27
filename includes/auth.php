<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: Authentication & Access Control Module
 *
 * Implements role-based access control (RBAC), login throttling,
 * secure session management, password resets, and centralized audit logging.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/csrf.php';

// Configure session hardening before starting
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    // Enable secure cookies if running on HTTPS
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

/**
 * Check session inactivity timeout (30 minutes)
 */
function check_session_timeout(): void {
    $timeout = get_config('app.session_lifetime', 1800);
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        // Session expired
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        session_destroy();
        session_start();
        $_SESSION['flash_warning'] = 'Your session has timed out due to inactivity. Please log in again.';
        header('Location: /UrbanEats/public/auth/login.php');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

// Enforce timeout on authenticated sessions
if (!empty($_SESSION['user_id'])) {
    check_session_timeout();
}

/**
 * Check if the user is currently authenticated
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']) && !empty($_SESSION['role_name']);
}

/**
 * Retrieve current logged-in user details
 */
function current_user(): ?array {
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'        => (int)$_SESSION['user_id'],
        'name'      => $_SESSION['user_name'] ?? 'User',
        'email'     => $_SESSION['user_email'] ?? '',
        'role_id'   => (int)$_SESSION['role_id'],
        'role_name' => $_SESSION['role_name'] ?? 'customer'
    ];
}

/**
 * Check if the current user possesses one of the specified roles
 * @param string|array $roles Single role name or array of role names
 */
function has_role($roles): bool {
    if (!is_logged_in()) {
        return false;
    }
    $userRole = $_SESSION['role_name'] ?? '';
    if (is_array($roles)) {
        return in_array($userRole, $roles, true);
    }
    return $userRole === $roles;
}

/**
 * Authentication Guard: Require user to be logged in
 */
function require_login(string $redirect = '/UrbanEats/public/auth/login.php'): void {
    if (!is_logged_in()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/UrbanEats/public/index.php';
        $_SESSION['flash_warning'] = 'Please log in to access this page.';
        header("Location: $redirect");
        exit;
    }
}

/**
 * Role-Based Access Guard: Require specific role(s)
 * If not authenticated, redirects to login.
 * If authenticated but lacking permissions, renders 403 Forbidden.
 */
function require_role($roles): void {
    require_login();

    if (!has_role($roles)) {
        http_response_code(403);
        $roleList = is_array($roles) ? implode(', ', $roles) : $roles;
        render_error_page(
            403,
            'Access Denied',
            "You do not possess the required permissions ($roleList) to access this administrative resource.",
            '/UrbanEats/public/index.php'
        );
        exit;
    }
}

/**
 * Centralized Audit Logger
 * Components A, B, and C call this function to record system audit entries.
 */
function log_action(PDO $pdo, string $action, string $details, ?int $userId = null): bool {
    try {
        if ($userId === null && !empty($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        $stmt = $pdo->prepare("
            INSERT INTO audit_log (user_id, action, details, ip_address, created_at)
            VALUES (:user_id, :action, :details, :ip_address, NOW())
        ");

        return $stmt->execute([
            ':user_id'    => $userId,
            ':action'     => $action,
            ':details'    => $details,
            ':ip_address' => $ip
        ]);
    } catch (PDOException $e) {
        log_system_error('AUDIT_LOG_FAIL', $e->getMessage(), $e->getFile(), $e->getLine());
        return false;
    }
}

/**
 * Attempt user authentication with login throttling
 * Locks account after 5 consecutive failed attempts for 15 minutes.
 */
function attempt_login(PDO $pdo, string $email, string $password): array {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    // 1. Fetch user by email including role name
    $stmt = $pdo->prepare("
        SELECT u.*, r.name AS role_name
        FROM users u
        INNER JOIN roles r ON u.role_id = r.id
        WHERE u.email = :email
        LIMIT 1
    ");
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    // If user not found, log attempt and return generic failure
    if (!$user) {
        record_login_attempt($pdo, $ip, $email, false);
        return ['success' => false, 'error' => 'Invalid email address or password.'];
    }

    // 2. Check if account is deactivated
    if ((int)$user['is_active'] !== 1) {
        return ['success' => false, 'error' => 'This account has been deactivated. Please contact support.'];
    }

    // 3. Check for active temporary lockout
    if (!empty($user['lockout_until'])) {
        $lockoutTime = strtotime($user['lockout_until']);
        if ($lockoutTime > time()) {
            $remainingMinutes = ceil(($lockoutTime - time()) / 60);
            return [
                'success' => false,
                'error' => "Account is temporarily locked due to 5 consecutive failed attempts. Please try again in {$remainingMinutes} minute(s)."
            ];
        } else {
            // Lockout expired, reset counter
            $resetStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = :id");
            $resetStmt->execute([':id' => $user['id']]);
            $user['failed_login_attempts'] = 0;
            $user['lockout_until'] = null;
        }
    }

    // 4. Verify password
    if (!password_verify($password, $user['password_hash'])) {
        // Increment failed attempts
        $newFailedAttempts = (int)$user['failed_login_attempts'] + 1;
        $maxAttempts = (int)get_config('app.max_failed_logins', 5);
        $lockoutUntil = null;

        if ($newFailedAttempts >= $maxAttempts) {
            $lockoutMinutes = ceil(get_config('app.lockout_time', 900) / 60);
            $lockoutUntil = date('Y-m-d H:i:s', time() + get_config('app.lockout_time', 900));
            $upd = $pdo->prepare("
                UPDATE users
                SET failed_login_attempts = :attempts, lockout_until = :lockout
                WHERE id = :id
            ");
            $upd->execute([
                ':attempts' => $newFailedAttempts,
                ':lockout'  => $lockoutUntil,
                ':id'       => $user['id']
            ]);

            record_login_attempt($pdo, $ip, $email, false);
            log_action($pdo, 'ACCOUNT_LOCKED', "Account locked for 15 minutes after {$newFailedAttempts} failed logins", $user['id']);

            return [
                'success' => false,
                'error' => "Account locked after {$maxAttempts} failed attempts. Please try again in {$lockoutMinutes} minutes."
            ];
        } else {
            $upd = $pdo->prepare("UPDATE users SET failed_login_attempts = :attempts WHERE id = :id");
            $upd->execute([':attempts' => $newFailedAttempts, ':id' => $user['id']]);

            record_login_attempt($pdo, $ip, $email, false);
            $remaining = $maxAttempts - $newFailedAttempts;
            return [
                'success' => false,
                'error' => "Invalid email or password. {$remaining} attempt(s) remaining before temporary lockout."
            ];
        }
    }

    // 5. Successful login
    // Reset failed attempts and lockout
    $resetStmt = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = :id");
    $resetStmt->execute([':id' => $user['id']]);

    record_login_attempt($pdo, $ip, $email, true);

    // Session regeneration prevents session fixation attacks
    if (!headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    $_SESSION['user_id']       = $user['id'];
    $_SESSION['user_name']     = $user['full_name'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['role_id']       = $user['role_id'];
    $_SESSION['role_name']     = $user['role_name'];
    $_SESSION['last_activity'] = time();

    log_action($pdo, 'LOGIN_SUCCESS', "User {$user['email']} ({$user['role_name']}) logged in successfully", $user['id']);

    return ['success' => true, 'user' => $user];
}

/**
 * Record login attempt into login_attempts table
 */
function record_login_attempt(PDO $pdo, string $ip, string $email, bool $isSuccessful): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO login_attempts (ip_address, email_attempted, is_successful, attempted_at)
            VALUES (:ip, :email, :success, NOW())
        ");
        $stmt->execute([
            ':ip'      => $ip,
            ':email'   => $email,
            ':success' => $isSuccessful ? 1 : 0
        ]);
    } catch (PDOException $e) {
        log_system_error('LOGIN_ATTEMPT_LOG_FAIL', $e->getMessage(), $e->getFile(), $e->getLine());
    }
}

/**
 * Destroy user session and log out
 */
function logout_user(PDO $pdo): void {
    if (!empty($_SESSION['user_id'])) {
        log_action($pdo, 'LOGOUT', "User logged out", (int)$_SESSION['user_id']);
    }

    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();
}
