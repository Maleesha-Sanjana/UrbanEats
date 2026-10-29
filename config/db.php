<?php
// Load custom config if present, or use defaults
$configFile = __DIR__ . '/config.php';
$config = file_exists($configFile) ? require $configFile : [
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: 3306,
        'dbname' => getenv('DB_NAME') ?: 'urbaneats_db',
        'username' => getenv('DB_USER') ?: 'root',
        'password' => getenv('DB_PASS') !== false ? getenv('DB_PASS') : '',
        'charset' => 'utf8mb4'
    ],
    'app' => [
        'name' => 'UrbanEats Cloud Kitchen',
        'currency' => 'LKR',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        'session_lifetime' => 1800,
        'max_failed_logins' => 5,
        'lockout_time' => 900,
        'upload_dir' => __DIR__ . '/../public/uploads/',
        'max_upload_size' => 2 * 1024 * 1024,
        'allowed_image_types' => ['image/jpeg', 'image/png', 'image/webp']
    ]
];

// Helper to access configuration array globally
function get_config(?string $key = null, $default = null) {
    global $config;
    if ($key === null) return $config;
    
    $segments = explode('.', $key);
    $current = $config;
    foreach ($segments as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return $default;
        }
        $current = $current[$segment];
    }
    return $current;
}

// Function to establish and return the singleton PDO connection
function get_db_connection(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dbConfig = get_config('db');
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s;charset=%s",
            $dbConfig['host'],
            $dbConfig['port'],
            $dbConfig['dbname'],
            $dbConfig['charset']
        );

        $initCommandAttr = defined('Pdo\Mysql::ATTR_INIT_COMMAND') ? Pdo\Mysql::ATTR_INIT_COMMAND : (defined('PDO::MYSQL_ATTR_INIT_COMMAND') ? PDO::MYSQL_ATTR_INIT_COMMAND : 1002);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            $initCommandAttr             => "SET NAMES {$dbConfig['charset']} COLLATE {$dbConfig['charset']}_unicode_ci"
        ];

        try {
            $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
            // Self-heal and auto-sync Component B GRN schema for teammates on run
            auto_sync_database_schema_if_needed($pdo);
        } catch (PDOException $e) {
            // Log raw connection error to server log
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            $logEntry = sprintf("[%s] [DB Connection Error] %s\n", date('Y-m-d H:i:s'), $e->getMessage());
            error_log($logEntry, 3, $logDir . '/error.log');

            // Friendly error message without exposing credentials
            http_response_code(500);
            require_once __DIR__ . '/../includes/errors.php';
            render_error_page(
                500,
                'Database Connection Error',
                'UrbanEats could not connect to the database. Please ensure MySQL is running in XAMPP/WAMP and the "urbaneats_db" schema is imported.'
            );
            exit;
        }
    }

    return $pdo;
}

/**
 * Auto-Synchronize Database Schema on Run
 * Ensures teammates' local databases automatically get all Component B tables (grn_headers, grn_items)
 * and sample transactions without requiring manual phpMyAdmin imports.
 */
function auto_sync_database_schema_if_needed(PDO $pdo): void {
    static $synced = false;
    if ($synced) return;
    $synced = true;

    try {
        $check = $pdo->query("SHOW TABLES LIKE 'grn_headers'")->fetch();
        if (!$check) {
            $sqlFile = __DIR__ . '/../database/urbaneats_db.sql';
            if (file_exists($sqlFile)) {
                $handle = fopen($sqlFile, 'r');
                if ($handle) {
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    $buffer = '';
                    while (($line = fgets($handle)) !== false) {
                        $trimmed = trim($line);
                        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
                            continue;
                        }
                        $buffer .= $line;
                        if (str_ends_with($trimmed, ';')) {
                            try {
                                $pdo->exec($buffer);
                            } catch (Exception $e) {}
                            $buffer = '';
                        }
                    }
                    fclose($handle);
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
                }
            }
        }
    } catch (Exception $e) {
        // Fail silently so normal execution is never interrupted
    }
}
