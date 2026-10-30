<?php

$currentPage = 'stock';
$pageTitle = 'Goods Received Notes (GRN) - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Strictly limited to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// 1. Fetch active categories for the Quick Add Dish modal
$categoriesStmt = $pdo->query("SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC");
$allCategories = $categoriesStmt->fetchAll();

// 2. Fetch all active suppliers from Supplier Master (Supplier #1 is Internal Kitchen Store)
$suppliersStmt = $pdo->query("
    SELECT id, code, name, category, contact_person, phone 
    FROM suppliers 
    WHERE is_active = 1 
    ORDER BY id ASC
");
$allSuppliers = $suppliersStmt->fetchAll();

// 3. Fetch all active dishes for the GRN line item selector
$dishesStmt = $pdo->query("
    SELECT d.id, d.name, d.price, c.name AS category_name, COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.is_active = 1
    ORDER BY d.name ASC
");
$activeDishes = $dishesStmt->fetchAll();

// 4. Handle POST Actions (Create GRN, Quick Add Dish, Quick Add Supplier)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $action = $_POST['action'] ?? '';

    // Action A: Quick Add New Supplier to DB
    if ($action === 'quick_add_supplier') {
        $supName    = trim($_POST['new_supplier_name'] ?? '');
        $supCat     = in_array($_POST['new_supplier_cat'] ?? '', ['internal', 'external'], true) ? $_POST['new_supplier_cat'] : 'external';
        $supContact = trim($_POST['new_supplier_contact'] ?? '');
        $supPhone   = trim($_POST['new_supplier_phone'] ?? '');

        if ($supName === '') {
            set_flash('error', 'Supplier name is required.');
        } else {
            $prefix = 'SUP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $supName) ?: 'VEN', 0, 3));
            $code = $prefix . '-' . random_int(10, 99);
            try {
                $insSup = $pdo->prepare("
                    INSERT INTO suppliers (code, name, category, contact_person, phone, is_active, created_at)
                    VALUES (:code, :name, :cat, :contact, :phone, 1, NOW())
                ");
                $insSup->execute([
                    ':code'    => $code,
                    ':name'    => $supName,
                    ':cat'     => $supCat,
                    ':contact' => $supContact ?: null,
                    ':phone'   => $supPhone ?: null
                ]);
                $newSupId = (int)$pdo->lastInsertId();
                log_action($pdo, 'SUPPLIER_ADDED_VIA_GRN', "Quick registered new supplier '{$supName}' (Code: {$code})", $currentUserId);
                set_flash('success', "New supplier '{$supName}' registered! It is now selectable in the GRN form.");
            } catch (Exception $e) {
                set_flash('error', 'Failed to register supplier: ' . $e->getMessage());
            }
        }
        header("Location: {$appBase}/menu/grn.php");
        exit;
    }

    // Action B: Quick Add New Dish to DB if it does not exist yet
    if ($action === 'quick_add_dish') {
        $dishName     = trim($_POST['new_dish_name'] ?? '');
        $categoryId   = (int)($_POST['new_category_id'] ?? 0);
        $price        = (float)($_POST['new_dish_price'] ?? 0.0);
        $description  = trim($_POST['new_dish_desc'] ?? '');
        $reorderLevel = max(1, (int)($_POST['new_reorder_level'] ?? 10));

        if ($dishName === '' || $categoryId <= 0 || $price <= 0) {
            set_flash('error', 'Please provide a valid dish name, category, and selling price.');
        } else {
            try {
                $pdo->beginTransaction();
                
                $insertDish = $pdo->prepare("
                    INSERT INTO dishes (category_id, name, description, price, image_url, is_spicy, is_vegetarian, is_active, created_at)
                    VALUES (:cat_id, :name, :desc, :price, 'default_dish.jpg', 0, 0, 1, NOW())
                ");
                $insertDish->execute([
                    ':cat_id' => $categoryId,
                    ':name'   => $dishName,
                    ':desc'   => $description,
                    ':price'  => $price
                ]);
                $newId = (int)$pdo->lastInsertId();

                $insertInv = $pdo->prepare("
                    INSERT INTO inventory (dish_id, current_stock, reorder_level, updated_at)
                    VALUES (:dish_id, 0, :reorder, NOW())
                ");
                $insertInv->execute([':dish_id' => $newId, ':reorder' => $reorderLevel]);

                log_action($pdo, 'DISH_ADDED_VIA_GRN', "Quick registered new dish '{$dishName}' (ID: {$newId}) from GRN portal", $currentUserId);
                $pdo->commit();

                set_flash('success', "New dish '{$dishName}' registered in database! You can now select it in the GRN form.");
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash('error', 'Failed to register new dish: ' . $e->getMessage());
            }
        }
        header("Location: {$appBase}/menu/grn.php");
        exit;
    }

    // Action C: Save Official Goods Received Note (GRN) / Production Receipt
    if ($action === 'create_grn') {
        $supplierId = (int)($_POST['supplier_id'] ?? 1);
        $invoiceRef = trim($_POST['invoice_reference'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $itemsData  = $_POST['items'] ?? [];

        if ($supplierId <= 0) {
            set_flash('error', 'Please select a valid supplier or internal production store.');
            header("Location: {$appBase}/menu/grn.php");
            exit;
        }

        try {
            $result = process_grn_receipt(
                $pdo,
                $currentUserId,
                $supplierId,
                $invoiceRef,
                $notes,
                $itemsData
            );

            $receiptTypeLabel = $result['is_internal'] ? 'Production Receipt' : 'Goods Received Note';
            set_flash('success', "{$receiptTypeLabel} #{$result['grn_number']} processed successfully! Received {$result['items_count']} items (Total: " . format_price($result['total_cost']) . "). Master kitchen inventory updated.");
            header("Location: {$appBase}/menu/grn.php?view_id=" . $result['grn_id']);
            exit;
        } catch (Exception $e) {
            set_flash('error', 'Receipt Processing Error: ' . $e->getMessage());
            header("Location: {$appBase}/menu/grn.php");
            exit;
        }
    }
}

// 5. Fetch specific GRN for Voucher / Print View if ?view_id is specified
$viewGrn = null;
$viewGrnItems = [];
if (isset($_GET['view_id'])) {
    $viewId = (int)$_GET['view_id'];
    $grnStmt = $pdo->prepare("
        SELECT g.*, u.full_name AS received_by_name, r.name AS role_name,
               s.code AS supplier_code, s.category AS supplier_category, s.contact_person, s.phone AS supplier_phone
        FROM grn_headers g
        JOIN users u ON g.received_by_user_id = u.id
        LEFT JOIN roles r ON u.role_id = r.id
        LEFT JOIN suppliers s ON g.supplier_id = s.id
        WHERE g.id = :id
        LIMIT 1
    ");
    $grnStmt->execute([':id' => $viewId]);
    $viewGrn = $grnStmt->fetch();

    if ($viewGrn) {
        $itemsStmt = $pdo->prepare("
            SELECT gi.*, d.name AS dish_name, c.name AS category_name
            FROM grn_items gi
            JOIN dishes d ON gi.dish_id = d.id
            JOIN categories c ON d.category_id = c.id
            WHERE gi.grn_id = :gid
            ORDER BY gi.id ASC
        ");
        $itemsStmt->execute([':gid' => $viewId]);
        $viewGrnItems = $itemsStmt->fetchAll();
    }
}

// 5. Fetch Recent Goods Received Notes for audit table
$recentGrns = $pdo->query("
    SELECT g.*, u.full_name AS received_by_name,
           COUNT(gi.id) AS item_count,
           SUM(gi.quantity_received) AS total_units
    FROM grn_headers g
    JOIN users u ON g.received_by_user_id = u.id
    LEFT JOIN grn_items gi ON g.id = gi.grn_id
    GROUP BY g.id
    ORDER BY g.received_at DESC, g.id DESC
    LIMIT 20
")->fetchAll();

$nextGrnNumberPreview = generate_grn_number($pdo);
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header & Action Bar -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Inventory Inward Control
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Goods Received Notes (GRN)</h1>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                Official procurement and internal production receipts. Inward batches update kitchen inventory in real-time.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?= $appBase ?>/menu/suppliers.php" class="btn btn-primary" style="background: #0284c7; border-color: #0284c7;">🚚 Supplier Master</a>
            <a href="<?= $appBase ?>/menu/adjustment_notes.php" class="btn btn-secondary">📝 Stock Adjustment Notes</a>
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline">📦 Inventory Dashboard</a>
            <button type="button" class="btn btn-outline" onclick="document.getElementById('quickAddDishModal').style.display='flex';">
                ✨ + Register New Dish
            </button>
        </div>
    </div>

    <!-- GRN Voucher / Printable Receipt View (Shown when ?view_id is set) -->
    <?php if ($viewGrn): ?>
        <?php
        $isInternalView = ((int)$viewGrn['supplier_id'] === 1 || ($viewGrn['supplier_category'] ?? '') === 'internal');
        ?>
        <div style="background: white; border: 2px solid <?= $isInternalView ? '#16a34a' : 'var(--color-primary)' ?>; border-radius: var(--radius-lg); padding: 2.5rem; margin-bottom: 3rem; box-shadow: var(--shadow-md);">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px dashed var(--border-color); padding-bottom: 1.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                        <span style="display: inline-block; background: <?= $isInternalView ? '#dcfce7' : '#e0f2fe' ?>; color: <?= $isInternalView ? '#15803d' : '#0369a1' ?>; font-size: 0.75rem; font-weight: 800; padding: 0.35rem 0.75rem; border-radius: 999px; text-transform: uppercase;">
                            <?= $isInternalView ? '🏭 Goods Received from Production' : '🚚 External Vendor Delivery' ?>
                        </span>
                        <span style="display: inline-block; background: #f1f5f9; color: #475569; font-size: 0.75rem; font-weight: 700; padding: 0.35rem 0.65rem; border-radius: 999px;">
                            Official Inward Voucher
                        </span>
                    </div>
                    <h2 style="font-size: 1.85rem; margin: 0 0 0.25rem 0; color: var(--color-secondary);">
                        <?= $isInternalView ? 'Production Inward Transfer Voucher' : 'Goods Received Note Voucher' ?>
                    </h2>
                    <p style="font-size: 0.9rem; color: var(--text-muted); margin: 0;">UrbanEats Cloud Kitchen Platform • Colombo Central Kitchen Hub</p>
                </div>
                <div style="text-align: right;">
                    <div style="font-family: var(--font-heading); font-size: 1.5rem; font-weight: 800; color: <?= $isInternalView ? '#16a34a' : 'var(--color-primary)' ?>;">
                        <?= e($viewGrn['grn_number']) ?>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                        Receipt Date: <strong><?= format_datetime($viewGrn['received_at']) ?></strong>
                    </div>
                    <button type="button" class="btn btn-outline-sm" style="margin-top: 0.5rem;" onclick="window.print();">
                        🖨️ Print Official Voucher
                    </button>
                </div>
            </div>

            <!-- Meta Details Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; background: var(--bg-page); padding: 1.25rem; border-radius: var(--radius-md);">
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">
                        <?= $isInternalView ? 'Production Source / Store:' : 'Supplier / Vendor:' ?>
                    </span>
                    <div style="font-size: 1.05rem; font-weight: 700; color: var(--color-secondary);">
                        <?= e($viewGrn['supplier_name']) ?>
                    </div>
                    <?php if (!empty($viewGrn['supplier_code'])): ?>
                        <span style="font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">(<?= e($viewGrn['supplier_code']) ?>)</span>
                    <?php endif; ?>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">
                        <?= $isInternalView ? 'Production Batch / Ticket Ref:' : 'Delivery / Invoice Ref:' ?>
                    </span>
                    <div style="font-size: 1.05rem; font-weight: 600;">
                        <?= e($viewGrn['invoice_reference'] ?: 'Standard Production Run') ?>
                    </div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Received & Inspected By:</span>
                    <div style="font-size: 1.05rem; font-weight: 600;"><?= e($viewGrn['received_by_name']) ?> (<?= ucfirst($viewGrn['role_name'] ?? 'Staff') ?>)</div>
                </div>
                <div>
                    <span style="font-size: 0.75rem; text-transform: uppercase; color: var(--text-muted); font-weight: 700;">Total Inward Cost Valuation:</span>
                    <div style="font-size: 1.25rem; font-weight: 800; color: <?= $isInternalView ? '#16a34a' : 'var(--color-primary)' ?>;">
                        <?= format_price($viewGrn['total_cost']) ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($viewGrn['notes'])): ?>
                <div style="margin-bottom: 1.5rem; padding: 0.75rem 1rem; background: #fffbeb; border-left: 4px solid #f59e0b; border-radius: var(--radius-sm); font-size: 0.9rem;">
                    <strong><?= $isInternalView ? 'Production & QA Notes:' : 'Inspection Notes:' ?></strong> <?= e($viewGrn['notes']) ?>
                </div>
            <?php endif; ?>

            <!-- Itemized Table -->
            <table class="data-table" style="margin-bottom: 1.5rem;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Delivered / Cooked Dish</th>
                        <th>Category</th>
                        <th>Batch / Lot No</th>
                        <th>Expiry Date</th>
                        <th style="text-align: right;">Quantity Received</th>
                        <th style="text-align: right;">Unit Cost (LKR)</th>
                        <th style="text-align: right;">Line Total (LKR)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $lineIdx = 1; ?>
                    <?php foreach ($viewGrnItems as $item): ?>
                        <tr>
                            <td><?= $lineIdx++ ?></td>
                            <td><strong><?= e($item['dish_name']) ?></strong></td>
                            <td><?= e($item['category_name']) ?></td>
                            <td><code><?= e($item['batch_number'] ?: 'STANDARD') ?></code></td>
                            <td><?= e($item['expiry_date'] ?: 'N/A') ?></td>
                            <td style="text-align: right;"><strong style="color: #16a34a;">+<?= (int)$item['quantity_received'] ?> units</strong></td>
                            <td style="text-align: right;"><?= format_price($item['unit_cost']) ?></td>
                            <td style="text-align: right;"><strong><?= format_price($item['line_total']) ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #f8fafc; font-size: 1.05rem;">
                        <th colspan="7" style="text-align: right; font-weight: 800;">Grand Total Inward Value:</th>
                        <th style="text-align: right; font-weight: 800; color: <?= $isInternalView ? '#16a34a' : 'var(--color-primary)' ?>;">
                            <?= format_price($viewGrn['total_cost']) ?>
                        </th>
                    </tr>
                </tfoot>
            </table>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 2rem; border-top: 1px solid var(--border-color); padding-top: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                <div style="font-size: 0.85rem; color: var(--text-muted);">
                    ✔ Inventory balances have been atomically credited in <code>inventory</code> & audited in <code>stock_movements</code>.
                </div>
                <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-secondary">Create Another GRN</a>
            </div>
        </div>
    <?php endif; ?>

    <!-- GRN Creation Form Card -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 3rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 1rem;">
            <div>
                <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">Record Inward Delivery / Production Receipt</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Document supplier inward shipment or freshly prepped kitchen batch</span>
            </div>
            <div style="font-size: 0.9rem; font-weight: 700; color: var(--color-primary); background: var(--bg-page); padding: 0.5rem 1rem; border-radius: var(--radius-md);">
                Generated Document #: <code><?= $nextGrnNumberPreview ?></code>
            </div>
        </div>

        <form action="<?= $appBase ?>/menu/grn.php" method="POST" id="grnForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_grn">

            <!-- Supplier & Header Info Row -->
            <div class="form-row" style="align-items: flex-end;">
                <div class="form-group" style="flex: 2;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                        <label for="supplier_id" class="form-label" style="margin: 0;">Supplier / Production Source <span class="required">*</span></label>
                        <a href="<?= $appBase ?>/menu/suppliers.php" target="_blank" style="font-size: 0.75rem; color: var(--color-primary); font-weight: 600; text-decoration: none;">View Supplier Master ↗</a>
                    </div>
                    <div style="display: flex; gap: 0.5rem;">
                        <select id="supplier_id" name="supplier_id" class="form-control" required onchange="handleSupplierChange()">
                            <?php foreach ($allSuppliers as $sup): ?>
                                <?php $isInternalSup = ((int)$sup['id'] === 1 || $sup['category'] === 'internal'); ?>
                                <option value="<?= $sup['id'] ?>" 
                                        data-category="<?= $sup['category'] ?>" 
                                        data-code="<?= e($sup['code']) ?>"
                                        data-name="<?= e($sup['name']) ?>"
                                        <?= ((int)$sup['id'] === 1) ? 'selected' : '' ?>>
                                    <?= $isInternalSup ? '🏭 ' : '🚚 ' ?><?= e($sup['name']) ?> (<?= e($sup['code']) ?>) [<?= ucfirst($sup['category']) ?>]
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-outline" style="white-space: nowrap;" onclick="document.getElementById('quickAddSupplierModal').style.display='flex';">
                            + New
                        </button>
                    </div>
                </div>

                <div class="form-group" style="flex: 1.2;">
                    <label for="invoice_reference" class="form-label" id="invoiceRefLabel">Production Batch / Job Ticket #</label>
                    <input type="text" id="invoice_reference" name="invoice_reference" class="form-control" placeholder="e.g. PROD-BATCH-501 or Shift-A-Prep">
                </div>

                <div class="form-group" style="flex: 1;">
                    <label class="form-label">Receiving Officer</label>
                    <input type="text" class="form-control" value="<?= e($currentUser['name']) ?> (<?= ucfirst($currentUser['role_name'] ?? 'Staff') ?>)" disabled style="background: #f1f5f9;">
                </div>
            </div>

            <!-- Dynamic Mode Indicator Banner -->
            <div id="supplierModeBanner" style="background: #f0fdf4; border: 1px solid #86efac; border-radius: var(--radius-md); padding: 0.85rem 1.25rem; margin-bottom: 1.5rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; flex-wrap: wrap;">
                <div style="display: flex; align-items: center; gap: 0.75rem;">
                    <span id="modeIcon" style="font-size: 1.5rem;">🏭</span>
                    <div>
                        <strong id="modeTitle" style="color: #166534; font-size: 0.95rem;">Goods Received from Production (Internal Central Kitchen)</strong>
                        <p id="modeDesc" style="font-size: 0.8rem; color: #15803d; margin: 0.15rem 0 0 0;">
                            Documenting freshly prepped batches arriving directly from internal kitchen stations into available stock.
                        </p>
                    </div>
                </div>
                <span id="modeBadge" style="background: #22c55e; color: white; font-size: 0.75rem; font-weight: 800; padding: 0.25rem 0.65rem; border-radius: 999px; text-transform: uppercase; white-space: nowrap;">
                    Internal Transfer
                </span>
            </div>

            <div class="form-group">
                <label for="notes" class="form-label" id="notesLabel">Production & Sensory Evaluation Remarks</label>
                <input type="text" id="notes" name="notes" class="form-control" placeholder="e.g. Morning cooking shift batch. Sensory tasting approved by Executive Chef.">
            </div>

            <!-- Multi-Item Line Table -->
            <div style="margin-top: 2rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
                <h3 style="font-size: 1.15rem; margin: 0; color: var(--color-secondary);">Received Dish Items & Quantities</h3>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Add all dishes included in this inward batch</span>
            </div>

            <div class="data-table-container" style="margin-bottom: 1rem;">
                <table class="data-table" id="grnItemsTable">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Select Dish / Menu Item <span class="required">*</span></th>
                            <th style="width: 15%;">Quantity (Units) <span class="required">*</span></th>
                            <th style="width: 15%;">Unit Cost (LKR)</th>
                            <th style="width: 15%;">Batch / Lot #</th>
                            <th style="width: 12%;">Expiry Date</th>
                            <th style="width: 8%; text-align: center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="grnItemsBody">
                        <!-- Initial Row -->
                        <tr class="grn-row">
                            <td>
                                <select name="items[0][dish_id]" class="form-control dish-select" required onchange="calculateTotals()">
                                    <option value="">-- Choose Dish --</option>
                                    <?php foreach ($activeDishes as $d): ?>
                                        <option value="<?= $d['id'] ?>">
                                            <?= e($d['name']) ?> (Current: <?= $d['current_stock'] ?> units)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="number" min="1" name="items[0][quantity]" class="form-control item-qty" placeholder="Units" required oninput="calculateTotals()">
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0" name="items[0][unit_cost]" class="form-control item-cost" placeholder="0.00" oninput="calculateTotals()">
                            </td>
                            <td>
                                <input type="text" name="items[0][batch_number]" class="form-control" placeholder="e.g. B-01">
                            </td>
                            <td>
                                <input type="date" name="items[0][expiry_date]" class="form-control">
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="btn btn-outline-sm text-danger" onclick="removeRow(this)">✕</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
                <button type="button" class="btn btn-outline" id="addRowBtn" onclick="addGrnRow()">
                    ➕ Add Another Dish Row
                </button>
                <div style="font-size: 1.15rem; font-weight: 700;">
                    Estimated Inward Total: <span id="grandTotalDisplay" style="color: var(--color-primary); font-size: 1.35rem; font-weight: 800;">LKR 0.00</span>
                </div>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary btn-lg" id="grnSubmitBtn">
                    📥 Post Goods Received from Production (Update Stock)
                </button>
                <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline btn-lg">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Recent Goods Received Notes Audit Log Table -->
    <div style="margin-top: 3.5rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">Recent Goods Received Notes Audit History</h2>
            <span style="font-size: 0.85rem; color: var(--text-muted);">Historical log of all accepted inward kitchen supplier shipments</span>
        </div>
        <span style="font-size: 0.85rem; color: var(--text-muted);"><?= count($recentGrns) ?> Shipments Logged</span>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>GRN Document #</th>
                    <th>Date Received</th>
                    <th>Supplier / Source</th>
                    <th>Invoice / Batch #</th>
                    <th>Receiving Officer</th>
                    <th>Items / Units</th>
                    <th>Total Value (LKR)</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentGrns)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: var(--text-muted); padding: 2rem;">
                            No Goods Received Notes recorded yet.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentGrns as $grn): ?>
                        <tr>
                            <td>
                                <strong><?= e($grn['grn_number']) ?></strong>
                            </td>
                            <td style="font-size: 0.85rem; color: var(--text-muted); white-space: nowrap;">
                                <?= format_datetime($grn['received_at']) ?>
                            </td>
                            <td>
                                <?php if ((int)$grn['supplier_id'] === 1): ?>
                                    <span style="background: #dcfce7; color: #15803d; font-size: 0.75rem; font-weight: 700; padding: 0.15rem 0.5rem; border-radius: 999px;">🏭 Production</span>
                                <?php endif; ?>
                                <strong><?= e($grn['supplier_name']) ?></strong>
                            </td>
                            <td><code><?= e($grn['invoice_reference'] ?: '-') ?></code></td>
                            <td><?= e($grn['received_by_name']) ?></td>
                            <td>
                                <span class="badge badge-active">
                                    <?= (int)$grn['item_count'] ?> items (<?= (int)$grn['total_units'] ?> units)
                                </span>
                            </td>
                            <td><strong><?= format_price($grn['total_cost']) ?></strong></td>
                            <td style="text-align: right;">
                                <a href="<?= $appBase ?>/menu/grn.php?view_id=<?= $grn['id'] ?>" class="btn btn-outline-sm">
                                    👁️ View Voucher
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Quick Register New Dish to Database -->
<div id="quickAddDishModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; justify-content: center; align-items: center; padding: 1.5rem;">
    <div style="background: white; border-radius: var(--radius-lg); max-width: 600px; width: 100%; padding: 2rem; box-shadow: var(--shadow-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; font-size: 1.25rem; color: var(--color-secondary);">Register New Dish to Database</h3>
            <button type="button" class="btn btn-outline-sm" onclick="document.getElementById('quickAddDishModal').style.display='none';">✕</button>
        </div>

        <form action="<?= $appBase ?>/menu/grn.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quick_add_dish">

            <div class="form-group">
                <label for="new_dish_name" class="form-label">Dish Name <span class="required">*</span></label>
                <input type="text" id="new_dish_name" name="new_dish_name" class="form-control" placeholder="e.g. Ceylon Spiced Prawn Kottu" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="new_category_id" class="form-label">Category <span class="required">*</span></label>
                    <select id="new_category_id" name="new_category_id" class="form-control" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($allCategories as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="new_dish_price" class="form-label">Customer Selling Price (LKR) <span class="required">*</span></label>
                    <input type="number" step="0.01" min="1" id="new_dish_price" name="new_dish_price" class="form-control" placeholder="e.g. 1850.00" required>
                </div>
            </div>

            <div class="form-group">
                <label for="new_dish_desc" class="form-label">Culinary Description</label>
                <input type="text" id="new_dish_desc" name="new_dish_desc" class="form-control" placeholder="Ingredients and flavor profile...">
            </div>

            <div class="form-group">
                <label for="new_reorder_level" class="form-label">Reorder Alert Threshold (Units)</label>
                <input type="number" min="1" id="new_reorder_level" name="new_reorder_level" class="form-control" value="10" required>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save Dish & Add to Catalogue</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('quickAddDishModal').style.display='none';">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Quick Register New Supplier -->
<div id="quickAddSupplierModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; justify-content: center; align-items: center; padding: 1.5rem;">
    <div style="background: white; border-radius: var(--radius-lg); max-width: 550px; width: 100%; padding: 2rem; box-shadow: var(--shadow-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h3 style="margin: 0; font-size: 1.25rem; color: var(--color-secondary);">Register New Vendor in Supplier Master</h3>
            <button type="button" class="btn btn-outline-sm" onclick="document.getElementById('quickAddSupplierModal').style.display='none';">✕</button>
        </div>

        <form action="<?= $appBase ?>/menu/grn.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="quick_add_supplier">

            <div class="form-group">
                <label for="new_supplier_name" class="form-label">Supplier / Farm / Distributor Name <span class="required">*</span></label>
                <input type="text" id="new_supplier_name" name="new_supplier_name" class="form-control" placeholder="e.g. Ceylon Highland Farms Ltd" required>
            </div>

            <div class="form-group">
                <label for="new_supplier_cat" class="form-label">Classification</label>
                <select id="new_supplier_cat" name="new_supplier_cat" class="form-control">
                    <option value="external" selected>External Vendor / Distributor</option>
                    <option value="internal">Internal Store / Production Hub</option>
                </select>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="new_supplier_contact" class="form-label">Contact Person</label>
                    <input type="text" id="new_supplier_contact" name="new_supplier_contact" class="form-control" placeholder="e.g. Nimal Fernando">
                </div>
                <div class="form-group">
                    <label for="new_supplier_phone" class="form-label">Phone Number</label>
                    <input type="text" id="new_supplier_phone" name="new_supplier_phone" class="form-control" placeholder="e.g. +94112890123">
                </div>
            </div>

            <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                <button type="submit" class="btn btn-primary" style="flex: 1;">Save & Add to Suppliers</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('quickAddSupplierModal').style.display='none';">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
let rowIndex = 1;

function handleSupplierChange() {
    const select = document.getElementById('supplier_id');
    const selectedOption = select.options[select.selectedIndex];
    const category = selectedOption.getAttribute('data-category');
    const supId = parseInt(select.value);
    const supName = selectedOption.getAttribute('data-name');

    const banner = document.getElementById('supplierModeBanner');
    const icon = document.getElementById('modeIcon');
    const title = document.getElementById('modeTitle');
    const desc = document.getElementById('modeDesc');
    const badge = document.getElementById('modeBadge');
    const refLabel = document.getElementById('invoiceRefLabel');
    const refInput = document.getElementById('invoice_reference');
    const notesLabel = document.getElementById('notesLabel');
    const notesInput = document.getElementById('notes');
    const submitBtn = document.getElementById('grnSubmitBtn');

    if (supId === 1 || category === 'internal') {
        // Mode 1: Goods Received from Production (Internal Central Kitchen)
        banner.style.background = '#f0fdf4';
        banner.style.borderColor = '#86efac';
        icon.textContent = '🏭';
        title.textContent = 'Goods Received from Production (Internal Central Kitchen)';
        title.style.color = '#166534';
        desc.textContent = 'Documenting freshly prepared dishes arriving directly from central kitchen production stations into available stock.';
        desc.style.color = '#15803d';
        badge.textContent = 'Internal Transfer';
        badge.style.background = '#22c55e';
        
        refLabel.textContent = 'Production Batch / Kitchen Job Ticket #';
        refInput.placeholder = 'e.g. PROD-BATCH-501 or Shift-A-Prep';
        notesLabel.textContent = 'Production & Sensory Evaluation Remarks';
        notesInput.placeholder = 'e.g. Morning cooking shift batch. Sensory tasting approved by Executive Chef.';
        submitBtn.innerHTML = '📥 Post Goods Received from Production (Update Stock)';
    } else {
        // Mode 2: External Supplier Procurement Receipt
        banner.style.background = '#f0f9ff';
        banner.style.borderColor = '#bae6fd';
        icon.textContent = '🚚';
        title.textContent = 'External Supplier Procurement: ' + supName;
        title.style.color = '#0369a1';
        desc.textContent = 'Recording official vendor delivery. Verify that quantity, temperatures, and physical items match invoice.';
        desc.style.color = '#0284c7';
        badge.textContent = 'External Vendor';
        badge.style.background = '#0284c7';

        refLabel.textContent = 'Vendor Invoice / Delivery Note #';
        refInput.placeholder = 'e.g. INV-CAG-8921 or DN-4410';
        notesLabel.textContent = 'Receiving Condition & Inspection Notes';
        notesInput.placeholder = 'e.g. Temperature checked below 4°C, freshness approved, packaging intact.';
        submitBtn.innerHTML = '💾 Confirm & Post Goods Received Note (GRN)';
    }
}

// Trigger initial dynamic banner setup on page load
document.addEventListener('DOMContentLoaded', function() {
    handleSupplierChange();
});

function addGrnRow() {
    const tableBody = document.getElementById('grnItemsBody');
    const firstRow = tableBody.querySelector('.grn-row');
    const newRow = firstRow.cloneNode(true);

    // Update names with unique index
    newRow.querySelectorAll('input, select').forEach(input => {
        const nameAttr = input.getAttribute('name');
        if (nameAttr) {
            input.setAttribute('name', nameAttr.replace(/\[\d+\]/, '[' + rowIndex + ']'));
        }
        if (input.type === 'number' || input.type === 'text' || input.type === 'date') {
            input.value = '';
        }
    });

    tableBody.appendChild(newRow);
    rowIndex++;
    calculateTotals();
}

function removeRow(btn) {
    const rows = document.querySelectorAll('#grnItemsBody .grn-row');
    if (rows.length > 1) {
        btn.closest('tr').remove();
        calculateTotals();
    } else {
        alert('At least one dish row is required for the Goods Received Note.');
    }
}

function calculateTotals() {
    let grandTotal = 0;
    const rows = document.querySelectorAll('#grnItemsBody .grn-row');
    rows.forEach(row => {
        const qty = parseFloat(row.querySelector('.item-qty').value) || 0;
        const cost = parseFloat(row.querySelector('.item-cost').value) || 0;
        grandTotal += (qty * cost);
    });

    document.getElementById('grandTotalDisplay').textContent = 'LKR ' + grandTotal.toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
