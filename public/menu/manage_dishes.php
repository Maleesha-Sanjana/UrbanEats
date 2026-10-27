<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Dish Catalogue Management (Staff & Admin CRUD)
 * Student 2: Denuka (Component B Owner)
 *
 * Key Responsibilities:
 * - Staff/Admin RBAC enforcement via require_role()
 * - Full CRUD with parameterized prepared statements
 * - Soft deletion pattern (setting is_active = 0 preserves past order references)
 * - Safe image upload integration (MIME, size, extension, unguessable filename)
 * - Initial inventory row creation and audit log recording
 */

$currentPage = 'dishes';
$pageTitle = 'Manage Dishes - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Only kitchen staff and administrators are permitted here
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUserId = (int)$_SESSION['user_id'];

// 1. Fetch active categories for dropdown selectors
$categoriesStmt = $pdo->query("
    SELECT id, name, slug 
    FROM categories 
    WHERE is_active = 1 
    ORDER BY name ASC
");
$categories = $categoriesStmt->fetchAll();

// 2. Handle POST Actions (Create, Update, Soft Delete, Reactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $submittedAction = $_POST['action'] ?? '';

    // Action A: Soft Delete / Archive Dish
    // VIVA EXPLANATION: Soft delete sets is_active = 0 so historical order receipts never break
    if ($submittedAction === 'delete') {
        $dishId = (int)($_POST['dish_id'] ?? 0);
        
        $nameCheckStmt = $pdo->prepare("SELECT name FROM dishes WHERE id = :id");
        $nameCheckStmt->execute([':id' => $dishId]);
        $targetDishName = $nameCheckStmt->fetchColumn();

        if ($targetDishName) {
            $archiveStmt = $pdo->prepare("UPDATE dishes SET is_active = 0 WHERE id = :id");
            $archiveStmt->execute([':id' => $dishId]);

            log_action(
                $pdo, 
                'DISH_DEACTIVATED', 
                "Soft deleted dish: {$targetDishName} (ID: {$dishId})", 
                $currentUserId
            );
            set_flash('success', "Dish '{$targetDishName}' was archived. Historical orders remain intact.");
        }
        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }

    // Action B: Reactivate Archived Dish
    if ($submittedAction === 'reactivate') {
        $dishId = (int)($_POST['dish_id'] ?? 0);
        
        $reactivateStmt = $pdo->prepare("UPDATE dishes SET is_active = 1 WHERE id = :id");
        $reactivateStmt->execute([':id' => $dishId]);

        log_action($pdo, 'DISH_REACTIVATED', "Reactivated dish ID: {$dishId}", $currentUserId);
        set_flash('success', 'Dish has been reactivated and returned to the public menu.');
        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }

    // Action C: Create or Update Dish Record
    if ($submittedAction === 'save') {
        $dishId       = (int)($_POST['dish_id'] ?? 0);
        $dishName     = trim($_POST['name'] ?? '');
        $categoryId   = (int)($_POST['category_id'] ?? 0);
        $price        = (float)($_POST['price'] ?? 0);
        $description  = trim($_POST['description'] ?? '');
        $isSpicy      = isset($_POST['is_spicy']) ? 1 : 0;
        $isVegetarian = isset($_POST['is_vegetarian']) ? 1 : 0;
        $initialStock = max(0, (int)($_POST['stock_qty'] ?? 0));
        $reorderLevel = max(1, (int)($_POST['reorder_level'] ?? 10));

        // Strict Server-Side Validation
        if ($dishName === '' || $categoryId <= 0 || $price <= 0) {
            set_flash('error', 'Please fill in all required fields (Dish Name, Category, and a valid positive Price).');
            header("Location: {$appBase}/menu/manage_dishes.php");
            exit;
        }

        // Handle Image Upload if a file was provided
        $uploadedImageName = null;
        if (!empty($_FILES['dish_image']) && $_FILES['dish_image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $uploadStatus = upload_dish_image($_FILES['dish_image']);
            if (!$uploadStatus['success']) {
                set_flash('error', 'Image Upload Failed: ' . $uploadStatus['error']);
                header("Location: {$appBase}/menu/manage_dishes.php");
                exit;
            }
            $uploadedImageName = $uploadStatus['filename'];
        }

        if ($dishId > 0) {
            // Updating an existing dish
            if ($uploadedImageName !== null) {
                $updateDishSql = "
                    UPDATE dishes 
                    SET category_id = :cat_id, name = :name, description = :desc, price = :price,
                        image_url = :img, is_spicy = :spicy, is_vegetarian = :veg
                    WHERE id = :id
                ";
                $updateStmt = $pdo->prepare($updateDishSql);
                $updateStmt->execute([
                    ':cat_id' => $categoryId,
                    ':name'   => $dishName,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':img'    => $uploadedImageName,
                    ':spicy'  => $isSpicy,
                    ':veg'    => $isVegetarian,
                    ':id'     => $dishId
                ]);
            } else {
                $updateDishSql = "
                    UPDATE dishes 
                    SET category_id = :cat_id, name = :name, description = :desc, price = :price,
                        is_spicy = :spicy, is_vegetarian = :veg
                    WHERE id = :id
                ";
                $updateStmt = $pdo->prepare($updateDishSql);
                $updateStmt->execute([
                    ':cat_id' => $categoryId,
                    ':name'   => $dishName,
                    ':desc'   => $description,
                    ':price'  => $price,
                    ':spicy'  => $isSpicy,
                    ':veg'    => $isVegetarian,
                    ':id'     => $dishId
                ]);
            }

            // Keep inventory reorder threshold synchronized
            $updateInvStmt = $pdo->prepare("UPDATE inventory SET reorder_level = :reorder WHERE dish_id = :dish_id");
            $updateInvStmt->execute([':reorder' => $reorderLevel, ':dish_id' => $dishId]);

            log_action($pdo, 'DISH_UPDATED', "Updated dish '{$dishName}' (ID: {$dishId})", $currentUserId);
            set_flash('success', "Dish '{$dishName}' was updated successfully.");
        } else {
            // Creating a new dish
            $insertDishSql = "
                INSERT INTO dishes (category_id, name, description, price, image_url, is_spicy, is_vegetarian, is_active, created_at)
                VALUES (:cat_id, :name, :desc, :price, :img, :spicy, :veg, 1, NOW())
            ";
            $insertStmt = $pdo->prepare($insertDishSql);
            $insertStmt->execute([
                ':cat_id' => $categoryId,
                ':name'   => $dishName,
                ':desc'   => $description,
                ':price'  => $price,
                ':img'    => $uploadedImageName ?: 'default_dish.jpg',
                ':spicy'  => $isSpicy,
                ':veg'    => $isVegetarian
            ]);

            $newDishId = (int)$pdo->lastInsertId();

            // Insert matching inventory record
            $insertInvStmt = $pdo->prepare("
                INSERT INTO inventory (dish_id, current_stock, reorder_level, updated_at)
                VALUES (:dish_id, :stock, :reorder, NOW())
            ");
            $insertInvStmt->execute([
                ':dish_id' => $newDishId,
                ':stock'   => $initialStock,
                ':reorder' => $reorderLevel
            ]);

            // If initial stock was given, record movement in stock_movements table
            if ($initialStock > 0) {
                record_stock_movement(
                    $pdo, 
                    $newDishId, 
                    $currentUserId, 
                    'restock', 
                    $initialStock, 
                    'Initial stock on dish creation'
                );
            }

            log_action($pdo, 'DISH_ADDED', "Added new dish '{$dishName}' with {$initialStock} units", $currentUserId);
            set_flash('success', "New dish '{$dishName}' created and published!");
        }

        header("Location: {$appBase}/menu/manage_dishes.php");
        exit;
    }
}

// 3. Fetch dish details if editing is requested via ?edit_id
$editDish = null;
if (isset($_GET['edit_id'])) {
    $editId = (int)$_GET['edit_id'];
    $editFetchStmt = $pdo->prepare("
        SELECT d.*, 
               COALESCE(i.current_stock, 0) AS current_stock, 
               COALESCE(i.reorder_level, 10) AS reorder_level
        FROM dishes d
        LEFT JOIN inventory i ON d.id = i.dish_id
        WHERE d.id = :id
        LIMIT 1
    ");
    $editFetchStmt->execute([':id' => $editId]);
    $editDish = $editFetchStmt->fetch();
}

// 4. Retrieve complete catalogue with joined categories and inventory
$catalogueQuery = "
    SELECT d.id, d.name, d.price, d.image_url, d.is_spicy, d.is_vegetarian, d.is_active,
           c.name AS category_name,
           COALESCE(i.current_stock, 0) AS current_stock,
           COALESCE(i.reorder_level, 10) AS reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    ORDER BY d.is_active DESC, d.id DESC
";
$allDishes = $pdo->query($catalogueQuery)->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header & Action Links -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Inventory & Catalogue
            </span>
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
            <input type="hidden" name="dish_id" value="<?= $editDish ? (int)$editDish['id'] : 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="name" class="form-label">Dish Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($editDish['name'] ?? '') ?>" placeholder="e.g. Signature Cheese Kottu" required>
                </div>

                <div class="form-group">
                    <label for="category_id" class="form-label">Menu Category <span class="required">*</span></label>
                    <select id="category_id" name="category_id" class="form-control" required>
                        <option value="">-- Choose Category --</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>" <?= ($editDish && (int)$editDish['category_id'] === (int)$cat['id']) ? 'selected' : '' ?>>
                                <?= e($cat['name']) ?>
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
                        <p class="form-hint">Current file: <code><?= e($editDish['image_url']) ?></code></p>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="reorder_level" class="form-label">Low Stock Alert Threshold</label>
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

    <!-- Catalogue List Table -->
    <div style="margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center;">
        <h2 style="font-size: 1.35rem; color: var(--color-secondary);">
            All Dishes in Catalogue (<?= count($allDishes) ?>)
        </h2>
        <span style="font-size: 0.85rem; color: var(--text-muted);">
            Soft-deleted dishes remain preserved for historical order integrity
        </span>
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
                <?php foreach ($allDishes as $dishItem): ?>
                    <?php
                    $isItemLowStock = ((int)$dishItem['current_stock'] <= (int)$dishItem['reorder_level']);
                    $isItemZeroStock = ((int)$dishItem['current_stock'] === 0);
                    ?>
                    <tr style="<?= (int)$dishItem['is_active'] === 0 ? 'opacity: 0.6; background-color: #f8fafc;' : '' ?>">
                        <td>
                            <div style="display: flex; align-items: center; gap: 0.75rem;">
                                <img src="<?= $appBase ?>/uploads/<?= e($dishItem['image_url'] ?: 'default_dish.jpg') ?>" 
                                     alt="<?= e($dishItem['name']) ?>" 
                                     style="width: 44px; height: 44px; border-radius: var(--radius-sm); object-fit: cover; background: #e2e8f0;"
                                     onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=100&q=80'">
                                <div>
                                    <strong><?= e($dishItem['name']) ?></strong>
                                    <span style="display: block; font-size: 0.75rem; color: var(--text-muted);">ID #<?= $dishItem['id'] ?></span>
                                </div>
                            </div>
                        </td>
                        <td><?= e($dishItem['category_name']) ?></td>
                        <td><strong><?= format_price($dishItem['price']) ?></strong></td>
                        <td>
                            <?php if ($isItemZeroStock): ?>
                                <span class="badge badge-inactive">0 (Sold Out)</span>
                            <?php elseif ($isItemLowStock): ?>
                                <span class="badge" style="background: #fffbeb; color: #92400e; border: 1px solid #fde68a;">
                                    ⚠️ <?= $dishItem['current_stock'] ?> units
                                </span>
                            <?php else: ?>
                                <span class="badge badge-active"><?= $dishItem['current_stock'] ?> units</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $dishItem['reorder_level'] ?> units</td>
                        <td>
                            <?php if ((int)$dishItem['is_vegetarian'] === 1): ?><span title="Vegetarian">🌱</span><?php endif; ?>
                            <?php if ((int)$dishItem['is_spicy'] === 1): ?><span title="Spicy">🌶️</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$dishItem['is_active'] === 1): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Archived</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                                <a href="<?= $appBase ?>/menu/manage_dishes.php?edit_id=<?= $dishItem['id'] ?>" class="btn btn-outline-sm">
                                    Edit
                                </a>

                                <?php if ((int)$dishItem['is_active'] === 1): ?>
                                    <form action="<?= $appBase ?>/menu/manage_dishes.php" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="dish_id" value="<?= $dishItem['id'] ?>">
                                        <button type="submit" class="btn btn-outline-sm text-danger" onclick="return confirm('Soft delete dish \'<?= e($dishItem['name']) ?>\'? It will be hidden from the public menu.');">
                                            Archive
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form action="<?= $appBase ?>/menu/manage_dishes.php" method="POST" style="margin: 0;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="reactivate">
                                        <input type="hidden" name="dish_id" value="<?= $dishItem['id'] ?>">
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
