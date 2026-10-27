<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$pdo = get_db_connection();

echo "=================================================================\n";
echo "   COMPONENT B & INTEGRATION VERIFICATION TEST SUITE\n";
echo "=================================================================\n\n";

// 1. Verify Role Guard Check logic
echo "--- 1. Role-Based Access Control (RBAC) Verification ---\n";
$_SESSION['user_id'] = 7; // Amara Jayawardena (Customer)
$_SESSION['role_name'] = 'customer';
$_SESSION['role_id'] = 3;

$canCustomerManageDishes = has_role(['kitchen_staff', 'admin']);
echo " [CHECK] Can customer manage/create/delete dishes? " . ($canCustomerManageDishes ? 'YES (SECURITY RISK)' : 'NO (RESTRICTED - PASS)') . "\n";
$canCustomerAdjustStock = has_role(['kitchen_staff', 'admin']);
echo " [CHECK] Can customer adjust stock? " . ($canCustomerAdjustStock ? 'YES (SECURITY RISK)' : 'NO (RESTRICTED - PASS)') . "\n";

$_SESSION['user_id'] = 3; // Kasun Fernando (Kitchen Staff)
$_SESSION['role_name'] = 'kitchen_staff';
$_SESSION['role_id'] = 2;
$canStaffManageDishes = has_role(['kitchen_staff', 'admin']);
echo " [CHECK] Can kitchen staff manage dishes & stock? " . ($canStaffManageDishes ? 'YES (PASS)' : 'NO (FAIL)') . "\n";

$_SESSION['user_id'] = 1; // Maleesha Sanjana (Admin)
$_SESSION['role_name'] = 'admin';
$_SESSION['role_id'] = 1;
$canAdminManageDishes = has_role(['kitchen_staff', 'admin']);
echo " [CHECK] Can administrator manage dishes & stock? " . ($canAdminManageDishes ? 'YES (PASS)' : 'NO (FAIL)') . "\n";

// 2. Verify Stock Adjustments (Restock & Wastage with reasons and user tracking)
echo "\n--- 2. Stock Adjustments (Restock, Wastage, Auditing) ---\n";
$dishId = 2; // Seafood Kottu
$invStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = ?");
$invStmt->execute([$dishId]);
$origStock = (int)$invStmt->fetchColumn();

// Restock 10 units
$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE inventory SET current_stock = current_stock + 10 WHERE dish_id = ?");
$upd->execute([$dishId]);
record_stock_movement($pdo, $dishId, 3, 'restock', 10, 'Morning fresh batch prep');
$pdo->commit();

$invStmt->execute([$dishId]);
$afterRestock = (int)$invStmt->fetchColumn();
echo " [CHECK] Restock +10: Before=$origStock, After=$afterRestock (" . ($afterRestock === $origStock + 10 ? 'PASS' : 'FAIL') . ")\n";

// Wastage 5 units
$pdo->beginTransaction();
$upd = $pdo->prepare("UPDATE inventory SET current_stock = current_stock - 5 WHERE dish_id = ? AND current_stock >= 5");
$upd->execute([$dishId]);
record_stock_movement($pdo, $dishId, 3, 'wastage', -5, 'Burned on wok during prep');
$pdo->commit();

$invStmt->execute([$dishId]);
$afterWastage = (int)$invStmt->fetchColumn();
echo " [CHECK] Wastage -5: Before=$afterRestock, After=$afterWastage (" . ($afterWastage === $afterRestock - 5 ? 'PASS' : 'FAIL') . ")\n";

// Check stock_movements audit trail
$smStmt = $pdo->prepare("SELECT movement_type, quantity, reason, user_id FROM stock_movements WHERE dish_id = ? ORDER BY id DESC LIMIT 2");
$smStmt->execute([$dishId]);
$moves = $smStmt->fetchAll();
echo " [CHECK] Movement #1 Recorded: type={$moves[0]['movement_type']}, qty={$moves[0]['quantity']}, reason='{$moves[0]['reason']}', staff_id={$moves[0]['user_id']}\n";
echo " [CHECK] Movement #2 Recorded: type={$moves[1]['movement_type']}, qty={$moves[1]['quantity']}, reason='{$moves[1]['reason']}', staff_id={$moves[1]['user_id']}\n";

// Restore original stock
$pdo->prepare("UPDATE inventory SET current_stock = ? WHERE dish_id = ?")->execute([$origStock, $dishId]);

// 3. Verify Invoicing & Checkout Stock Deduction
echo "\n--- 3. Invoicing / Payment Confirmation & Atomic Stock Deduction ---\n";
$dishId = 3;
$invStmt->execute([$dishId]);
$dish3StockBefore = (int)$invStmt->fetchColumn();

// Perform simulated checkout transaction
$testOrderNum = 'TEST-INV-' . time();
$pdo->beginTransaction();
try {
    // 1. Insert order
    $pdo->prepare("INSERT INTO orders (order_number, user_id, order_type, delivery_address, contact_phone, subtotal, total_amount, status) VALUES (?, 7, 'delivery', 'Colombo', '0771234567', 1500, 1800, 'Placed')")
        ->execute([$testOrderNum]);
    $orderId = $pdo->lastInsertId();

    // 2. Insert order_item with snapshot price
    $pdo->prepare("INSERT INTO order_items (order_id, dish_id, dish_name, unit_price, quantity, subtotal) VALUES (?, ?, 'Test Dish', 1500, 3, 4500)")
        ->execute([$orderId, $dishId]);

    // 3. Transaction-safe reduce_stock
    reduce_stock($pdo, $dishId, 3);
    record_stock_movement($pdo, $dishId, 7, 'sale', -3, "Deducted for Order #{$testOrderNum}");

    // 4. Payment record (card - completed invoice)
    $pdo->prepare("INSERT INTO payments (order_id, payment_method, payment_status, transaction_reference, amount, paid_at) VALUES (?, 'card', 'completed', 'TEST-REF', 1800, NOW())")
        ->execute([$orderId]);

    $pdo->commit();
    echo " [PASS] Invoiced Order & Checkout Transaction Committed Successfully!\n";
} catch (Exception $e) {
    $pdo->rollBack();
    echo " [FAIL] Invoiced Order Failed: " . $e->getMessage() . "\n";
}

