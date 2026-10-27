<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Stock Adjustment Notes (SAN) Management
 * Student 2: Denuka (Component B Owner)
 *
 * Professional Inventory Adjustment & Wastage Voucher System:
 * - Formal, documented Stock Adjustment Notes replacing informal inventory edits
 * - Supports both stock deductions (wastage, spillage, expired, shortage, QA tasting) and additions (count overage)
 * - Atomic transaction with row-level locks (SELECT ... FOR UPDATE) preventing negative stock balances
 * - Complete audit trail in stock_adjustment_notes, inventory, stock_movements, and audit_log
 * - Printable formal SAN voucher with supervisory sign-off blocks
 */

$currentPage = 'stock';
$pageTitle = 'Stock Adjustment Notes (SAN) - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Restricted to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// 1. Fetch all active dishes with current stock for the SAN form
$dishesStmt = $pdo->query("
    SELECT d.id, d.name, d.price, c.name AS category_name, COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.is_active = 1
    ORDER BY d.name ASC
");
$allDishes = $dishesStmt->fetchAll();

// 2. Handle POST Request to create a new Stock Adjustment Note
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $action           = $_POST['action'] ?? '';
    $dishId           = (int)($_POST['dish_id'] ?? 0);
    $direction        = in_array($_POST['direction'] ?? '', ['increase', 'decrease'], true) ? $_POST['direction'] : 'decrease';
    $adjustmentType   = trim($_POST['adjustment_type'] ?? '');
    $quantity         = abs((int)($_POST['quantity'] ?? 0));
    $rootCause        = trim($_POST['root_cause'] ?? '');
    $correctiveAction = trim($_POST['corrective_action'] ?? '');

    if ($action === 'create_san') {
        if ($dishId <= 0 || $quantity <= 0 || $rootCause === '') {
            set_flash('error', 'Please select a dish, positive adjustment quantity, and provide a documented root cause.');
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
                    $correctiveAction ?: null
                );

                $dirLabel = ($direction === 'increase') ? 'credited (+)' : 'deducted (-)';
                set_flash('success', "Stock Adjustment Note #{$sanResult['san_number']} issued successfully! {$quantity} units {$dirLabel} for '{$sanResult['dish_name']}'. Stock balance updated: {$sanResult['stock_before']} → {$sanResult['stock_after']}.");
                header("Location: {$appBase}/menu/adjustment_notes.php?view_id=" . $sanResult['san_id']);
                exit;
            } catch (Exception $e) {
                set_flash('error', 'Stock Adjustment Failed: ' . $e->getMessage());
                header("Location: {$appBase}/menu/adjustment_notes.php");
                exit;
            }
        }
    }
}

// 3. Fetch specific SAN for Voucher / Print View if ?view_id is specified
$viewSan = null;
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $sanStmt = $pdo->prepare("
        SELECT san.*, d.name AS dish_name, d.price AS dish_price, c.name AS category_name,
               u.full_name AS adjusted_by_name, r.name AS role_name
        FROM stock_adjustment_notes san
        JOIN dishes d ON san.dish_id = d.id
        JOIN categories c ON d.category_id = c.id
        JOIN users u ON san.adjusted_by_user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        WHERE san.id = :id
        LIMIT 1
    ");
    $sanStmt->execute([':id' => $viewId]);
    $viewSan = $sanStmt->fetch();
}

