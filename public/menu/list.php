<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component B: Public Menu Catalogue
 *
 * Implements category filtering, keyword search, price & name sorting (whitelisted),
 * dietary filters (vegetarian, spicy), and secure paginated database queries.
 */

$currentPage = 'menu';
$pageTitle = 'Explore Our Menu - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

$pdo = get_db_connection();

// 1. Fetch Categories for Filter Chips
$catStmt = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY id ASC");
$allCategories = $catStmt->fetchAll();

// 2. Validate and Whitelist GET Query Parameters
$search = trim($_GET['search'] ?? '');
$categorySlug = trim($_GET['category'] ?? '');
$sort = trim($_GET['sort'] ?? 'default');
$dietary = trim($_GET['dietary'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 9;
$offset = ($page - 1) * $perPage;

$whereClauses = ['d.is_active = 1'];
$params = [];

// Search filter
if (!empty($search)) {
    $whereClauses[] = "(d.name LIKE :search_name OR d.description LIKE :search_desc)";
    $params[':search_name'] = "%{$search}%";
    $params[':search_desc'] = "%{$search}%";
}

// Category filter
$selectedCategory = null;
if (!empty($categorySlug)) {
    $cStmt = $pdo->prepare("SELECT id, name, slug FROM categories WHERE slug = :slug AND is_active = 1 LIMIT 1");
    $cStmt->execute([':slug' => $categorySlug]);
    $selectedCategory = $cStmt->fetch();
    if ($selectedCategory) {
        $whereClauses[] = "d.category_id = :cat_id";
        $params[':cat_id'] = $selectedCategory['id'];
    }
}

// Dietary filters
if ($dietary === 'veg') {
    $whereClauses[] = "d.is_vegetarian = 1";
} elseif ($dietary === 'spicy') {
    $whereClauses[] = "d.is_spicy = 1";
}

// Whitelist Order By Clause (Prevent SQL injection through sort parameter)
$orderSql = match ($sort) {
    'price_asc'  => 'd.price ASC',
    'price_desc' => 'd.price DESC',
    'name_asc'   => 'd.name ASC',
    'name_desc'  => 'd.name DESC',
    default      => 'd.id ASC'
};

$whereSql = implode(' AND ', $whereClauses);

// Count Total Matching Dishes
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    WHERE $whereSql
");
$countStmt->execute($params);
$totalDishes = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalDishes / $perPage));