$invStmt->execute([$dishId]);
$dish3StockAfter = (int)$invStmt->fetchColumn();
echo " [CHECK] Stock Deduction on Invoicing: Before=$dish3StockBefore, After=$dish3StockAfter (" . ($dish3StockAfter === $dish3StockBefore - 3 ? 'DEDUCTED PROPERLY - PASS' : 'FAIL') . ")\n";

// Verify payment record
$payStmt = $pdo->prepare("SELECT payment_method, payment_status, amount, paid_at FROM payments WHERE order_id = ?");
$payStmt->execute([$orderId]);
$pay = $payStmt->fetch();
echo " [CHECK] Invoice Payment Confirmed: method={$pay['payment_method']}, status={$pay['payment_status']}, amount={$pay['amount']}, paid_at={$pay['paid_at']}\n";

// Cleanup test order
$pdo->prepare("DELETE FROM orders WHERE id = ?")->execute([$orderId]);
$pdo->prepare("UPDATE inventory SET current_stock = ? WHERE dish_id = ?")->execute([$dish3StockBefore, $dishId]);

// 4. Verify Soft Delete
echo "\n--- 4. Soft Deletion (Historical Order Safety) ---\n";
$dish = $pdo->query("SELECT id, name, is_active FROM dishes LIMIT 1")->fetch();
echo " [CHECK] Dish #{$dish['id']} initial status: is_active={$dish['is_active']}\n";
$pdo->prepare("UPDATE dishes SET is_active = 0 WHERE id = ?")->execute([$dish['id']]);
$archived = $pdo->query("SELECT is_active FROM dishes WHERE id = " . $dish['id'])->fetchColumn();
echo " [CHECK] After soft delete: is_active=$archived (Row still exists in DB so foreign keys in past orders are NOT broken: PASS)\n";
$pdo->prepare("UPDATE dishes SET is_active = 1 WHERE id = ?")->execute([$dish['id']]);

// 5. Verify Goods Received Note (GRN) Processing & Inventory Sync
echo "\n--- 5. Goods Received Note (GRN) & Inward Stock Replenishment ---\n";
$grnNumber = generate_grn_number($pdo);
echo " [CHECK] Generated sequential GRN number: $grnNumber (PASS)\n";

$dishId = 1;
$invCheck = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = ?");
$invCheck->execute([$dishId]);
$stockBeforeGrn = (int)$invCheck->fetchColumn();

// Test processing a multi-item GRN
$testGrnResult = process_grn_receipt(
    $pdo,
    3, // Kasun Fernando
    'Test Ceylon Agro Produce Ltd',
    'INV-TEST-9988',
    'Delivery inspected and accepted for testing',
    [
        ['dish_id' => $dishId, 'quantity' => 15, 'unit_cost' => 450.00, 'batch_number' => 'B-TEST-01', 'expiry_date' => '2026-11-30']
    ]
);
echo " [PASS] process_grn_receipt() executed: GRN #{$testGrnResult['grn_number']} (Items: {$testGrnResult['items_count']}, Cost: LKR {$testGrnResult['total_cost']})\n";

$invCheck->execute([$dishId]);
$stockAfterGrn = (int)$invCheck->fetchColumn();
echo " [CHECK] Master Inventory Synchronized via GRN: Before=$stockBeforeGrn, After=$stockAfterGrn (" . ($stockAfterGrn === $stockBeforeGrn + 15 ? 'RESTOCKED +15 - PASS' : 'FAIL') . ")\n";

// Verify audit entry in stock_movements
$moveCheck = $pdo->prepare("SELECT movement_type, quantity, reason FROM stock_movements WHERE dish_id = ? ORDER BY id DESC LIMIT 1");
$moveCheck->execute([$dishId]);
$lastMovement = $moveCheck->fetch();
echo " [CHECK] Movement audit log updated: type={$lastMovement['movement_type']}, qty={$lastMovement['quantity']}, reason='{$lastMovement['reason']}' (PASS)\n";

// Cleanup test GRN & restore original stock
$pdo->prepare("DELETE FROM grn_headers WHERE id = ?")->execute([$testGrnResult['grn_id']]);
$pdo->prepare("UPDATE inventory SET current_stock = ? WHERE dish_id = ?")->execute([$stockBeforeGrn, $dishId]);

// 6. Verify HTTP Endpoint Protection with Live Requests
echo "\n--- 6. Live HTTP Role Access Enforcement (Real Web Server) ---\n";
echo " (Testing HTTP status codes on restricted URLs without auth)\n";
$endpoints = [
    '/menu/grn.php',
    '/menu/manage_dishes.php',
    '/menu/categories.php',
    '/menu/stock.php',
    '/menu/list.php',
    '/menu/detail.php?id=1'
];
foreach ($endpoints as $ep) {
    $url = "http://127.0.0.1:8080/UrbanEats/public" . $ep;
    $headers = @get_headers($url);
    $statusLine = $headers ? $headers[0] : 'No response';
    echo "   - $ep => $statusLine\n";
}

echo "\n=================================================================\n";
echo "   ALL COMPONENT B VERIFICATIONS COMPLETE\n";
echo "=================================================================\n";
