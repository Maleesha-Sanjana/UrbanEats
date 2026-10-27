<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Supplier Master Management
 * Student 2: Denuka (Component B Owner)
 *
 * Key Capabilities:
 * - Central registry of approved suppliers and internal production stores
 * - Supplier #1 is permanently designated as the internal Central Kitchen Store
 * - Staff and Admin RBAC access control
 * - Audited creation, modification, and status toggles for vendor compliance
 * - Aggregate metrics tracking: total GRNs received and inventory value procured
 */

$currentPage = 'stock';
$pageTitle = 'Supplier Master - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// RBAC: Restricted to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUser = current_user();
$currentUserId = (int)$currentUser['id'];

// Handle POST actions (Create Supplier, Update Supplier, Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();
    $action = $_POST['action'] ?? '';

    // Action A: Create New Supplier
    if ($action === 'create_supplier') {
        $code          = strtoupper(trim($_POST['code'] ?? ''));
        $name          = trim($_POST['name'] ?? '');
        $category      = in_array($_POST['category'] ?? '', ['internal', 'external'], true) ? $_POST['category'] : 'external';
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $address       = trim($_POST['address'] ?? '');

        if ($name === '') {
            set_flash('error', 'Supplier name is required.');
        } else {
            // Auto-generate code if left blank
            if ($code === '') {
                $prefix = 'SUP-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'VEN', 0, 3));
                $code = $prefix . '-' . random_int(10, 99);
            }

            try {
                $insStmt = $pdo->prepare("
                    INSERT INTO suppliers (code, name, category, contact_person, phone, email, address, is_active, created_at)
                    VALUES (:code, :name, :cat, :contact, :phone, :email, :address, 1, NOW())
                ");
                $insStmt->execute([
                    ':code'    => $code,
                    ':name'    => $name,
                    ':cat'     => $category,
                    ':contact' => $contactPerson ?: null,
                    ':phone'   => $phone ?: null,
                    ':email'   => $email ?: null,
                    ':address' => $address ?: null
                ]);
                $newId = (int)$pdo->lastInsertId();

                log_action($pdo, 'SUPPLIER_CREATED', "Registered new supplier '{$name}' [Code: {$code}] (ID: {$newId})", $currentUserId);
                set_flash('success', "Supplier '{$name}' registered successfully under code {$code}.");
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    set_flash('error', "A supplier with code '{$code}' already exists. Please choose a unique code.");
                } else {
                    set_flash('error', "Database error: " . $e->getMessage());
                }
            }
        }
        header("Location: {$appBase}/menu/suppliers.php");
        exit;
    }

    // Action B: Update Existing Supplier
    if ($action === 'update_supplier') {
        $supplierId    = (int)($_POST['supplier_id'] ?? 0);
        $name          = trim($_POST['name'] ?? '');
        $category      = in_array($_POST['category'] ?? '', ['internal', 'external'], true) ? $_POST['category'] : 'external';
        $contactPerson = trim($_POST['contact_person'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $address       = trim($_POST['address'] ?? '');

        if ($supplierId <= 0 || $name === '') {
            set_flash('error', 'Valid supplier ID and name are required.');
        } else {
            // Supplier 1 is permanently internal store
            if ($supplierId === 1) {
                $category = 'internal';
            }

            try {
                $updStmt = $pdo->prepare("
                    UPDATE suppliers
                    SET name = :name, category = :cat, contact_person = :contact,
                        phone = :phone, email = :email, address = :address
                    WHERE id = :id
                ");
                $updStmt->execute([
                    ':name'    => $name,
                    ':cat'     => $category,
                    ':contact' => $contactPerson ?: null,
                    ':phone'   => $phone ?: null,
                    ':email'   => $email ?: null,
                    ':address' => $address ?: null,
                    ':id'      => $supplierId
                ]);

                log_action($pdo, 'SUPPLIER_UPDATED', "Updated supplier '{$name}' (ID: {$supplierId})", $currentUserId);
                set_flash('success', "Supplier '{$name}' updated successfully.");
            } catch (Exception $e) {
                set_flash('error', "Failed to update supplier: " . $e->getMessage());
            }
        }
        header("Location: {$appBase}/menu/suppliers.php");
        exit;
    }

    // Action C: Toggle Active Status
    if ($action === 'toggle_status') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($supplierId === 1) {
            set_flash('error', "The primary Central Kitchen Store (Supplier #1) cannot be deactivated.");
        } else {
            $stmt = $pdo->prepare("UPDATE suppliers SET is_active = IF(is_active = 1, 0, 1) WHERE id = :id");
            $stmt->execute([':id' => $supplierId]);
            log_action($pdo, 'SUPPLIER_STATUS_TOGGLED', "Toggled active status for supplier ID #{$supplierId}", $currentUserId);
            set_flash('success', "Supplier status updated successfully.");
        }
        header("Location: {$appBase}/menu/suppliers.php");
        exit;
    }
}

// Fetch all suppliers along with GRN receipt metrics
$suppliersStmt = $pdo->query("
    SELECT s.*,
           COUNT(g.id) AS total_grns,
           COALESCE(SUM(g.total_cost), 0.00) AS total_procured_value,
           MAX(g.received_at) AS last_receipt_date
    FROM suppliers s
    LEFT JOIN grn_headers g ON s.id = g.supplier_id
    GROUP BY s.id
    ORDER BY s.id ASC
");
$allSuppliers = $suppliersStmt->fetchAll();

// Auto-suggest next supplier code
$nextCode = 'SUP-VEN-' . str_pad((string)(count($allSuppliers) + 1), 2, '0', STR_PAD_LEFT);
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Action Bar & Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Master Data & Procurement Control
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Supplier Master</h1>
            <p style="color: var(--text-muted); font-size: 0.9rem; margin-top: 0.25rem;">
                Official directory of internal kitchen hubs and external food & beverage vendors.
            </p>
        </div>
        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap;">
            <a href="<?= $appBase ?>/menu/grn.php" class="btn btn-primary">📥 Goods Received Notes (GRN)</a>
            <a href="<?= $appBase ?>/menu/adjustment_notes.php" class="btn btn-secondary">📝 Stock Adjustment Notes</a>
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline">📦 Inventory Dashboard</a>
            <button type="button" class="btn btn-primary" onclick="document.getElementById('addSupplierModal').style.display='flex';" style="background: #0284c7; border-color: #0284c7;">
                ✨ + Register New Supplier
            </button>
        </div>
    </div>

    <!-- Store / Internal Production Hub Highlight Card -->
    <?php
    $internalStore = null;
    foreach ($allSuppliers as $sup) {
        if ((int)$sup['id'] === 1) {
            $internalStore = $sup;
            break;
        }
    }
    ?>
    <?php if ($internalStore): ?>
        <div style="background: linear-gradient(135deg, #f0fdf4 0%, #e0f2fe 100%); border: 2px solid #38bdf8; border-radius: var(--radius-lg); padding: 1.5rem 2rem; margin-bottom: 2rem; box-shadow: var(--shadow-sm);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                <div>
                    <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem;">
                        <span style="background: #0284c7; color: white; font-size: 0.75rem; font-weight: 800; padding: 0.25rem 0.75rem; border-radius: 999px; text-transform: uppercase;">
                            Primary System Entity #1
                        </span>
                        <span style="background: #16a34a; color: white; font-size: 0.75rem; font-weight: 800; padding: 0.25rem 0.75rem; border-radius: 999px; text-transform: uppercase;">
                            Internal Store / Production Hub
                        </span>
                    </div>
                    <h2 style="font-size: 1.4rem; color: #0f172a; margin: 0 0 0.35rem 0;">
                        🏭 <?= e($internalStore['name']) ?>
                    </h2>
                    <p style="color: #475569; font-size: 0.9rem; margin: 0; max-width: 800px;">
                        Designated as the <strong>UrbanEats Central Kitchen Production Hub</strong>. When selected on the GRN form, the workflow automatically switches to <em>"Goods Received from Production"</em> to document hot-cooked and batch-prepared dishes entering inventory from our internal kitchen stations.
                    </p>
                </div>
                <div style="background: white; border-radius: var(--radius-md); padding: 1rem 1.5rem; text-align: center; border: 1px solid #bae6fd; min-width: 180px;">
                    <div style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase; font-weight: 700;">Production Receipts</div>
                    <div style="font-size: 1.5rem; font-weight: 800; color: #0284c7;"><?= (int)$internalStore['total_grns'] ?> GRNs</div>
                    <div style="font-size: 0.8rem; color: #16a34a; font-weight: 600;"><?= format_price($internalStore['total_procured_value']) ?></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Suppliers Data Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Supplier Code & Name</th>
                    <th>Classification</th>
                    <th>Contact Person</th>
                    <th>Phone & Email</th>
                    <th>Address / Location</th>
                    <th>GRN Inwards</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allSuppliers as $sup): ?>
                    <?php
                    $isInternal = ((int)$sup['id'] === 1 || $sup['category'] === 'internal');
                    $isActive = ((int)$sup['is_active'] === 1);
                    ?>
                    <tr style="<?= !$isActive ? 'opacity: 0.6; background-color: #f8fafc;' : '' ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <div style="width: 40px; height: 40px; border-radius: var(--radius-sm); background: <?= $isInternal ? '#e0f2fe' : '#f1f5f9' ?>; display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                                    <?= $isInternal ? '🏭' : '🚚' ?>
                                </div>
                                <div>
                                    <strong><?= e($sup['name']) ?></strong>
                                    <span style="display: block; font-size: 0.75rem; color: var(--text-muted); font-family: monospace;">
                                        <?= e($sup['code']) ?>
                                    </span>
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($isInternal): ?>
                                <span style="background: #e0f2fe; color: #0369a1; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 999px;">
                                    Internal Store
                                </span>
                            <?php else: ?>
                                <span style="background: #f1f5f9; color: #475569; font-size: 0.75rem; font-weight: 700; padding: 0.2rem 0.6rem; border-radius: 999px;">
                                    External Vendor
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($sup['contact_person'] ?: '—') ?></td>
                        <td>
                            <div style="font-size: 0.85rem;">
                                <?php if (!empty($sup['phone'])): ?>
                                    <div>📞 <?= e($sup['phone']) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($sup['email'])): ?>
                                    <div style="color: var(--text-muted); font-size: 0.8rem;">✉️ <?= e($sup['email']) ?></div>
                                <?php endif; ?>
                                <?php if (empty($sup['phone']) && empty($sup['email'])): ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td style="max-width: 200px; font-size: 0.85rem; color: var(--text-muted);">
                            <?= e($sup['address'] ?: '—') ?>
                        </td>
                        <td>
                            <strong><?= (int)$sup['total_grns'] ?></strong> <span style="font-size: 0.75rem; color: var(--text-muted);">receipts</span>
                            <div style="font-size: 0.75rem; color: var(--color-primary); font-weight: 600;">
                                <?= format_price($sup['total_procured_value']) ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($isActive): ?>
                                <span class="badge badge-success">Active</span>
                            <?php else: ?>
                                <span class="badge badge-danger">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: flex; gap: 0.5rem; justify-content: flex-end;">
                                <button type="button" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;"
                                        onclick="openEditSupplierModal(<?= htmlspecialchars(json_encode($sup), ENT_QUOTES, 'UTF-8') ?>)">
                                    ✏️ Edit
                                </button>
                                <?php if ((int)$sup['id'] !== 1): ?>
                                    <form action="<?= $appBase ?>/menu/suppliers.php" method="POST" style="display: inline;" onsubmit="return confirm('Toggle status for <?= e(addslashes($sup['name'])) ?>?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="supplier_id" value="<?= (int)$sup['id'] ?>">
                                        <button type="submit" class="btn btn-outline" style="padding: 0.35rem 0.65rem; font-size: 0.8rem; color: <?= $isActive ? '#dc2626' : '#16a34a' ?>;">
                                            <?= $isActive ? 'Deactivate' : 'Activate' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal 1: Register New Supplier -->
