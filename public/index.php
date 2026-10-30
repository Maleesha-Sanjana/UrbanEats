<?php

$currentPage = 'home';
$pageTitle = 'UrbanEats - Sri Lanka\'s Premier Cloud Kitchen';
require_once __DIR__ . '/../includes/header.php';

$pdo = get_db_connection();

// 1. Fetch Active Categories
$catStmt = $pdo->query("
    SELECT c.*, COUNT(d.id) AS dish_count
    FROM categories c
    LEFT JOIN dishes d ON c.id = d.category_id AND d.is_active = 1
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY c.id ASC
");
$categories = $catStmt->fetchAll();

// 2. Fetch Featured Dishes with Stock Levels
$dishStmt = $pdo->query("
    SELECT d.*, c.name AS category_name, COALESCE(i.current_stock, 0) AS current_stock
    FROM dishes d
    JOIN categories c ON d.category_id = c.id
    LEFT JOIN inventory i ON d.id = i.dish_id
    WHERE d.is_active = 1
    ORDER BY d.id ASC
    LIMIT 8
");
$featuredDishes = $dishStmt->fetchAll();
?>

<!-- Hero Banner Section -->
<section class="hero-section">
    <div class="hero-grid">
        <div class="hero-content">
            <div class="hero-tag">🔥 Freshly Fired Cloud Kitchen</div>
            <h1 class="hero-title">
                Authentic Ceylon Flavors, <br>
                <span class="hero-highlight">Delivered To Your Doorstep.</span>
            </h1>
            <p class="hero-subtitle">
                Craving smoking cheese kottu, slow-simmered Jaffna crab, or fragrantly wrapped Dutch-Burgher lamprais?
                We prepare artisan Sri Lankan dishes on-demand with zero dining room overhead.
            </p>
            <div class="hero-cta">
                <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary btn-lg">Browse Full Menu 🍛</a>
                <a href="#featured" class="btn btn-outline btn-lg" style="color: white; border-color: rgba(255,255,255,0.3);">Today's Specials</a>
            </div>
        </div>
        <div class="hero-stats">
            <div class="stat-card">
                <div class="stat-num">35m</div>
                <div class="stat-label">Avg. Delivery Colombo</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">100%</div>
                <div class="stat-label">Halal & Fresh Spices</div>
            </div>
            <div class="stat-card">
                <div class="stat-num">LKR</div>
                <div class="stat-label">Transparent Pricing</div>
            </div>
        </div>
    </div>
</section>

<!-- Active Promotional Coupon Banner -->
<div class="site-container" style="margin-bottom: 2.5rem;">
    <div style="background: linear-gradient(90deg, #fff7ed, #ffedd5); border: 2px dashed #ea580c; border-radius: var(--radius-lg); padding: 1.25rem 1.75rem; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem;">
        <div style="display: flex; align-items: center; gap: 1rem;">
            <span style="font-size: 2rem;">🏷️</span>
            <div>
                <strong style="color: #9a3412; font-size: 1.1rem; display: block;">Enjoy 15% Off Your First Online Order!</strong>
                <span style="color: #c2410c; font-size: 0.875rem;">Use promo voucher code <code style="background: white; padding: 0.2rem 0.5rem; border-radius: 4px; font-weight: 700; color: #ea580c; border: 1px solid #fdba74;">FIRSTORDER</code> at checkout (Min. LKR 1,000.00).</span>
            </div>
        </div>
        <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary-sm">Order & Apply</a>
    </div>
</div>

<!-- Category Carousel / Pills -->
<section class="site-container" style="margin-bottom: 3rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.25rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Explore Categories</span>
            <h2 style="font-size: 1.85rem; margin-top: 0.25rem;">Hand-Crafted Menu Collections</h2>
        </div>
        <a href="<?= $appBase ?>/menu/list.php" style="font-weight: 700; font-size: 0.9rem;">View All Categories &rarr;</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem;">
        <?php foreach ($categories as $cat): ?>
            <a href="<?= $appBase ?>/menu/list.php?category=<?= urlencode($cat['slug']) ?>" 
               style="background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 1.25rem 1rem; text-align: center; box-shadow: var(--shadow-xs); transition: all 0.2s ease; display: block;">
                <div style="font-size: 2.25rem; margin-bottom: 0.5rem;">
                    <?php
                    $icons = [
                        'rice-curry' => '🍛',
                        'kottu-roti' => '🥘',
                        'biryani-specials' => '🍗',
                        'short-eats' => '🥟',
                        'desserts' => '🍮',
                        'beverages' => '🥥'
                    ];
                    echo $icons[$cat['slug']] ?? '🍲';
                    ?>
                </div>
                <h3 style="font-size: 1rem; margin-bottom: 0.25rem;"><?= e($cat['name']) ?></h3>
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 600;"><?= (int)$cat['dish_count'] ?> Signature Dishes</span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Featured Signature Dishes Section -->
<section class="site-container" id="featured" style="margin-bottom: 3.5rem;">
    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 1.5rem;">
        <div>
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Chef's Recommendations</span>
            <h2 style="font-size: 1.85rem; margin-top: 0.25rem;">Popular UrbanEats Specials</h2>
        </div>
        <a href="<?= $appBase ?>/menu/list.php" class="btn btn-outline-sm">Full Menu Catalogue</a>
    </div>

    <div class="dishes-grid">
        <?php foreach ($featuredDishes as $dish): ?>
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
                                <button type="submit" class="btn btn-primary-sm">Add +</button>
                            </form>
                        <?php else: ?>
                            <button type="button" class="btn btn-outline-sm" disabled style="opacity: 0.5;">Unavailable</button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<!-- Why Cloud Kitchen Section -->
<section style="background: white; border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 3.5rem 0;">
    <div class="site-container">
        <div style="text-align: center; max-width: 650px; margin: 0 auto 3rem;">
            <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">The UrbanEats Difference</span>
            <h2 style="font-size: 2rem; margin-top: 0.35rem;">Why Order From a Cloud Kitchen?</h2>
            <p style="color: var(--text-muted); font-size: 0.95rem; margin-top: 0.5rem;">
                No dining rooms or waiter services means 100% of our energy, investment, and passion goes directly into high-quality ingredients, authentic spice grinding, and express delivery.
            </p>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 2rem;">
            <div style="padding: 1.5rem; border-radius: var(--radius-md); background: var(--bg-page); border: 1px solid var(--border-color);">
                <div style="font-size: 2rem; margin-bottom: 0.75rem;">⚡</div>
                <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Fired Fresh to Order</h3>
                <p style="font-size: 0.875rem; color: var(--text-muted);">
                    Dishes are never sitting under heat lamps. Kottu is chopped fresh on the griddle the moment your order reaches our kitchen screen.
                </p>
            </div>
            <div style="padding: 1.5rem; border-radius: var(--radius-md); background: var(--bg-page); border: 1px solid var(--border-color);">
                <div style="font-size: 2rem; margin-bottom: 0.75rem;">📦</div>
                <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Thermal Tamper-Proof Packaging</h3>
                <p style="font-size: 0.875rem; color: var(--text-muted);">
                    Custom vented boxes retain crispness for hot butter cuttlefish, while banana leaf wraps keep lamprais intensely aromatic and hot.
                </p>
            </div>
            <div style="padding: 1.5rem; border-radius: var(--radius-md); background: var(--bg-page); border: 1px solid var(--border-color);">
                <div style="font-size: 2rem; margin-bottom: 0.75rem;">📍</div>
                <h3 style="font-size: 1.15rem; margin-bottom: 0.5rem;">Real-Time Status Tracking</h3>
                <p style="font-size: 0.875rem; color: var(--text-muted);">
                    Watch your food move smoothly through Placed &rarr; Preparing &rarr; Ready &rarr; Out for Delivery with live timeline updates.
                </p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
