<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Dish Detail & Order Placement
 *
 * Displays high-resolution dish details, ingredients, culinary tags,
 * real-time inventory stock levels, and custom quantity selector.
 */

$currentPage = 'menu';
require_once __DIR__ . '/../../includes/header.php';

$pdo = get_db_connection();
$dishId = (int)($_GET['id'] ?? 0);

if ($dishId <= 0) {
    header("Location: {$appBase}/menu/list.php");
    exit;
}

$stmt = $pdo->prepare("
    SELECT d.*, c.name AS category_name, c.slug AS category_slug,
           COALESCE(i.current_stock, 0) AS current_stock,
           i.reorder_level
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.id = :id AND d.is_active = 1
    LIMIT 1
");
$stmt->execute([':id' => $dishId]);
$dish = $stmt->fetch();

if (!$dish) {
    http_response_code(404);
    render_error_page(404, 'Dish Unavailable', 'This dish was not found or is currently archived.', "{$appBase}/menu/list.php");
    exit;
}

$pageTitle = e($dish['name']) . ' - UrbanEats Cloud Kitchen';
$stock = (int)$dish['current_stock'];
$stockClass = ($stock === 0) ? 'stock-out' : (($stock <= 10) ? 'stock-low' : 'stock-in');
$stockText = ($stock === 0) ? 'Currently Sold Out' : (($stock <= 10) ? "Low Stock: Only {$stock} units left!" : "Available in Kitchen ({$stock} left)");
?>

<div class="site-container" style="margin-top: 1.5rem; margin-bottom: 3.5rem;">
    <!-- Breadcrumb Navigation -->
    <nav aria-label="Breadcrumb" style="margin-bottom: 1.5rem; font-size: 0.875rem;">
        <a href="<?= $appBase ?>/index.php">Home</a> &rsaquo;
        <a href="<?= $appBase ?>/menu/list.php">Menu</a> &rsaquo;
        <a href="<?= $appBase ?>/menu/list.php?category=<?= urlencode($dish['category_slug']) ?>"><?= e($dish['category_name']) ?></a> &rsaquo;
        <span style="color: var(--text-muted);"><?= e($dish['name']) ?></span>
    </nav>

    <!-- Split Product Layout -->
    <div style="display: grid; grid-template-columns: 1fr 1.1fr; gap: 3rem; background: white; border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 2.5rem; box-shadow: var(--shadow-sm); align-items: start;">
        <!-- Left: Image Media Box -->
        <div>
            <div style="position: relative; border-radius: var(--radius-lg); overflow: hidden; background: #e2e8f0; height: 380px; box-shadow: var(--shadow-md);">
                <img src="<?= $appBase ?>/uploads/<?= e($dish['image_url'] ?: 'default_dish.jpg') ?>" 
                     alt="<?= e($dish['name']) ?>" 
                     style="width: 100%; height: 100%; object-fit: cover;"
                     onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=800&q=80'">

                <div class="dish-badges" style="top: 1rem; left: 1rem;">
                    <?php if ((int)$dish['is_vegetarian'] === 1): ?>
                        <span class="dietary-badge badge-veg">🌱 100% Vegetarian</span>
                    <?php endif; ?>
                    <?php if ((int)$dish['is_spicy'] === 1): ?>
                        <span class="dietary-badge badge-spicy">🌶️ Sri Lankan Hot & Spicy</span>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top: 1.5rem; padding: 1rem; background: var(--bg-page); border-radius: var(--radius-md); font-size: 0.85rem; color: var(--text-muted);">
                <strong>🍲 Cloud Kitchen Preparation:</strong> Each dish is prepared on-demand upon order dispatch. Cook time averages 15–20 minutes prior to delivery courier collection.
            </div>
        </div>

        <!-- Right: Dish Specs & Add to Cart -->
        <div>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                <span class="dish-category" style="font-size: 0.85rem;"><?= e($dish['category_name']) ?></span>
                <span class="stock-pill <?= $stockClass ?>" style="position: static;"><?= $stockText ?></span>
            </div>

            <h1 style="font-size: 2.25rem; margin-bottom: 0.75rem; color: var(--color-secondary);"><?= e($dish['name']) ?></h1>
            <div style="font-family: var(--font-heading); font-size: 1.85rem; font-weight: 800; color: var(--color-primary); margin-bottom: 1.5rem;">
                <?= format_price($dish['price']) ?>
            </div>

            <div style="border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 1.25rem 0; margin-bottom: 1.5rem;">
                <h3 style="font-size: 0.95rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 0.5rem;">Dish Description & Heritage</h3>
                <p style="font-size: 1rem; line-height: 1.7; color: var(--color-secondary-surface);">
                    <?= nl2br(e($dish['description'])) ?>
                </p>
            </div>

            <!-- Add to Cart Form -->
            <?php if ($stock > 0): ?>
                <form action="<?= $appBase ?>/orders/cart.php" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="dish_id" value="<?= $dish['id'] ?>">

                    <div style="display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.5rem;">
                        <label for="detailQty" style="font-weight: 700; font-size: 0.95rem;">Select Quantity:</label>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            <input type="number" 
                                   id="detailQty" 
                                   name="quantity" 
                                   value="1" 
                                   min="1" 
                                   max="<?= $stock ?>" 
                                   class="cart-qty-input" 
                                   style="width: 80px; height: 44px; font-size: 1.1rem;">
                            <span style="font-size: 0.8rem; color: var(--text-muted);">(Max <?= $stock ?>)</span>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
                            Add to Cart 🛒
                        </button>
                        <a href="<?= $appBase ?>/menu/list.php" class="btn btn-outline btn-lg">
                            Continue Browsing
                        </a>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-danger" style="margin-bottom: 1.5rem;">
                    <strong>Item Out of Stock</strong>
                    <p style="font-size: 0.85rem; margin-top: 0.25rem;">
                        Our chefs have temporarily exhausted ingredients for this dish. Fresh restocks occur daily.
                    </p>
                </div>
                <a href="<?= $appBase ?>/menu/list.php" class="btn btn-outline btn-lg">Explore Other Dishes</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