<div id="addSupplierModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem;">
    <div style="background: white; border-radius: var(--radius-lg); width: 100%; max-width: 600px; padding: 2rem; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">✨ Register New Supplier</h2>
            <button type="button" onclick="document.getElementById('addSupplierModal').style.display='none';" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>

        <form action="<?= $appBase ?>/menu/suppliers.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_supplier">

            <div class="form-row">
                <div class="form-group">
                    <label for="new_sup_code" class="form-label">Supplier Code</label>
                    <input type="text" id="new_sup_code" name="code" class="form-control" value="<?= e($nextCode) ?>" placeholder="e.g. SUP-CAG-09">
                    <p class="form-hint">Leave default or enter custom code.</p>
                </div>
                <div class="form-group">
                    <label for="new_sup_cat" class="form-label">Classification <span class="required">*</span></label>
                    <select id="new_sup_cat" name="category" class="form-control" required>
                        <option value="external" selected>External Vendor / Distributor</option>
                        <option value="internal">Internal Store / Production Hub</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="new_sup_name" class="form-label">Supplier / Company Name <span class="required">*</span></label>
                <input type="text" id="new_sup_name" name="name" class="form-control" placeholder="e.g. Ceylon Highland Farms Ltd" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="new_sup_contact" class="form-label">Contact Person</label>
                    <input type="text" id="new_sup_contact" name="contact_person" class="form-control" placeholder="e.g. Sunil Perera (Sales Manager)">
                </div>
                <div class="form-group">
                    <label for="new_sup_phone" class="form-label">Phone Number</label>
                    <input type="text" id="new_sup_phone" name="phone" class="form-control" placeholder="e.g. +94112345678">
                </div>
            </div>

            <div class="form-group">
                <label for="new_sup_email" class="form-label">Email Address</label>
                <input type="email" id="new_sup_email" name="email" class="form-control" placeholder="orders@supplier.lk">
            </div>

            <div class="form-group">
                <label for="new_sup_addr" class="form-label">Office / Warehouse Address</label>
                <textarea id="new_sup_addr" name="address" rows="2" class="form-control" placeholder="Street, City, Postal Code"></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('addSupplierModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary">Save & Register Supplier</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal 2: Edit Existing Supplier -->
<div id="editSupplierModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); z-index: 9999; align-items: center; justify-content: center; padding: 1.5rem;">
    <div style="background: white; border-radius: var(--radius-lg); width: 100%; max-width: 600px; padding: 2rem; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; margin-bottom: 1.5rem;">
            <h2 style="font-size: 1.35rem; margin: 0; color: var(--color-secondary);">✏️ Edit Supplier Details</h2>
            <button type="button" onclick="document.getElementById('editSupplierModal').style.display='none';" style="background: none; border: none; font-size: 1.5rem; cursor: pointer;">&times;</button>
        </div>

        <form action="<?= $appBase ?>/menu/suppliers.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_supplier">
            <input type="hidden" name="supplier_id" id="edit_sup_id">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Supplier Code</label>
                    <input type="text" id="edit_sup_code" class="form-control" readonly style="background: #f1f5f9;">
                </div>
                <div class="form-group">
                    <label for="edit_sup_cat" class="form-label">Classification</label>
                    <select id="edit_sup_cat" name="category" class="form-control">
                        <option value="external">External Vendor</option>
                        <option value="internal">Internal Store / Production Hub</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label for="edit_sup_name" class="form-label">Supplier / Entity Name <span class="required">*</span></label>
                <input type="text" id="edit_sup_name" name="name" class="form-control" required>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="edit_sup_contact" class="form-label">Contact Person</label>
                    <input type="text" id="edit_sup_contact" name="contact_person" class="form-control">
                </div>
                <div class="form-group">
                    <label for="edit_sup_phone" class="form-label">Phone Number</label>
                    <input type="text" id="edit_sup_phone" name="phone" class="form-control">
                </div>
            </div>

            <div class="form-group">
                <label for="edit_sup_email" class="form-label">Email Address</label>
                <input type="email" id="edit_sup_email" name="email" class="form-control">
            </div>

            <div class="form-group">
                <label for="edit_sup_addr" class="form-label">Address</label>
                <textarea id="edit_sup_addr" name="address" rows="2" class="form-control"></textarea>
            </div>

            <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('editSupplierModal').style.display='none';">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Supplier</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditSupplierModal(sup) {
    document.getElementById('edit_sup_id').value = sup.id;
    document.getElementById('edit_sup_code').value = sup.code;
    document.getElementById('edit_sup_name').value = sup.name;
    document.getElementById('edit_sup_cat').value = sup.category;
    document.getElementById('edit_sup_contact').value = sup.contact_person || '';
    document.getElementById('edit_sup_phone').value = sup.phone || '';
    document.getElementById('edit_sup_email').value = sup.email || '';
    document.getElementById('edit_sup_addr').value = sup.address || '';

    // If ID is 1, disable category dropdown because it's permanently internal
    if (parseInt(sup.id) === 1) {
        document.getElementById('edit_sup_cat').setAttribute('disabled', 'disabled');
    } else {
        document.getElementById('edit_sup_cat').removeAttribute('disabled');
    }

    document.getElementById('editSupplierModal').style.display = 'flex';
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