// 4. Fetch recent Stock Adjustment Notes for the audit history log
$recentSansStmt = $pdo->query("
    SELECT san.*, d.name AS dish_name, c.name AS category_name, u.full_name AS adjusted_by_name
    FROM stock_adjustment_notes san
    JOIN dishes d ON san.dish_id = d.id
    JOIN categories c ON d.category_id = c.id
    JOIN users u ON san.adjusted_by_user_id = u.id
    ORDER BY san.created_at DESC, san.id DESC
    LIMIT 25
");
$recentSans = $recentSansStmt->fetchAll();

$nextSanNumberPreview = generate_san_number($pdo);
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Inventory Discrepancy & Wastage Control
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Stock Adjustment Notes (SAN)</h1>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                Formal voucher documentation for kitchen prep wastage, spillage, expiration, and stocktake count reconciliations.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-primary">📥 Goods Received Notes (GRN)</a>
            <a href="<?= $appBase ?>/menu/suppliers.php" class="btn btn-secondary">🚚 Supplier Master</a>
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline">📦 Inventory Dashboard</a>
        </div>
    </div>

    <!-- SAN Voucher / Printable Document View (Shown when ?view_id is set) -->
    <?php if ($viewSan): ?>
        <?php
        $isDecrease = ($viewSan['direction'] === 'decrease');
        $accentColor = $isDecrease ? '#dc2626' : '#16a34a';
        $bgBadgeColor = $isDecrease ? '#fee2e2' : '#dcfce7';
        $textBadgeColor = $isDecrease ? '#991b1b' : '#15803d';
        ?>
        <div style="background: white; border: 2px solid <?= $accentColor ?>; border-radius: var(--radius-lg); padding: 2.5rem; margin-bottom: 3rem; box-shadow: var(--shadow-md);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px dashed var(--border-color); padding-bottom: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                        <span style="display: inline-block; background: <?= $bgBadgeColor ?>; color: <?= $textBadgeColor ?>; font-size: 0.75rem; font-weight: 800; padding: 0.35rem 0.75rem; border-radius: 999px; text-transform: uppercase;">
                            <?= $isDecrease ? '🔴 Stock Reduction / Wastage' : '🟢 Stock Overage / Inward Correction' ?>
                        </span>
                        <span style="display: inline-block; background: #f1f5f9; color: #475569; font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.65rem; border-radius: 999px; text-transform: uppercase;">
                            Category: <?= strtoupper(str_replace('_', ' ', $viewSan['adjustment_type'])) ?>
                        </span>
                    </div>
                    <h2 style="font-size: 1.85rem; margin: 0 0 0.25rem 0; color: var(--color-secondary);">Stock Adjustment Note Voucher</h2>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin: 0;">UrbanEats Cloud Kitchen Platform • Colombo Central Kitchen Hub</p>
                </div>
                <div style="text-align: right;">
                    <div style="font-family: var(--font-heading); font-size: 1.6rem; font-weight: 800; color: <?= $accentColor ?>;">
                        <?= e($viewSan['san_number']) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Issue Date: <strong><?= format_datetime($viewSan['created_at']) ?></strong>
                    </div>
                    <button type="button" class="btn btn-outline-sm" style="margin-top: 0.5rem;" onclick="window.print();">
                        🖨️ Print SAN Voucher
                    </button>
                </div>
            </div>

            <!-- Key Adjustment Snapshot Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; margin-bottom: 2rem; background: var(--bg-page); padding: 1.25rem; border-radius: var(--radius-md);">
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Dish / Inventory Item:</span>
                    <div style="font-size: 1.15rem; font-weight: 800; color: var(--color-secondary); margin-top: 0.25rem;">
                        <?= e($viewSan['dish_name']) ?>
                    </div>
                    <span style="font-size: 0.8rem; color: var(--text-muted);"><?= e($viewSan['category_name']) ?></span>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Opening Stock (Before):</span>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #475569; margin-top: 0.25rem;">
                        <?= (int)$viewSan['stock_before'] ?> units
                    </div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Adjusted Units:</span>
                    <div style="font-size: 1.25rem; font-weight: 800; color: <?= $accentColor ?>; margin-top: 0.25rem;">
                        <?= $isDecrease ? '-' : '+' ?><?= (int)$viewSan['quantity'] ?> units
                    </div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Closing Stock (Verified):</span>
                    <div style="font-size: 1.25rem; font-weight: 800; color: #0284c7; margin-top: 0.25rem;">
                        <?= (int)$viewSan['stock_after'] ?> units
                    </div>
                </div>
            </div>

            <!-- Root Cause & Corrective Action Details -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem; margin-bottom: 2rem;">
                <div style="background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; font-weight: 800; color: #b91c1c; margin-bottom: 0.5rem;">
                        🔍 Root Cause & Circumstances
                    </div>
                    <p style="margin: 0; font-size: 0.95rem; color: #1e293b; line-height: 1.5;">
                        <?= e($viewSan['root_cause']) ?>
                    </p>
                </div>
                <div style="background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem;">
                    <div style="font-size: 0.8rem; text-transform: uppercase; font-weight: 800; color: #15803d; margin-bottom: 0.5rem;">
                        🛡️ Corrective Action / Prevention Protocol
                    </div>
                    <p style="margin: 0; font-size: 0.95rem; color: #1e293b; line-height: 1.5;">
                        <?= e($viewSan['corrective_action'] ?: 'Standard kitchen operating procedures re-emphasized.') ?>
                    </p>
                </div>
            </div>

            <!-- Formal Supervisory Sign-off Block -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 2rem; margin-top: 2.5rem; border-top: 1px solid var(--border-color); padding-top: 2rem;">
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Prepared & Logged By:</span>
                    <div style="font-size: 0.95rem; font-weight: 700; margin-top: 0.5rem;"><?= e($viewSan['adjusted_by_name']) ?></div>
                    <div style="font-size: 0.8rem; color: var(--text-muted);"><?= ucfirst($viewSan['role_name'] ?? 'Staff') ?></div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Kitchen Supervisor / Executive Chef:</span>
                    <div style="margin-top: 1.5rem; border-bottom: 1px solid #94a3b8; width: 80%;"></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">Signature & Date</div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Inventory Controller / Store Manager:</span>
                    <div style="margin-top: 1.5rem; border-bottom: 1px solid #94a3b8; width: 80%;"></div>
                    <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">Approval Signature & Stamp</div>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                <div style="font-size: 0.85rem; color: var(--text-muted);">
                    ✔ Inventory balances have been atomically adjusted and recorded in <code>stock_adjustment_notes</code> and <code>stock_movements</code>.
                </div>
                <a href="<?= $appBase ?>/menu/adjustment_notes.php" class="btn btn-secondary">Create Another SAN</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- SAN Creation Form Card -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 3rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">Issue Formal Stock Adjustment Note</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Document kitchen spillage, prep spoilage, shelf expiration, or count discrepancies</span>
            </div>
            <div style="font-size: 0.9rem; font-weight: 700; color: var(--color-primary); background: var(--bg-page); padding: 0.5rem 1rem; border-radius: var(--radius-md);">
                Generated Document #: <code><?= $nextSanNumberPreview ?></code>
            </div>
        </div>

        <form action="<?= $appBase ?>/menu/adjustment_notes.php" method="POST" id="sanForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_san">

            <div class="form-row">
                <!-- Dish Selection -->
                <div class="form-group" style="flex: 2;">
                    <label for="san_dish_id" class="form-label">Select Dish / Stock Item <span class="required">*</span></label>
                    <select id="san_dish_id" name="dish_id" class="form-control" required onchange="handleDishChange()">
                        <option value="">-- Choose Menu Dish --</option>
                        <?php foreach ($allDishes as $d): ?>
                            <option value="<?= $d['id'] ?>" data-stock="<?= (int)$d['current_stock'] ?>" data-name="<?= e($d['name']) ?>" data-category="<?= e($d['category_name']) ?>">
                                <?= e($d['name']) ?> (Current Stock: <?= (int)$d['current_stock'] ?> units) [<?= e($d['category_name']) ?>]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Adjustment Direction -->
                <div class="form-group" style="flex: 1.2;">
                    <label for="san_direction" class="form-label">Adjustment Direction <span class="required">*</span></label>
                    <select id="san_direction" name="direction" class="form-control" required onchange="handleDirectionChange()">
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
                        <input type="number" id="san_quantity" name="quantity" min="1" class="form-control" placeholder="Enter units (e.g. 3)" required oninput="updateStockPreview()">
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
            <div class="form-group">
                <label for="san_root_cause" class="form-label">Documented Root Cause & Circumstances <span class="required">*</span></label>
                <textarea id="san_root_cause" name="root_cause" rows="2" class="form-control" placeholder="Provide factual explanation: e.g. Dropped tray during lunch rush / Refrigerator 2 thermostat fluctuated above safe threshold / Portion pack damaged during transit." required></textarea>
                <p class="form-hint">Mandatory for supervisory sign-off and food safety compliance.</p>
            </div>

            <div class="form-group">
                <label for="san_corrective_action" class="form-label">Corrective & Preventative Action Taken</label>
                <textarea id="san_corrective_action" name="corrective_action" rows="2" class="form-control" placeholder="e.g. Fridge thermostat repaired and calibrated / Junior cooks retrained on tray transport protocol / Disposed per hygiene guidelines."></textarea>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary btn-lg" id="sanSubmitBtn">
                    📝 Issue & Post Stock Adjustment Note
                </button>
                <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Recent Stock Adjustment Notes Table -->
    <div style="margin-top: 3.5rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">Stock Adjustment Notes Audit Log</h2>
            <span style="font-size: 0.85rem; color: var(--text-muted);">Historical register of all formal stock deductions and reconciliations</span>
        </div>
        <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($recentSans) ?> Notes Logged</span>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>SAN Voucher #</th>
                    <th>Date & Time</th>
                    <th>Dish / Item</th>
                    <th>Classification</th>
                    <th>Direction & Quantity</th>
                    <th>Stock Snapshot</th>
                    <th>Officer</th>
                    <th>Root Cause</th>
                    <th style="text-align: right;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentSans)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No Stock Adjustment Notes recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentSans as $san): ?>
                        <?php
                        $isDec = ($san['direction'] === 'decrease');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($san['san_number']) ?></strong>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">
                                <?= format_datetime($san['created_at']) ?>
                            </td>
                            <td>
                                <strong><?= e($san['dish_name']) ?></strong>
                                <span style="display: block; font-size: 0.75rem; color: var(--text-muted);"><?= e($san['category_name']) ?></span>
                            </td>
                            <td>
                                <span style="font-size: 0.75rem; font-weight: 700; background: #f1f5f9; padding: 0.2rem 0.5rem; border-radius: 999px;">
                                    <?= ucfirst(str_replace('_', ' ', $san['adjustment_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($isDec): ?>
                                    <strong style="color: #dc2626;">-<?= (int)$san['quantity'] ?> units</strong>
                                <?php else: ?>
                                    <strong style="color: #16a34a;">+<?= (int)$san['quantity'] ?> units</strong>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.85rem; font-family: monospace;">
                                <?= (int)$san['stock_before'] ?> → <strong><?= (int)$san['stock_after'] ?></strong>
                            </td>
                            <td><?= e($san['adjusted_by_name']) ?></td>
                            <td style="max-width: 250px; font-size: 0.85rem; color: #475569;" title="<?= e($san['root_cause']) ?>">
                                <?= e(mb_strimwidth($san['root_cause'], 0, 45, '...')) ?>
                            </td>
                            <td style="text-align: right;">
                                <a href="<?= $appBase ?>/menu/adjustment_notes.php?view_id=<?= $san['id'] ?>" class="btn btn-outline-sm">
                                    👁️ Voucher
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
let currentSelectedStock = 0;

function handleDishChange() {
    const dishSelect = document.getElementById('san_dish_id');
    const selectedOption = dishSelect.options[dishSelect.selectedIndex];
    if (dishSelect.value) {
        currentSelectedStock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
        document.getElementById('previewCurrentStock').textContent = currentSelectedStock + ' units';
    } else {
        currentSelectedStock = 0;
        document.getElementById('previewCurrentStock').textContent = '— units';
    }
    updateStockPreview();
}

function handleDirectionChange() {
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
    updateStockPreview();
}

function updateStockPreview() {
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
    handleDirectionChange();
    handleDishChange();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