// Query Paginated Dishes
$dishQuery = "
    SELECT d.*, c.name AS category_name, c.slug AS category_slug,
           COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE $whereSql
    ORDER BY $orderSql
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($dishQuery);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$dishes = $stmt->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header & Title -->
    <div style="margin-bottom: 2rem;">
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component B: Cloud Kitchen Catalogue</span>
        <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">
            <?= $selectedCategory ? e($selectedCategory['name']) : 'Explore Our Full Menu' ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem;">
            Prepared freshly with high-grade local ingredients. Filter, search, or sort dishes below.
        </p>
    </div>

    <!-- Category Filter Pills / Carousel -->
    <div class="category-pills">
        <a href="<?= $appBase ?>/menu/list.php?<?= http_build_query(array_merge($_GET, ['category' => ''])) ?>" 
           class="category-pill <?= empty($categorySlug) ? 'active' : '' ?>">
            All Categories (<?= $totalDishes ?>)
        </a>
        <?php foreach ($allCategories as $cat): ?>
            <a href="<?= $appBase ?>/menu/list.php?<?= http_build_query(array_merge($_GET, ['category' => $cat['slug'], 'page' => 1])) ?>" 
               class="category-pill <?= $categorySlug === $cat['slug'] ? 'active' : '' ?>">
                <?= e($cat['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search, Sort & Dietary Filters -->
    <div class="menu-controls">
        <form action="<?= $appBase ?>/menu/list.php" method="GET" class="filter-form">
            <?php if (!empty($categorySlug)): ?>
                <input type="hidden" name="category" value="<?= e($categorySlug) ?>">
            <?php endif; ?>

            <div class="search-box">
                <input type="text" 
                       name="search" 
                       class="form-control" 
                       placeholder="Search dishes (e.g. kottu, lamprais, biryani)..." 
                       value="<?= e($search) ?>">
            </div>

            <div class="filter-selects">
                <!-- Sorting Whitelist -->
                <select name="sort" class="form-control" style="width: auto;">
                    <option value="default" <?= $sort === 'default' ? 'selected' : '' ?>>Sort: Recommended</option>
                    <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name: A to Z</option>
                    <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name: Z to A</option>
                </select>

                <!-- Dietary Filter -->
                <select name="dietary" class="form-control" style="width: auto;">
                    <option value="">Dietary: All</option>
                    <option value="veg" <?= $dietary === 'veg' ? 'selected' : '' ?>>🌱 Vegetarian Only</option>
                    <option value="spicy" <?= $dietary === 'spicy' ? 'selected' : '' ?>>🌶️ Spicy Only</option>
                </select>

                <button type="submit" class="btn btn-secondary">Apply Filters</button>
                <?php if (!empty($search) || !empty($categorySlug) || $sort !== 'default' || !empty($dietary)): ?>
                    <a href="<?= $appBase ?>/menu/list.php" class="btn btn-outline">Reset All</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Dish Grid -->
    <?php if (empty($dishes)): ?>
        <div style="background: white; border: 1px dashed var(--border-color); border-radius: var(--radius-lg); padding: 3.5rem 2rem; text-align: center; margin-top: 1.5rem;">
            <span style="font-size: 3rem; display: block; margin-bottom: 0.5rem;">🔍</span>
            <h3 style="font-size: 1.25rem;">No dishes match your selected filters</h3>
            <p style="color: var(--text-muted); margin-bottom: 1.5rem; font-size: 0.9rem;">
                Try searching with different keywords or clearing your active filters.
            </p>
            <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary">Clear All Filters</a>
        </div>
    <?php else: ?>
        <div class="dishes-grid">
            <?php foreach ($dishes as $dish): ?>
                <?php
                $stock = (int)$dish['current_stock'];
                $stockClass = ($stock === 0) ? 'stock-out' : (($stock <= 10) ? 'stock-low' : 'stock-in');
                $stockText = ($stock === 0) ? 'Sold Out' : (($stock <= 10) ? "Only {$stock} Left" : 'In Stock');
                ?>
                <article class="dish-card">
                    <div class="dish-media">
                        <img src="<?= $appBase ?>/uploads/<?= e($dish['image_url'] ?: 'default_dish.jpg') ?>" 
                             alt="<?= e($dish['name']) ?>" 
                             class="dish-img"
                             onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=600&q=80'">

                        <div class="dish-badges">
                            <?php if ((int)$dish['is_vegetarian'] === 1): ?>
                                <span class="dietary-badge badge-veg">🌱 Pure Veg</span>
                            <?php endif; ?>
                            <?php if ((int)$dish['is_spicy'] === 1): ?>
                                <span class="dietary-badge badge-spicy">🌶️ Spicy</span>
                            <?php endif; ?>
                        </div>

                        <span class="stock-pill <?= $stockClass ?>"><?= $stockText ?></span>
                    </div>

                    <div class="dish-content">
                        <span class="dish-category"><?= e($dish['category_name']) ?></span>
                        <h3 class="dish-name">
                            <a href="<?= $appBase ?>/menu/detail.php?id=<?= $dish['id'] ?>"><?= e($dish['name']) ?></a>
                        </h3>
                        <p class="dish-desc"><?= e($dish['description']) ?></p>

                        <div class="dish-footer">
                            <span class="dish-price"><?= format_price($dish['price']) ?></span>
                            
                            <?php if ($stock > 0): ?>
                                <form action="<?= $appBase ?>/orders/cart.php" method="POST" style="margin: 0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="dish_id" value="<?= $dish['id'] ?>">
                                    <input type="hidden" name="quantity" value="1">
                                    <button type="submit" class="btn btn-primary-sm" title="Add 1 to cart">
                                        Add to Cart 🛒
                                    </button>
                                </form>
                            <?php else: ?>
                                <button type="button" class="btn btn-outline-sm" disabled style="opacity: 0.5;">Sold Out</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])) ?>" 
                   class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">&larr; Prev</a>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                       class="page-btn <?= $page === $i ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>

                <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPages, $page + 1)])) ?>" 
                   class="page-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">Next &rarr;</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
