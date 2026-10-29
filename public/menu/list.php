<?php

$currentPage = 'menu';
$pageTitle = 'Explore Our Menu - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

$pdo = get_db_connection();

// 1. Fetch all active categories for the filter pill navigation
$categoryStmt = $pdo->query("
    SELECT id, name, slug, description 
    FROM categories 
    WHERE is_active = 1 
    ORDER BY id ASC
");
$allCategories = $categoryStmt->fetchAll();

// 2. Sanitize and validate incoming GET filter parameters
$searchQuery  = trim($_GET['search'] ?? '');
$categorySlug = trim($_GET['category'] ?? '');
$sortKey      = trim($_GET['sort'] ?? 'default');
$dietaryType  = trim($_GET['dietary'] ?? '');
$currentPageNum = max(1, (int)($_GET['page'] ?? 1));
$itemsPerPage = 9;
$offset = ($currentPageNum - 1) * $itemsPerPage;

// Build dynamic WHERE clause using prepared statement parameter binding
$whereConditions = ['d.is_active = 1'];
$queryParams     = [];

// Keyword search across dish name and culinary description
if ($searchQuery !== '') {
    $whereConditions[] = "(d.name LIKE :search_kw OR d.description LIKE :search_desc)";
    $queryParams[':search_kw']   = '%' . $searchQuery . '%';
    $queryParams[':search_desc'] = '%' . $searchQuery . '%';
}

// Category filter by URL slug
$currentCategory = null;
if ($categorySlug !== '') {
    $catQueryStmt = $pdo->prepare("
        SELECT id, name, slug 
        FROM categories 
        WHERE slug = :slug AND is_active = 1 
        LIMIT 1
    ");
    $catQueryStmt->execute([':slug' => $categorySlug]);
    $currentCategory = $catQueryStmt->fetch();

    if ($currentCategory) {
        $whereConditions[] = "d.category_id = :selected_cat_id";
        $queryParams[':selected_cat_id'] = (int)$currentCategory['id'];
    }
}

// Dietary preference filters
if ($dietaryType === 'veg') {
    $whereConditions[] = "d.is_vegetarian = 1";
} elseif ($dietaryType === 'spicy') {
    $whereConditions[] = "d.is_spicy = 1";
}

// Whitelist sorting parameters to prevent SQL injection
$sortOrderBy = match ($sortKey) {
    'price_asc'  => 'd.price ASC',
    'price_desc' => 'd.price DESC',
    'name_asc'   => 'd.name ASC',
    'name_desc'  => 'd.name DESC',
    default      => 'd.id ASC'
};

$compiledWhereClause = implode(' AND ', $whereConditions);

// 3. Count total matching records for pagination math
$countSql = "
    SELECT COUNT(*) 
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    WHERE {$compiledWhereClause}
";
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($queryParams);
$totalMatchingDishes = (int)$countStmt->fetchColumn();
$totalPageCount = max(1, (int)ceil($totalMatchingDishes / $itemsPerPage));

// 4. Retrieve paginated dish dataset joined with categories & inventory stock
$paginatedDishesSql = "
    SELECT d.id, d.category_id, d.name, d.description, d.price, 
           d.image_url, d.is_spicy, d.is_vegetarian, d.is_active,
           c.name AS category_name, c.slug AS category_slug,
           COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE {$compiledWhereClause}
    ORDER BY {$sortOrderBy}
    LIMIT :page_limit OFFSET :page_offset
";

$dishesStmt = $pdo->prepare($paginatedDishesSql);

// Bind search & category criteria
foreach ($queryParams as $paramKey => $paramValue) {
    $dishesStmt->bindValue($paramKey, $paramValue);
}

// Bind integer parameters explicitly for LIMIT/OFFSET
$dishesStmt->bindValue(':page_limit', $itemsPerPage, PDO::PARAM_INT);
$dishesStmt->bindValue(':page_offset', $offset, PDO::PARAM_INT);
$dishesStmt->execute();
$dishes = $dishesStmt->fetchAll();
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Title & Header Info -->
    <div style="margin-bottom: 2rem;">
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">
            Component B: Cloud Kitchen Catalogue
        </span>
        <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">
            <?= $currentCategory ? e($currentCategory['name']) : 'Explore Our Full Menu' ?>
        </h1>
        <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.25rem;">
            Authentic Sri Lankan flavors cooked fresh per order. Filter by category, dietary choices, or price below.
        </p>
    </div>

    <!-- Category Filter Pills Navigation -->
    <div class="category-pills">
        <a href="<?= $appBase ?>/menu/list.php?<?= http_build_query(array_merge($_GET, ['category' => '', 'page' => 1])) ?>" 
           class="category-pill <?= empty($categorySlug) ? 'active' : '' ?>">
            All Dishes (<?= $totalMatchingDishes ?>)
        </a>
        <?php foreach ($allCategories as $category): ?>
            <a href="<?= $appBase ?>/menu/list.php?<?= http_build_query(array_merge($_GET, ['category' => $category['slug'], 'page' => 1])) ?>" 
               class="category-pill <?= $categorySlug === $category['slug'] ? 'active' : '' ?>">
                <?= e($category['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <!-- Search, Sort & Dietary Filter Toolbar -->
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
                       value="<?= e($searchQuery) ?>">
            </div>

            <div class="filter-selects">
                <!-- Whitelisted Sort Selector -->
                <select name="sort" class="form-control" style="width: auto;">
                    <option value="default" <?= $sortKey === 'default' ? 'selected' : '' ?>>Sort: Recommended</option>
                    <option value="price_asc" <?= $sortKey === 'price_asc' ? 'selected' : '' ?>>Price: Low to High</option>
                    <option value="price_desc" <?= $sortKey === 'price_desc' ? 'selected' : '' ?>>Price: High to Low</option>
                    <option value="name_asc" <?= $sortKey === 'name_asc' ? 'selected' : '' ?>>Name: A to Z</option>
                    <option value="name_desc" <?= $sortKey === 'name_desc' ? 'selected' : '' ?>>Name: Z to A</option>
                </select>

                <!-- Dietary Options Selector -->
                <select name="dietary" class="form-control" style="width: auto;">
                    <option value="">Dietary: All</option>
                    <option value="veg" <?= $dietaryType === 'veg' ? 'selected' : '' ?>>🌱 Vegetarian Only</option>
                    <option value="spicy" <?= $dietaryType === 'spicy' ? 'selected' : '' ?>>🌶️ Spicy Only</option>
                </select>

                <button type="submit" class="btn btn-secondary">Apply Filters</button>
                <?php if (!empty($searchQuery) || !empty($categorySlug) || $sortKey !== 'default' || !empty($dietaryType)): ?>
                    <a href="<?= $appBase ?>/menu/list.php" class="btn btn-outline">Reset All</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Dish Grid Cards -->
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
                $dishStock = (int)$dish['current_stock'];
                $stockStatusClass = ($dishStock === 0) ? 'stock-out' : (($dishStock <= 10) ? 'stock-low' : 'stock-in');
                $stockStatusLabel = ($dishStock === 0) ? 'Sold Out' : (($dishStock <= 10) ? "Only {$dishStock} Left" : 'In Stock');
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

                        <span class="stock-pill <?= $stockStatusClass ?>"><?= $stockStatusLabel ?></span>
                    </div>

                    <div class="dish-content">
                        <span class="dish-category"><?= e($dish['category_name']) ?></span>
                        <h3 class="dish-name">
                            <a href="<?= $appBase ?>/menu/detail.php?id=<?= $dish['id'] ?>"><?= e($dish['name']) ?></a>
                        </h3>
                        <p class="dish-desc"><?= e($dish['description']) ?></p>

                        <div class="dish-footer">
                            <span class="dish-price"><?= format_price($dish['price']) ?></span>
                            
                            <?php if ($dishStock > 0): ?>
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

        <!-- Pagination Controls -->
        <?php if ($totalPageCount > 1): ?>
            <div class="pagination">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $currentPageNum - 1)])) ?>" 
                   class="page-btn <?= $currentPageNum <= 1 ? 'disabled' : '' ?>">&larr; Prev</a>

                <?php for ($pageIndex = 1; $pageIndex <= $totalPageCount; $pageIndex++): ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $pageIndex])) ?>" 
                       class="page-btn <?= $currentPageNum === $pageIndex ? 'active' : '' ?>"><?= $pageIndex ?></a>
                <?php endfor; ?>

                <a href="?<?= http_build_query(array_merge($_GET, ['page' => min($totalPageCount, $currentPageNum + 1)])) ?>" 
                   class="page-btn <?= $currentPageNum >= $totalPageCount ? 'disabled' : '' ?>">Next &rarr;</a>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
