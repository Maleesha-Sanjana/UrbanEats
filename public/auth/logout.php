<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component A: User Logout Handler
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = get_db_connection();
logout_user($pdo);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
set_flash('info', 'You have been logged out securely. See you again soon!');

header('Location: ' . $appBase . '/auth/login.php');
exit;
