<?php

$currentPage = 'dishes';
$pageTitle = 'Manage Categories - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// Access Control: Restricted to kitchen staff and administrators
require_role(['kitchen_staff', 'admin']);

$pdo = get_db_connection();
$currentUserId = (int)$_SESSION['user_id'];

// Handle POST actions (Save Category, Toggle Status)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $submittedAction = $_POST['action'] ?? '';

    // Action A: Create or Update Category
    if ($submittedAction === 'save') {
        $categoryId  = (int)($_POST['category_id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $slugInput   = trim(strtolower($_POST['slug'] ?? ''));
        $description = trim($_POST['description'] ?? '');

        // Auto-generate clean URL slug from name if left empty
        $normalizedSlug = $slugInput;
        if ($normalizedSlug === '') {
            $normalizedSlug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
            $normalizedSlug = trim($normalizedSlug, '-');
        }

        if ($name === '' || $normalizedSlug === '') {
            set_flash('error', 'Both category name and a valid URL slug are required.');
        } else {
            if ($categoryId > 0) {
                // Update existing category
                $updateCatStmt = $pdo->prepare("
                    UPDATE categories 
                    SET name = :name, slug = :slug, description = :desc
                    WHERE id = :id
                ");
                $updateCatStmt->execute([
                    ':name' => $name,
                    ':slug' => $normalizedSlug,
                    ':desc' => $description,
                    ':id'   => $categoryId
                ]);
                log_action($pdo, 'CATEGORY_UPDATED', "Updated category '{$name}' (ID: {$categoryId})", $currentUserId);
                set_flash('success', "Category '{$name}' updated successfully.");
            } else {
                // Insert new category
                $insertCatStmt = $pdo->prepare("
                    INSERT INTO categories (name, slug, description, is_active, created_at)
                    VALUES (:name, :slug, :desc, 1, NOW())
                ");
                $insertCatStmt->execute([
                    ':name' => $name,
                    ':slug' => $normalizedSlug,
                    ':desc' => $description
                ]);
                log_action($pdo, 'CATEGORY_ADDED', "Added new category '{$name}'", $currentUserId);
                set_flash('success', "New category '{$name}' created successfully.");
            }
        }
        header("Location: {$appBase}/menu/categories.php");
        exit;
    }

    // Action B: Toggle Category Active Status
    if ($submittedAction === 'toggle_active') {
        $targetCatId = (int)($_POST['category_id'] ?? 0);
        $fetchStatusStmt = $pdo->prepare("SELECT is_active, name FROM categories WHERE id = :id");
        $fetchStatusStmt->execute([':id' => $targetCatId]);
        $targetCategory = $fetchStatusStmt->fetch();

        if ($targetCategory) {
            $nextStatus = ((int)$targetCategory['is_active'] === 1) ? 0 : 1;
            $toggleStmt = $pdo->prepare("UPDATE categories SET is_active = :status WHERE id = :id");
            $toggleStmt->execute([':status' => $nextStatus, ':id' => $targetCatId]);

            $statusText = ($nextStatus === 1) ? 'activated' : 'deactivated';
            log_action($pdo, 'CATEGORY_TOGGLED', "Category '{$targetCategory['name']}' {$statusText}", $currentUserId);
            set_flash('success', "Category '{$targetCategory['name']}' has been {$statusText}.");
        }
        header("Location: {$appBase}/menu/categories.php");
        exit;
    }
}

// Check if currently editing a category
$editingCategory = null;
if (isset($_GET['edit_id'])) {
    $editQueryStmt = $pdo->prepare("SELECT * FROM categories WHERE id = :id LIMIT 1");
    $editQueryStmt->execute([':id' => (int)$_GET['edit_id']]);
    $editingCategory = $editQueryStmt->fetch();
}

// Fetch all categories with real-time dish count aggregations
// LEFT JOIN ensures categories without any dishes yet still show 0 instead of being omitted
$categoriesSummaryQuery = "
    SELECT c.id, c.name, c.slug, c.description, c.is_active, c.created_at,
           COUNT(d.id) AS dish_count
    FROM categories c
    LEFT JOIN dishes d ON c.id = d.category_id
    GROUP BY c.id
    ORDER BY c.id ASC
";
$categories = $pdo->query($categoriesSummaryQuery)->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 2rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
                Component B: Menu Structure
            </span>
            <h1 style="font-size: 2rem; margin-top: 0.25rem;">Menu Categories Hierarchy</h1>
        </div>
        <div style="display: flex; gap: 0.75rem;">
            <a href="<?= $appBase ?>/menu/manage_dishes.php" class="btn btn-secondary">🍲 Dishes Catalogue</a>
            <a href="<?= $appBase ?>/menu/stock.php" class="btn btn-outline">📦 Inventory</a>
        </div>
    </div>

    <!-- Category Form Card -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2rem; margin-bottom: 2.5rem; box-shadow: var(--shadow-sm);">
        <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; color: var(--color-secondary);">
            <?= $editingCategory ? 'Edit Category: ' . e($editingCategory['name']) : 'Create New Menu Category' ?>
        </h2>

        <form action="<?= $appBase ?>/menu/categories.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="category_id" value="<?= $editingCategory ? (int)$editingCategory['id'] : 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label for="name" class="form-label">Category Name <span class="required">*</span></label>
                    <input type="text" id="name" name="name" class="form-control" value="<?= e($editingCategory['name'] ?? '') ?>" placeholder="e.g. Clay Pot Seafood Specials" required>
                </div>

                <div class="form-group">
                    <label for="slug" class="form-label">URL Slug (e.g. seafood-specials)</label>
                    <input type="text" id="slug" name="slug" class="form-control" value="<?= e($editingCategory['slug'] ?? '') ?>" placeholder="leave blank to auto-generate">
                </div>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">Category Description</label>
                <textarea id="description" name="description" rows="2" class="form-control" placeholder="Brief summary of dishes included in this section..."><?= e($editingCategory['description'] ?? '') ?></textarea>
            </div>

            <div style="display: flex; gap: 1rem;">
                <button type="submit" class="btn btn-primary">
                    <?= $editingCategory ? 'Update Category' : 'Add Category' ?>
                </button>
                <?php if ($editingCategory): ?>
                    <a href="<?= $appBase ?>/menu/categories.php" class="btn btn-outline">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Categories List Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Category Name</th>
                    <th>Slug</th>
                    <th>Description</th>
                    <th>Total Dishes</th>
                    <th>Status</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                    <tr>
                        <td>#<?= $cat['id'] ?></td>
                        <td><strong><?= e($cat['name']) ?></strong></td>
                        <td><code><?= e($cat['slug']) ?></code></td>
                        <td style="max-width: 340px; font-size: 0.85rem; color: var(--text-muted);"><?= e($cat['description']) ?></td>
                        <td>
                            <a href="<?= $appBase ?>/menu/list.php?category=<?= urlencode($cat['slug']) ?>" style="font-weight: 700;">
                                <?= (int)$cat['dish_count'] ?> dishes &rarr;
                            </a>
                        </td>
                        <td>
                            <?php if ((int)$cat['is_active'] === 1): ?>
                                <span class="badge badge-active">Active</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;">
                            <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                                <a href="<?= $appBase ?>/menu/categories.php?edit_id=<?= $cat['id'] ?>" class="btn btn-outline-sm">Edit</a>
                                
                                <form action="<?= $appBase ?>/menu/categories.php" method="POST" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="category_id" value="<?= $cat['id'] ?>">
                                    <?php if ((int)$cat['is_active'] === 1): ?>
                                        <button type="submit" class="btn btn-outline-sm text-danger" onclick="return confirm('Deactivate category \'<?= e($cat['name']) ?>\'?');">Deactivate</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn btn-primary-sm" style="background: var(--color-success);">Activate</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
