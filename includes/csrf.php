<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * CSRF Protection Module
 *
 * Implements synchronizer token pattern using cryptographically secure random bytes
 * and timing-attack-safe comparison via hash_equals().
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Generate or retrieve current CSRF token
 */
function generate_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Output hidden HTML input field containing current CSRF token
 */
function csrf_field(): string {
    $token = htmlspecialchars(generate_csrf_token(), ENT_QUOTES, 'UTF-8');
    return '<input type="hidden" name="csrf_token" value="' . $token . '">';
}

/**
 * Validate CSRF token from POST request
 */
function verify_csrf_token(?string $token = null): bool {
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? '';
    }

    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }

    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Enforce CSRF token verification on POST requests
 * Halts with 403 Forbidden if invalid.
 */
function validate_csrf_or_fail(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verify_csrf_token()) {
            http_response_code(403);
            require_once __DIR__ . '/errors.php';
            render_error_page(
                403,
                'Invalid Security Token',
                'Your session security token has expired or is invalid. Please refresh the page and try submitting the form again.'
            );
            exit;
        }
    }
}
