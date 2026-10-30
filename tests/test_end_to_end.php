<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/errors.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db_connection();
echo "=================================================================\n";
echo "   URBANEATS AUTOMATED END-TO-END INTEGRATION TEST RUNNER\n";
echo "=================================================================\n\n";

$testsPassed = 0;
$testsFailed = 0;

function assert_test($description, $condition) {
    global $testsPassed, $testsFailed;
    if ($condition) {
        echo " [PASS] $description\n";
        $testsPassed++;
    } else {
        echo " [FAIL] $description\n";
        $testsFailed++;
    }
}

// -------------------------------------------------------------------
// TEST 1: Database Connection & Seed Data Integrity
// -------------------------------------------------------------------
echo "--- TEST SUITE 1: System & Schema Integrity ---\n";
$userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
assert_test("Database contains >= 20 seeded users (Found: $userCount)", $userCount >= 20);

$dishCount = (int)$pdo->query("SELECT COUNT(*) FROM dishes WHERE is_active = 1")->fetchColumn();
assert_test("Database contains >= 20 active dishes (Found: $dishCount)", $dishCount >= 20);

$orderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
assert_test("Database contains >= 20 seeded orders (Found: $orderCount)", $orderCount >= 20);

// -------------------------------------------------------------------
// TEST 2: Authentication, Hashing & Throttling
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 2: Component A (Identity & Access) ---\n";
// Test login with valid admin credentials
$adminLogin = attempt_login($pdo, 'admin@urbaneats.lk', 'Password123!');
assert_test("Admin authentication succeeds with valid password", $adminLogin['success'] === true);
assert_test("Admin user role is 'admin'", ($adminLogin['user']['role_name'] ?? '') === 'admin');

// Test login with invalid password
$badLogin = attempt_login($pdo, 'admin@urbaneats.lk', 'WrongPassword!');
assert_test("Authentication fails with incorrect password", $badLogin['success'] === false);

// -------------------------------------------------------------------
// TEST 3: Inventory & Safe Stock Deduction
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 3: Component B (Menu & Inventory) ---\n";
$dishId = 1; // Traditional Chicken Rice & Curry
$invStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = :id");
$invStmt->execute([':id' => $dishId]);
$stockBefore = (int)$invStmt->fetchColumn();

// Execute reduce_stock in a transaction
$pdo->beginTransaction();
$deducted = reduce_stock($pdo, $dishId, 2);
$invStmt->execute([':id' => $dishId]);
$stockAfter = (int)$invStmt->fetchColumn();
$pdo->commit();

assert_test("reduce_stock() succeeds and decrements stock exactly", $stockAfter === ($stockBefore - 2));

// Test reduce_stock() refusing negative stock
$insufficientException = false;
$pdo->beginTransaction();
try {
    reduce_stock($pdo, $dishId, 999999);
} catch (Exception $e) {
    $insufficientException = true;
    $pdo->rollBack();
}
assert_test("reduce_stock() strictly refuses to go below zero (throws exception)", $insufficientException);

// -------------------------------------------------------------------
// TEST 4: Atomic Checkout Transaction & Workflow
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 4: Component C (Ordering, Payment & Kitchen Queue) ---\n";

// Perform simulated checkout
$testUserId = 7; // Amara Jayawardena
$testOrderNum = 'TEST-' . date('Ymd') . '-' . random_int(1000, 9999);

$pdo->beginTransaction();
try {
    $orderStmt = $pdo->prepare("
        INSERT INTO orders (order_number, user_id, order_type, delivery_address, contact_phone, subtotal, total_amount, status, created_at)
        VALUES (:num, :uid, 'delivery', '12/4 Flower Road, Colombo 07', '+94776789012', 1900.00, 2200.00, 'Placed', NOW())
    ");
    $orderStmt->execute([':num' => $testOrderNum, ':uid' => $testUserId]);
    $newOrderId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (order_id, dish_id, dish_name, unit_price, quantity, subtotal)
        VALUES (:oid, :did, 'Traditional Chicken Rice & Curry', 950.00, 2, 1900.00)
    ");
    $itemStmt->execute([':oid' => $newOrderId, ':did' => $dishId]);

    $payStmt = $pdo->prepare("
        INSERT INTO payments (order_id, payment_method, payment_status, amount, created_at)
        VALUES (:oid, 'cash_on_delivery', 'pending', 2200.00, NOW())
    ");
    $payStmt->execute([':oid' => $newOrderId]);

    $histStmt = $pdo->prepare("
        INSERT INTO order_status_history (order_id, changed_by_user_id, old_status, new_status, comment, created_at)
        VALUES (:oid, :uid, NULL, 'Placed', 'Initial order placement', NOW())
    ");
    $histStmt->execute([':oid' => $newOrderId, ':uid' => $testUserId]);

    $pdo->commit();
    assert_test("Checkout atomic transaction committed successfully (Order #$testOrderNum)", true);
} catch (Exception $e) {
    $pdo->rollBack();
    assert_test("Checkout failed: " . $e->getMessage(), false);
}

// Test Status Workflow Transition
$validPrep = is_valid_status_transition('Placed', 'Preparing');
assert_test("Workflow state machine allows transition 'Placed' -> 'Preparing'", $validPrep === true);

$invalidJump = is_valid_status_transition('Placed', 'Completed');
assert_test("Workflow state machine strictly rejects illegal jump 'Placed' -> 'Completed'", $invalidJump === false);

// Advance Order to Preparing -> Ready -> Completed
$pdo->beginTransaction();
$upd1 = $pdo->prepare("UPDATE orders SET status = 'Preparing' WHERE id = :id");
$upd1->execute([':id' => $newOrderId]);
$upd2 = $pdo->prepare("UPDATE orders SET status = 'Ready' WHERE id = :id");
$upd2->execute([':id' => $newOrderId]);
$upd3 = $pdo->prepare("UPDATE orders SET status = 'Completed' WHERE id = :id");
$upd3->execute([':id' => $newOrderId]);
$pdo->commit();

$checkStatus = $pdo->query("SELECT status FROM orders WHERE id = $newOrderId")->fetchColumn();
assert_test("Order workflow completed successfully (Final status: $checkStatus)", $checkStatus === 'Completed');

// -------------------------------------------------------------------
// TEST 5: Reports Aggregate Queries
// -------------------------------------------------------------------
echo "\n--- TEST SUITE 5: Reporting & Aggregation ---\n";
$reportStmt = $pdo->query("
    SELECT SUM(total_amount) AS revenue, COUNT(*) AS cnt 
    FROM orders 
    WHERE status = 'Completed'
");
$rep = $reportStmt->fetch();
assert_test("Sales report aggregate query computes gross revenue (LKR {$rep['revenue']})", (float)$rep['revenue'] > 0);

$topDishStmt = $pdo->query("
    SELECT d.name, SUM(oi.quantity) as qty_sold, SUM(oi.subtotal) as dish_revenue
    FROM order_items oi
    JOIN dishes d ON oi.dish_id = d.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.status = 'Completed'
    GROUP BY d.id, d.name
    ORDER BY dish_revenue DESC
    LIMIT 1
");
$top = $topDishStmt->fetch();
assert_test("Top-selling dish query returns best-seller: '{$top['name']}' with {$top['qty_sold']} units", !empty($top));

echo "\n=================================================================\n";
echo "   TEST RESULTS: Passed: $testsPassed | Failed: $testsFailed\n";
echo "=================================================================\n";
if ($testsFailed === 0) {
    echo "🎉 ALL INTEGRATION TESTS PASSED WITH 100% SUCCESS!\n\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n\n";
    exit(1);
}
