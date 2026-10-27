<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Inventory & Stock Control Dashboard
 * Student 2: Denuka (Component B Owner)
 *
 * Key Responsibilities:
 * - Real-time kitchen inventory tracking with row-level locks
 * - Automated low-stock and depleted ingredient alerts
 * - Staff inventory adjustments (restock batches, kitchen wastage, physical counts)
 * - Audited stock movements history (linked with Component C checkout sales deductions)
 */

$currentPage = 'stock';
$pageTitle = 'Stock & Inventory - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Restricted to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUserId = (int)$_SESSION['user_id'];

// 1. Handle Stock Adjustments (Restock batches or Kitchen Wastage)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $dishId       = (int)($_POST['dish_id'] ?? 0);
    $movementType = trim($_POST['movement_type'] ?? '');
    $quantity     = abs((int)($_POST['quantity'] ?? 0));
    $reason       = trim($_POST['reason'] ?? '');

    // Server-side input validation
    if ($dishId <= 0 || $quantity <= 0 || $reason === '') {
        set_flash('error', 'Please provide a valid dish, positive unit quantity, and operational reason.');
    } elseif (!in_array($movementType, ['restock', 'wastage', 'adjustment'], true)) {
        set_flash('error', 'Invalid movement type selected.');
    } else {
        // VIVA: Lock inventory row with FOR UPDATE inside a transaction to prevent race conditions
        $pdo->beginTransaction();
        try {
            $lockInvStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = :id FOR UPDATE");
            $lockInvStmt->execute([':id' => $dishId]);
            $currentStock = (int)$lockInvStmt->fetchColumn();

            // Calculate positive or negative delta
            $stockDelta = ($movementType === 'restock') ? $quantity : -$quantity;
            $updatedStockBalance = $currentStock + $stockDelta;

            // Prevent stock balance from ever dropping below zero
            if ($updatedStockBalance < 0) {
                throw new Exception("Wastage deduction exceeds available kitchen inventory ({$currentStock} units currently in stock).");
            }

            // Update master inventory level
            $updateInventoryStmt = $pdo->prepare("
                UPDATE inventory 
                SET current_stock = :new_stock, updated_at = NOW() 
                WHERE dish_id = :dish_id
            ");
            $updateInventoryStmt->execute([
                ':new_stock' => $updatedStockBalance, 
                ':dish_id'   => $dishId
            ]);

            // Insert detailed audit trail in stock_movements
            record_stock_movement(
                $pdo, 
                $dishId, 
                $currentUserId, 
                $movementType, 
                $stockDelta, 
                $reason
            );

            // Log high-level action to centralized system audit log
            log_action(
                $pdo,
                'STOCK_' . strtoupper($movementType),
                "Adjusted dish ID #{$dishId} by {$stockDelta} units. New balance: {$updatedStockBalance}. Reason: {$reason}",
                $currentUserId
            );

            $pdo->commit();
            set_flash('success', "Stock updated successfully! New balance: {$updatedStockBalance} units.");
        } catch (Exception $e) {
            $pdo->rollBack();
            set_flash('error', 'Inventory Adjustment Failed: ' . $e->getMessage());
        }
    }

    header("Location: {$appBase}/menu/stock.php");
    exit;
}

// 2. Fetch Low Stock and Depleted Items requiring kitchen attention
$lowStockQuery = "
    SELECT d.id, d.name, d.price, c.name AS category_name,
           COALESCE(i.current_stock, 0) AS current_stock,
           COALESCE(i.reorder_level, 10) AS reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.is_active = 1 AND COALESCE(i.current_stock, 0) <= COALESCE(i.reorder_level, 10)
    ORDER BY i.current_stock ASC
";
$criticalStockDishes = $pdo->query($lowStockQuery)->fetchAll();

// 3. Fetch Full Kitchen Inventory Roster
$fullCatalogueQuery = "
    SELECT d.id, d.name, d.price, c.name AS category_name, d.is_active,
           COALESCE(i.current_stock, 0) AS current_stock,
           COALESCE(i.reorder_level, 10) AS reorder_level,
           i.updated_at
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.is_active = 1
    ORDER BY d.name ASC
