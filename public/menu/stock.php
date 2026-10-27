<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Inventory & Stock Control Dashboard
 * Student 2: Denuka (Component B Owner)
 *
 * Key Responsibilities:
 * - Real-time kitchen inventory tracking with row-level locks
 * - Automated low-stock and depleted ingredient alerts
 * - Direct integration with official Goods Received Note (GRN) procurement system
 * - Formal Stock Adjustment Notes (SAN) module for wastage, spillage, expiration, and count reconciliation
 * - Audited stock movements history (linked with GRN inward receipts, SAN vouchers, and Component C sales)
 */

$currentPage = 'stock';
$pageTitle = 'Stock & Inventory - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Restricted to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUserId = (int)$_SESSION['user_id'];

// 1. Handle Formal Stock Adjustment Notes (SAN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $dishId   = (int)($_POST['dish_id'] ?? 0);
    $quantity = abs((int)($_POST['quantity'] ?? 0));

    // Direction: support direct 'direction' or map from legacy 'movement_type'
    if (isset($_POST['direction'])) {
        $direction = in_array($_POST['direction'], ['increase', 'decrease'], true) ? $_POST['direction'] : 'decrease';
    } elseif (isset($_POST['movement_type'])) {
        $direction = ($_POST['movement_type'] === 'restock') ? 'increase' : 'decrease';
    } else {
        $direction = 'decrease';
    }

    // Adjustment type / reason classification
    if (!empty($_POST['adjustment_type'])) {
        $adjustmentType = trim($_POST['adjustment_type']);
    } elseif (!empty($_POST['movement_type'])) {
        $adjustmentType = match ($_POST['movement_type']) {
            'restock'    => 'count_overage',
            'adjustment' => 'count_shortage',
            default      => 'wastage'
        };
    } else {
        $adjustmentType = 'wastage';
    }

    $rootCause        = trim($_POST['root_cause'] ?? $_POST['reason'] ?? '');
    $correctiveAction = trim($_POST['corrective_action'] ?? '');

    // Server-side input validation
    if ($dishId <= 0 || $quantity <= 0 || $rootCause === '') {
        set_flash('error', 'Please provide a valid dish, positive adjustment quantity, and documented root cause.');
    } else {
        try {
            $sanResult = process_stock_adjustment_note(
                $pdo,
                $currentUserId,
                $dishId,
                $adjustmentType,
                $direction,
                $quantity,
                $rootCause,
                $correctiveAction ?: "Issued via Kitchen Stock Dashboard"
            );

            $dirWord = ($direction === 'increase') ? 'credited (+)' : 'deducted (-)';
            set_flash('success', "Stock Adjustment Note #{$sanResult['san_number']} issued! {$quantity} units {$dirWord} for '{$sanResult['dish_name']}'. Verified balance: {$sanResult['stock_before']} → {$sanResult['stock_after']} units.");
            header("Location: {$appBase}/menu/adjustment_notes.php?view_id=" . $sanResult['san_id']);
            exit;
        } catch (Exception $e) {
            set_flash('error', 'Stock Adjustment Failed: ' . $e->getMessage());
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

$nextSanPreview = generate_san_number($pdo);
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Inventory Control
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Live Stock & Inventory Dashboard</h1>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-primary" style="background: #16a34a; border-color: #16a34a;">
                📥 Goods Received Notes (GRN)
            </a>
            <a href="<?= $appBase ?>/menu/adjustment_notes.php" class="btn btn-secondary">
                📝 Stock Adjustment Notes (SAN)
            </a>
            <a href="<?= $appBase ?>/menu/suppliers.php" class="btn btn-outline">
                🚚 Supplier Master
            </a>
            <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-outline">
                🍲 Catalogue
            </a>
        </div>
    </div>

    <!-- Official GRN Replenishment Banner Callout -->
    <div style="background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%); border: 1px solid #86efac; border-radius: var(--radius-lg); padding: 1.5rem; margin-bottom: 2.5rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1.25rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; gap: 1rem; max-width: 780px;">
            <span style="font-size: 2.5rem;">📋</span>
            <div>
                <h3 style="font-size: 1.15rem; color: #166534; margin: 0 0 0.25rem 0;">Formal Inward Deliveries (Goods Received Notes)</h3>
                <p style="font-size: 0.875rem; color: #15803d; margin: 0; line-height: 1.5;">
                    To maintain strict accounting compliance and stock traceability, all supplier deliveries must be received via an official <strong>Goods Received Note (GRN)</strong> documenting supplier invoices, unit purchase costs, and batch numbers.
                </p>
            </div>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-primary" style="background: #16a34a;">
                ➕ Create Goods Received Note (GRN)
            </a>
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
                        Dishes below their reorder threshold must be replenished via GRN promptly to prevent checkout failures.
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

    <!-- Unified Stock Adjustment Note (SAN) Module -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 2.5rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <span style="font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                    Official Inventory Discrepancy & Wastage Control
                </span>
                <h2 style="font-size: 1.35rem; margin: 0.25rem 0 0 0; color: var(--color-secondary);">
                    Issue Stock Adjustment Note (SAN)
                </h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">
                    Document kitchen prep spoilage, station spillage, expiration, or physical stocktake reconciliations
                </span>
            </div>
            <div style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
                <div style="font-size: 0.85rem; font-weight: 700; color: var(--color-primary); background: var(--bg-page); padding: 0.5rem 0.85rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    Voucher #: <code><?= $nextSanPreview ?></code>
                </div>
                <a href="<?= $appBase ?>/menu/adjustment_notes.php" class="btn btn-outline" style="font-size: 0.85rem;">
                    📋 All SAN Vouchers & Audit Trail &rarr;
                </a>
            </div>
        </div>

        <form action="<?= $appBase ?>/menu/stock.php" method="POST" id="stockSanForm">
            <?= csrf_field() ?>

            <div class="form-row">
                <!-- Dish Selection -->
                <div class="form-group" style="flex: 2;">
                    <label for="san_dish_id" class="form-label">Select Dish / Stock Item <span class="required">*</span></label>
                    <select id="san_dish_id" name="dish_id" class="form-control" required onchange="handleStockDishChange()">
                        <option value="">-- Choose Menu Dish --</option>
                        <?php foreach ($inventoryList as $invItem): ?>
                            <option value="<?= $invItem['id'] ?>" data-stock="<?= (int)$invItem['current_stock'] ?>" data-name="<?= e($invItem['name']) ?>" data-category="<?= e($invItem['category_name']) ?>">
                                <?= e($invItem['name']) ?> (Current Stock: <?= (int)$invItem['current_stock'] ?> units) [<?= e($invItem['category_name']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Adjustment Direction -->
                <div class="form-group" style="flex: 1.2;">
                    <label for="san_direction" class="form-label">Adjustment Direction <span class="required">*</span></label>
                    <select id="san_direction" name="direction" class="form-control" required onchange="handleStockDirectionChange()">
                        <option value="decrease" selected>🔴 Deduction / Wastage (Reduce Stock)</option>
                        <option value="increase">🟢 Count Overage / Credit (Add Stock)</option>
                    </select>
                </div>

                <!-- Adjustment Category Type -->
                <div class="form-group" style="flex: 1.5;">
                    <label for="san_type" class="form-label">Reason Classification <span class="required">*</span></label>
                    <select id="san_type" name="adjustment_type" class="form-control" required>
                        <option value="wastage" selected>Kitchen Prep Spoilage / Damage</option>
                        <option value="spillage">Cooking Station Spillage / Dropped</option>
                        <option value="expired">Passed Shelf Life / Cold Chain Expiry</option>
                        <option value="count_shortage">Physical Stocktake Shortage</option>
                        <option value="internal_sampling">Head Chef Sensory Tasting / QA Audit</option>
                        <option value="count_overage" style="display: none;">Physical Stocktake Overage Found</option>
                    </select>
                </div>
            </div>

            <!-- Dynamic Stock Calculation & Units Row -->
            <div style="background: var(--bg-page); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem;">
                <div class="form-row" style="margin-bottom: 0; align-items: center;">
                    <div class="form-group" style="flex: 1; margin-bottom: 0;">
                        <label for="san_quantity" class="form-label">Units to Adjust <span class="required">*</span></label>
                        <input type="number" id="san_quantity" name="quantity" min="1" class="form-control" placeholder="Enter units (e.g. 3)" required oninput="updateStockSanPreview()">
                    </div>
                    <div style="flex: 2; display: flex; align-items: center; justify-content: space-around; background: white; padding: 0.75rem 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div style="text-align: center;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Current Ledger Stock</span>
                            <div id="previewCurrentStock" style="font-size: 1.25rem; font-weight: 800; color: #475569;">— units</div>
                        </div>
                        <div id="previewOperator" style="font-size: 1.5rem; font-weight: 800; color: #dc2626;">−</div>
                        <div style="text-align: center;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Adjustment Qty</span>
                            <div id="previewAdjUnits" style="font-size: 1.25rem; font-weight: 800; color: #dc2626;">0 units</div>
                        </div>
                        <div style="font-size: 1.5rem; font-weight: 800; color: var(--text-muted);">=</div>
                        <div style="text-align: center;">
                            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Resulting Stock</span>
                            <div id="previewResultingStock" style="font-size: 1.35rem; font-weight: 800; color: #0284c7;">— units</div>
                        </div>
                    </div>
                </div>
                <div id="stockWarning" style="display: none; margin-top: 0.75rem; color: #dc2626; font-size: 0.85rem; font-weight: 600;">
                    ⚠️ Warning: Adjustment quantity exceeds available stock! Stock cannot drop below zero.
                </div>
            </div>

            <!-- Root Cause & Corrective Action Inputs -->
            <div class="form-row">
                <div class="form-group" style="flex: 1.5;">
                    <label for="san_root_cause" class="form-label">Documented Root Cause & Circumstances <span class="required">*</span></label>
                    <textarea id="san_root_cause" name="root_cause" rows="2" class="form-control" placeholder="Provide factual explanation: e.g. Dropped tray during lunch rush / Refrigerator 2 thermostat fluctuated / Count discrepancy on closing audit." required></textarea>
                    <p class="form-hint">Mandatory for supervisory sign-off and food safety compliance.</p>
                </div>

                <div class="form-group" style="flex: 1.5;">
                    <label for="san_corrective_action" class="form-label">Corrective & Preventative Action Taken</label>
                    <textarea id="san_corrective_action" name="corrective_action" rows="2" class="form-control" placeholder="e.g. Fridge thermostat recalibrated / Staff retrained on handling protocol / Disposed per hygiene guidelines."></textarea>
                    <p class="form-hint">Preventative measures taken to prevent recurring wastage.</p>
                </div>
            </div>

            <div style="display: flex; gap: 1rem; align-items: center; flex-wrap: wrap;">
                <button type="submit" class="btn btn-primary btn-lg" id="sanSubmitBtn">
                    📝 Issue & Post Stock Adjustment Note
                </button>
                <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-outline btn-lg" style="color: #16a34a; border-color: #16a34a;">
                    📥 Need Supplier Restock? Create GRN &rarr;
                </a>
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
                    <th>Customer Price</th>
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
            Audited trail of inward supplier GRNs, automated checkout sales deductions, and formal Stock Adjustment Notes (SAN).
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

<script>
let currentSelectedStock = 0;

function handleStockDishChange() {
    const dishSelect = document.getElementById('san_dish_id');
    const selectedOpt = dishSelect.options[dishSelect.selectedIndex];
    const previewCurrent = document.getElementById('previewCurrentStock');

    if (dishSelect.value && selectedOpt && selectedOpt.dataset.stock !== undefined) {
        currentSelectedStock = parseInt(selectedOpt.dataset.stock) || 0;
        previewCurrent.textContent = currentSelectedStock + ' units';
    } else {
        currentSelectedStock = 0;
        previewCurrent.textContent = '— units';
    }
    updateStockSanPreview();
}

function handleStockDirectionChange() {
    const dir = document.getElementById('san_direction').value;
    const typeSelect = document.getElementById('san_type');
    const previewOp = document.getElementById('previewOperator');
    const previewUnits = document.getElementById('previewAdjUnits');

    if (dir === 'increase') {
        previewOp.textContent = '+';
        previewOp.style.color = '#16a34a';
        previewUnits.style.color = '#16a34a';

        // Filter types for increase
        typeSelect.value = 'count_overage';
        for (let opt of typeSelect.options) {
            opt.style.display = (opt.value === 'count_overage') ? 'block' : 'none';
        }
    } else {
        previewOp.textContent = '−';
        previewOp.style.color = '#dc2626';
        previewUnits.style.color = '#dc2626';

        // Filter types for decrease
        typeSelect.value = 'wastage';
        for (let opt of typeSelect.options) {
            opt.style.display = (opt.value === 'count_overage') ? 'none' : 'block';
        }
    }
    updateStockSanPreview();
}

function updateStockSanPreview() {
    const qtyInput = document.getElementById('san_quantity');
    const qty = parseInt(qtyInput.value) || 0;
    const dir = document.getElementById('san_direction').value;
    const warning = document.getElementById('stockWarning');
    const submitBtn = document.getElementById('sanSubmitBtn');
    const previewUnits = document.getElementById('previewAdjUnits');
    const previewResult = document.getElementById('previewResultingStock');

    previewUnits.textContent = qty + ' units';

    if (!document.getElementById('san_dish_id').value) {
        previewResult.textContent = '— units';
        warning.style.display = 'none';
        submitBtn.removeAttribute('disabled');
        return;
    }

    let resulting = 0;
    if (dir === 'decrease') {
        resulting = currentSelectedStock - qty;
        if (resulting < 0) {
            warning.style.display = 'block';
            submitBtn.setAttribute('disabled', 'disabled');
            previewResult.textContent = resulting + ' units (Invalid)';
            previewResult.style.color = '#dc2626';
            return;
        } else {
            warning.style.display = 'none';
            submitBtn.removeAttribute('disabled');
            previewResult.style.color = '#0284c7';
        }
    } else {
        resulting = currentSelectedStock + qty;
        warning.style.display = 'none';
        submitBtn.removeAttribute('disabled');
        previewResult.style.color = '#0284c7';
    }

    previewResult.textContent = resulting + ' units';
}

document.addEventListener('DOMContentLoaded', function() {
    handleStockDirectionChange();
    handleStockDishChange();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
