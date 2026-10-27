<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Dish Catalogue Management (CRUD)
 *
 * Implements Staff/Admin CRUD, soft deletion (is_active flag),
 * secure image upload (MIME, size, extension, unique naming),
 * and centralized audit logging.
 */

$currentPage = 'dishes';
$pageTitle = 'Manage Dishes - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUserId = (int)$_SESSION['user_id'];

// 1. Fetch Categories for Dropdowns
$categories = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// 2. Handle POST Actions (Create, Update, Soft Delete, Reactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $action = $_POST['action'] ?? '';

    // Action: Soft Delete / Archive Dish
    if ($action === 'delete') {
        $dishId = (int)($_POST['dish_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM dishes WHERE id = :id");
        $stmt->execute([':id' => $dishId]);
        $dishName = $stmt->fetchColumn();

        if ($dishName) {
            // Soft delete: set is_active = 0
            $delStmt = $pdo->prepare("UPDATE dishes SET is_active = 0 WHERE id = :id");
            $delStmt->execute([':id' => $dishId]);

            log_action($pdo, 'DISH_DEACTIVATED', "Soft deleted dish: {$dishName} (ID: {$dishId})", $currentUserId);
            set_flash('success', "Dish '{$dishName}' was archived successfully. Historical orders remain intact.");
        }
        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }

    // Action: Reactivate Dish
    if ($action === 'reactivate') {
        $dishId = (int)($_POST['dish_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE dishes SET is_active = 1 WHERE id = :id");
        $stmt->execute([':id' => $dishId]);

        log_action($pdo, 'DISH_REACTIVATED', "Reactivated dish ID: {$dishId}", $currentUserId);
        set_flash('success', 'Dish reactivated and restored to public menu.');
        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }

    // Action: Create or Update Dish
    if ($action === 'save') {
        $dishId = (int)($_POST['dish_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $price = (float)($_POST['price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $isSpicy = isset($_POST['is_spicy']) ? 1 : 0;
        $isVegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
        $stockQty = max(0, (int)($_POST['stock_qty'] ?? 0));
        $reorderLevel = max(1, (int)($_POST['reorder_level'] ?? 10));

        // Validation
        if (empty($name) || $categoryId <= 0 || $price <= 0) {
            set_flash('error', 'Please fill in all required fields (Name, Category, and valid Price).');
            header("Location: {$appBase}/menu/manage_dishes.php");
            exit;
        }

        // Handle Image Upload if provided
        $imageUrl = null;
        if (!empty($_FILES['dish_image']) && $_FILES['dish_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadResult = upload_dish_image($_FILES['dish_image']);
            if (!$uploadResult['success']) {
                set_flash('error', 'Image Upload Failed: ' . $uploadResult['error']);
                header("Location: {$appBase}/menu/manage_dishes.php");
                exit;
            }
            $imageUrl = $uploadResult['filename'];
        }

        if ($dishId > 0) {
            // Update Existing Dish
            if ($imageUrl !== null) {
                $upd = $pdo->prepare("
                    UPDATE dishes 
                    SET category_id = :cat_id, name = :name, description = :desc, price = :price,
                        image_url = :img, is_spicy = :spicy, is_vegetarian = :veg
                    WHERE id = :id
                ");
                $upd->execute([
                    ':cat_id' => $categoryId,
                    ':name'   => $name,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':img'    => $imageUrl,
                    ':spicy'  => $isSpicy,
                    ':veg'    => $isVegetarian,
                    ':id'     => $dishId
                ]);
            } else {
                $upd = $pdo->prepare("
                    UPDATE dishes 
                    SET category_id = :cat_id, name = :name, description = :desc, price = :price,
                        is_spicy = :spicy, is_vegetarian = :veg
                    WHERE id = :id
                ");
                $upd->execute([
                    ':cat_id' => $categoryId,
                    ':name'   => $name,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':spicy'  => $isSpicy,
                    ':veg'    => $isVegetarian,
                    ':id'     => $dishId
                ]);
            }

            // Update inventory reorder level
            $updInv = $pdo->prepare("UPDATE inventory SET reorder_level = :reorder WHERE dish_id = :dish_id");
            $updInv->execute([':reorder' => $reorderLevel, ':dish_id' => $dishId]);

            log_action($pdo, 'DISH_UPDATED', "Updated details for dish: {$name} (ID: {$dishId})", $currentUserId);
            set_flash('success', "Dish '{$name}' was updated successfully.");
        } else {
            // Insert New Dish
            $ins = $pdo->prepare("
                INSERT INTO dishes (category_id, name, description, price, image_url, is_spicy, is_vegetarian, is_active, created_at)
                VALUES (:cat_id, :name, :desc, :price, :img, :spicy, :veg, 1, NOW())
            ");
            $ins->execute([
                ':cat_id' => $categoryId,
                ':name'   => $name,
                ':desc'   => $description,
                ':price'  => $price,
                ':img'    => $imageUrl ?: 'default_dish.jpg',
                ':spicy'  => $isSpicy,
                ':veg'    => $isVegetarian
            ]);

            $newDishId = (int)$pdo->lastInsertId();

            // Insert initial inventory row
            $invIns = $pdo->prepare("
                INSERT INTO inventory (dish_id, current_stock, reorder_level, updated_at)
                VALUES (:dish_id, :stock, :reorder, NOW())
            ");
            $invIns->execute([
                ':dish_id' => $newDishId,
                ':stock'   => $stockQty,
                ':reorder' => $reorderLevel
            ]);

            if ($stockQty > 0) {
                record_stock_movement($pdo, $newDishId, $currentUserId, 'restock', $stockQty, 'Initial dish inventory creation');
            }

            log_action($pdo, 'DISH_ADDED', "Added new dish '{$name}' with {$stockQty} initial stock", $currentUserId);
            set_flash('success', "New dish '{$name}' has been created and added to the menu!");
        }

        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }
}

// 3. Fetch Dish for Editing if requested
$editDish = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $eStmt = $pdo->prepare("
        SELECT d.*, COALESCE(i.current_stock, 0) AS current_stock, COALESCE(i.reorder_level, 10) AS reorder_level
        FROM dishes d
        LEFT JOIN inventory i ON d.id = i.dish_id
        WHERE d.id = :id
        LIMIT 1
    ");
    $eStmt->execute([':id' => $editId]);
    $editDish = $eStmt->fetch();
}

// 4. Fetch All Dishes with Inventory
$dishesQuery = "
    SELECT d.*, c.name AS category_name,
           COALESCE(i.current_stock, 0) AS current_stock,
           COALESCE(i.reorder_level, 10) AS reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    ORDER BY d.is_active DESC, d.id DESC
";
$allDishes = $pdo->query($dishesQuery)->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component B: Inventory & Catalogue</span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Manage Dish Catalogue</h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-secondary">📦 Stock & Wastage Dashboard</a>
            <a href="<?= $appBase ?>/menu/categories.php" class="btn btn-outline">📂 Categories</a>
        </div>
    </div>

    <!-- Dish Add / Edit Form Card -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 2.5rem; box-shadow: var(--shadow-sm);">
        <h2 style="font-size: 1.35rem; margin-bottom: 1.5rem; color: var(--color-secondary);">
            <?= $editDish ? 'Edit Dish: ' . e($editDish['name']) : 'Add New Specialty Dish' ?>
        </h2>

        <form action="<?= $appBase ?>/menu/manage_dishes.php" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="dish_id" value="<?= $editDish ? $editDish['id'] : 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="name" class="form-label">Dish Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($editDish['name'] ?? '') ?>" placeholder="e.g. Signature Cheese Kottu" required>
                </div>

                <div class="form-group">
                    <label for="category_id" class="form-label">Menu Category <span class="required">*</span></label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($editDish && (int)$editDish['category_id'] === (int)$c['id']) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="price" class="form-label">Price in LKR <span class="required">*</span></label>
                    <input type="number" step="0.01" min="1" id="price" name="price" class="form-control" value="<?= e($editDish['price'] ?? '') ?>" placeholder="e.g. 1550.00" required>
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Culinary Description</label>
                <textarea id="description" name="description" rows="2" class="form-control" placeholder="Ingredients, spice level, preparation style..."><?= e($editDish['description'] ?? '') ?></textarea>
            </div>

            <div class="form-row" style="align-items: center;">
                <div class="form-group">
                    <label for="dish_image" class="form-label">Dish Image (JPG, PNG, WEBP - Max 2MB)</label>
                    <input type="file" id="dish_image" name="dish_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                    <?php if ($editDish && !empty($editDish['image_url'])): ?>
                        <p class="form-hint">Current image: <code><?= e($editDish['image_url']) ?></code></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="reorder_level" class="form-label">Low Stock Reorder Alert Level</label>
                    <input type="number" min="1" id="reorder_level" name="reorder_level" class="form-control" value="<?= e($editDish['reorder_level'] ?? 10) ?>" required>
                </div>

                <?php if (!$editDish): ?>
                    <div class="form-group">
                        <label for="stock_qty" class="form-label">Initial Opening Stock (Units)</label>
                        <input type="number" min="0" id="stock_qty" name="stock_qty" class="form-control" value="25" required>
                    </div>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 2rem; margin-bottom: 1.5rem; padding: 0.75rem 0;">
                <label class="checkbox-label">
                    <input type="checkbox" name="is_spicy" value="1" <?= ($editDish && (int)$editDish['is_spicy'] === 1) ? 'checked' : '' ?>>
                    <span>🌶️ Sri Lankan Hot & Spicy</span>
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="is_vegetarian" value="1" <?= ($editDish && (int)$editDish['is_vegetarian'] === 1) ? 'checked' : '' ?>>
                    <span>🌱 Vegetarian Recipe</span>
                </label>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary">
                    <?= $editDish ? 'Save Changes' : 'Create & Publish Dish' ?>
                </button>
                <?php if ($editDish): ?>
                    <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-outline">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Catalogue Table -->
    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.35rem; color: var(--color-secondary);">All Dishes in Database (<?= count($allDishes) ?>)</h2>
        <span style="font-size: 0.85rem; color: var(--text-muted);">Soft deleted dishes are preserved for historical order integrity</span>
    </div>

    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Dish</th>
                    <th>Category</th>
                    <th>Price (LKR)</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Dietary</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allDishes as $d): ?>
                    <?php
                    $isLow = ((int)$d['current_stock'] <= (int)$d['reorder_level']);
                    $isZero = ((int)$d['current_stock'] === 0);
                    ?>
                    <tr style="<?= (int)$d['is_active'] === 0 ? 'opacity: 0.6; background-color: #f8fafc;' : '' ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <img src="<?= $appBase ?>/uploads/<?= e($d['image_url'] ?: 'default_dish.jpg') ?>" 
                                     alt="<?= e($d['name']) ?>" 
                                     style="width: 44px; height: 44px; border-radius: var(--radius-sm); object-fit: cover; background: #e2e8f0;"
                                     onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=100&q=80'">
                                <div>
                                    <strong><?= e($d['name']) ?></strong>
                                    <span style="display: block; font-size: 0.75rem; color: var(--text-muted);">ID #<?= $d['id'] ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($d['category_name']) ?></td>
                        <td><strong><?= format_price($d['price']) ?></strong></td>
                        <td>
                            <?php if ($isZero): ?>
                                <span class="badge badge-inactive">0 (Sold Out)</span>
                            <?php elseif ($isLow): ?>
                                <span class="badge" style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a;">
                                    ⚠️ <?= $d['current_stock'] ?> units
                                </span>
                            <?php else: ?>
                                <span class="badge badge-active"><?= $d['current_stock'] ?> units</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $d['reorder_level'] ?> units</td>
                        <td>
                            <?php if ((int)$d['is_vegetarian'] === 1): ?><span title="Vegetarian">🌱</span><?php endif; ?>
                            <?php if ((int)$d['is_spicy'] === 1): ?><span title="Spicy">🌶️</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$d['is_active'] === 1): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                                <a href="<?= $appBase ?>/menu/manage_dishes.php?edit_id=<?= $d['id'] ?>" class="btn btn-outline-sm">
                                    Edit
                                </a>

                                <?php if ((int)$d['is_active'] === 1): ?>
                                    <form action="<?= $appBase ?>/menu/manage_dishes.php" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="dish_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-outline-sm text-danger" onclick="return confirm('Soft delete dish \'<?= e($d['name']) ?>\'? It will be hidden from the menu without breaking past orders.');">
                                            Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form action="<?= $appBase ?>/menu/manage_dishes.php" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="dish_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-primary-sm" style="background: var(--color-success);">
                                            Restore
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
