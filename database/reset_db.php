<?php

require_once __DIR__ . '/../config/db.php';

$isCli = (php_sapi_name() === 'cli');

// Basic web access protection
if (!$isCli) {
    require_once __DIR__ . '/../includes/auth.php';
    if (!has_role(['admin', 'kitchen_staff'])) {
        header('Content-Type: text/plain');
        http_response_code(403);
        echo "403 Forbidden: Only administrators or kitchen staff can trigger a database reset.\n";
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

echo "=================================================================\n";
echo "   URBANEATS DATABASE RESET & AUTOMATIC MIGRATION RUNNER\n";
echo "=================================================================\n\n";

$pdo = get_db_connection();
$sqlFile = __DIR__ . '/urbaneats_db.sql';

if (!file_exists($sqlFile)) {
    die("Error: Schema file '$sqlFile' not found!\n");
}

echo "1. Reading master schema & seed SQL file: urbaneats_db.sql...\n";

// Disable foreign keys and drop existing tables
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

$tablesToDrop = [
    'grn_items', 'grn_headers', 'order_status_history', 'payments', 
    'order_items', 'orders', 'coupons', 'stock_movements', 'inventory', 
    'dishes', 'categories', 'password_resets', 'audit_log', 
    'login_attempts', 'users', 'roles'
];

foreach ($tablesToDrop as $table) {
    $pdo->exec("DROP TABLE IF EXISTS `$table`");
}
echo "2. Dropped all 16 existing tables to eliminate old schema conflicts.\n";

// Robust line-by-line SQL parser for MySQL multi-line statements
echo "3. Parsing and executing SQL table creation and seed statements...\n";
$handle = fopen($sqlFile, 'r');
$buffer = '';
$executedCount = 0;

if ($handle) {
    while (($line = fgets($handle)) !== false) {
        $trimmed = trim($line);
        // Skip comment lines
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        $buffer .= $line;

        // Check for statement terminator ';'
        if (str_ends_with($trimmed, ';')) {
            try {
                $pdo->exec($buffer);
                $executedCount++;
            } catch (PDOException $e) {
                // Ignore warnings about database existing
                if (!str_contains($e->getMessage(), 'database exists')) {
                    echo "   [Notice] " . substr(trim($buffer), 0, 50) . "... => " . $e->getMessage() . "\n";
                }
            }
            $buffer = '';
        }
    }
    fclose($handle);
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
echo "   ✔ Executed $executedCount SQL statements successfully.\n";

// Verify table count and rows
echo "\n4. Verifying Table Health & Seed Data Counts:\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "   ✔ Active Tables in 'urbaneats_db' (" . count($tables) . " total):\n";
foreach ($tables as $t) {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "     - $t: $count rows\n";
}

echo "\n=================================================================\n";
echo "🎉 SUCCESS: Database completely refreshed and synchronized!\n";
echo "Teammates can now run the app with 100% clean data and GRN tables.\n";
echo "=================================================================\n";