";
$inventoryList = $pdo->query($fullCatalogueQuery)->fetchAll();

// 4. Fetch Recent Stock Movement History (Last 15 audited transactions)
$movementHistoryQuery = "
    SELECT sm.*, d.name AS dish_name, u.full_name AS staff_name, r.name AS role_name
    FROM stock_movements sm
    JOIN dishes d ON sm.dish_id = d.id
    LEFT JOIN users u ON sm.user_id = u.id
    LEFT JOIN roles r ON u.role_id = r.id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 15
";
$movementsHistory = $pdo->query($movementHistoryQuery)->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Inventory Control
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Live Stock & Wastage Dashboard</h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-secondary">🍲 Catalogue Management</a>
            <a href="<?= $appBase ?>/orders/kitchen.php" class="btn btn-primary">🍳 Live Kitchen Orders</a>
        </div>
    </div>

    <!-- Critical Low Stock Alerts Box -->
    <?php if (!empty($criticalStockDishes)): ?>
        <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: var(--radius-lg); padding: 1.5rem; margin-bottom: 2.5rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 1rem;">
                <span style="font-size: 1.5rem;">⚠️</span>
                <div>
                    <h2 style="font-size: 1.15rem; color: #92400e; margin: 0;">
                        Attention Required: Low Stock & Depleted Items (<?= count($criticalStockDishes) ?>)
                    </h2>
                    <p style="font-size: 0.85rem; color: #b45309; margin: 0;">
                        Dishes below their reorder threshold must be replenished promptly to prevent checkout failures.
                    </p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                <?php foreach ($criticalStockDishes as $alertDish): ?>
                    <?php $isItemDepleted = ((int)$alertDish['current_stock'] === 0); ?>
                    <div style="background: white; border: 1px solid <?= $isItemDepleted ? '#fca5a5' : '#fde68a' ?>; border-radius: var(--radius-md); padding: 1rem; display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong><?= e($alertDish['name']) ?></strong>
                            <div style="font-size: 0.8rem; color: var(--text-muted);">
                                <?= e($alertDish['category_name']) ?> • Reorder Level: <?= $alertDish['reorder_level'] ?>
                            </div>
                            <span class="stock-pill <?= $isItemDepleted ? 'stock-out' : 'stock-low' ?>" style="position: static; margin-top: 0.35rem; display: inline-block;">
                                <?= $isItemDepleted ? 'OUT OF STOCK' : $alertDish['current_stock'] . ' UNITS LEFT' ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Quick Stock Action Form (Restock or Wastage) -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 2.5rem; box-shadow: var(--shadow-sm);">
        <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: var(--color-secondary);">
            Record Stock Movement (Restock / Wastage)
        </h2>
        
        <form action="<?= $appBase ?>/menu/stock.php" method="POST" class="form-row" style="align-items: flex-end;">
            <?= csrf_field() ?>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="dish_id" class="form-label">Select Dish <span class="required">*</span></label>
                <select id="dish_id" name="dish_id" class="form-control" required>
                    <option value="">-- Choose Dish --</option>
                    <?php foreach ($inventoryList as $invItem): ?>
                        <option value="<?= $invItem['id'] ?>">
                            <?= e($invItem['name']) ?> (Current: <?= $invItem['current_stock'] ?> units)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="movement_type" class="form-label">Movement Type <span class="required">*</span></label>
                <select id="movement_type" name="movement_type" class="form-control" required>
                    <option value="restock">➕ Restock (Add to Inventory)</option>
                    <option value="wastage">➖ Wastage (Damage / Spillage / Expired)</option>
                    <option value="adjustment">🔄 Physical Count Adjustment</option>
                </select>
            </div>

            <div class="form-group" style="margin-bottom: 0;">
                <label for="quantity" class="form-label">Quantity (Units) <span class="required">*</span></label>
                <input type="number" min="1" id="quantity" name="quantity" class="form-control" placeholder="e.g. 25" required>
            </div>

            <div class="form-group" style="margin-bottom: 0; flex: 1.5;">
                <label for="reason" class="form-label">Operational Reason <span class="required">*</span></label>
                <input type="text" id="reason" name="reason" class="form-control" placeholder="e.g. Morning kitchen prep batch / Spilled tray" required>
            </div>

            <div>
                <button type="submit" class="btn btn-primary">Save Adjustment</button>
            </div>
        </form>
    </div>

    <!-- Inventory Stock Table -->
    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.35rem; color: var(--color-secondary);">Real-Time Kitchen Inventory Table</h2>
        <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($inventoryList) ?> Dishes Tracked</span>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Dish Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Stock Health</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inventoryList as $invRow): ?>
                    <?php
                    $stockUnits = (int)$invRow['current_stock'];
                    $reorderUnits = (int)$invRow['reorder_level'];
                    ?>
                    <tr>
                        <td><strong><?= e($invRow['name']) ?></strong></td>
                        <td><?= e($invRow['category_name']) ?></td>
                        <td><?= format_price($invRow['price']) ?></td>
                        <td>
                            <strong style="font-size: 1rem;"><?= $stockUnits ?> units</strong>
                        </td>
                        <td><?= $reorderUnits ?> units</td>
                        <td>
                            <?php if ($stockUnits === 0): ?>
                                <span class="badge badge-inactive">Out of Stock</span>
                            <?php elseif ($stockUnits <= $reorderUnits): ?>
                                <span class="badge" style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a;">Low Stock</span>
                            <?php else: ?>
                                <span class="badge badge-active">Optimal</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-muted);">
                            <?= format_datetime($invRow['updated_at'], 'M d, g:i A') ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Stock Movements Audit History Table -->
    <div style="margin-top: 3rem; margin-bottom: 1rem;">
        <h2 style="font-size: 1.35rem; color: var(--color-secondary);">Recent Stock Movements History</h2>
        <p style="font-size: 0.85rem; color: var(--text-muted);">
            Audited trail of all manual restocks, automated checkout sales deductions, and wastage recordings.
        </p>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Dish Name</th>
                    <th>Type</th>
                    <th>Quantity Delta</th>
                    <th>Reason / Details</th>
                    <th>Staff Member</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($movementsHistory as $historyRow): ?>
                    <tr>
                        <td style="white-space: nowrap; font-size: 0.8rem; color: var(--text-muted);">
                            <?= format_datetime($historyRow['created_at']) ?>
                        </td>
                        <td><strong><?= e($historyRow['dish_name']) ?></strong></td>
                        <td>
                            <?php
                            $movementBadgeClasses = [
                                'restock'    => 'badge-active',
                                'sale'       => 'badge-role-customer',
                                'wastage'    => 'badge-inactive',
                                'adjustment' => 'badge-role-staff'
                            ];
                            ?>
                            <span class="badge <?= $movementBadgeClasses[$historyRow['movement_type']] ?? 'badge-active' ?>">
                                <?= ucfirst($historyRow['movement_type']) ?>
                            </span>
                        </td>
                        <td>
                            <strong style="color: <?= $historyRow['quantity'] > 0 ? '#16a34a' : '#dc2626' ?>;">
                                <?= $historyRow['quantity'] > 0 ? '+' . $historyRow['quantity'] : $historyRow['quantity'] ?> units
                            </strong>
                        </td>
                        <td style="font-size: 0.85rem; color: var(--color-secondary);"><?= e($historyRow['reason']) ?></td>
                        <td>
                            <?php if (!empty($historyRow['staff_name'])): ?>
                                <?= e($historyRow['staff_name']) ?> (<?= ucfirst($historyRow['role_name'] ?? '') ?>)
                            <?php else: ?>
                                <span style="color: var(--text-muted); font-style: italic;">Automated Checkout Engine</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
