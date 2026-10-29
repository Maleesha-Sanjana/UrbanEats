<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$appBase = get_app_base_url();
$pdo = get_db_connection();
logout_user($pdo);

set_flash('info', 'You have been logged out securely. See you again soon!');

header('Location: ' . $appBase . '/auth/login.php');
exit;
