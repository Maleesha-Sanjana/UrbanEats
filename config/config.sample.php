<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Sample Configuration File
 *
 * Copy this file to 'config.php' or update 'db.php' with your local environment settings.
 * DO NOT commit production passwords to version control.
 */

return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'urbaneats_db',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4'
    ],
    'app' => [
        'name' => 'UrbanEats Cloud Kitchen',
        'currency' => 'LKR',
        'base_url' => '/UrbanEats/public', // Adjust according to your local XAMPP/WAMP/server setup
        'session_lifetime' => 1800, // 30 minutes
        'max_failed_logins' => 5,
        'lockout_time' => 900, // 15 minutes in seconds
        'upload_dir' => __DIR__ . '/../public/uploads/',
        'max_upload_size' => 2 * 1024 * 1024, // 2MB
        'allowed_image_types' => ['image/jpeg', 'image/png', 'image/webp']
    ]
];
