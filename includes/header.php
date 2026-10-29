<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/errors.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Dynamically determine base URL if not explicitly set
$scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
$publicPos = strpos($scriptPath, '/public');
$appBase = ($publicPos !== false) ? substr($scriptPath, 0, $publicPos + 7) : '/UrbanEats/public';

// Calculate cart count
$cartItemCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cartItemCount += (int)$qty;
    }
}

$pageTitle = $pageTitle ?? 'UrbanEats - Sri Lanka\'s Premier Cloud Kitchen';
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- Main Hand-written Stylesheet -->
    <link rel="stylesheet" href="<?= $appBase ?>/css/style.css">
</head>
<body class="site-body">
    <!-- Top Alert / Announcement Bar -->
    <div class="announcement-bar">
        <div class="site-container announcement-content">
            <span>🔥 Authentic Sri Lankan Street Food & Culinary Delights • Express Delivery Across Colombo</span>
            <span class="delivery-time">⏰ Orders Open: 10:30 AM - 11:00 PM</span>
        </div>
    </div>

    <!-- Main Navigation Header -->
    <header class="site-header" id="mainHeader">
        <div class="site-container header-inner">
            <!-- Brand Logo -->
            <a href="<?= $appBase ?>/index.php" class="brand-logo" aria-label="UrbanEats Cloud Kitchen">
                <div class="logo-mark">
                    <span class="logo-flame">🍲</span>
                </div>
                <div class="logo-text">
                    <span class="brand-name">Urban<span class="brand-accent">Eats</span></span>
                    <span class="brand-tagline">Cloud Kitchen Platform</span>
                </div>
            </a>

            <!-- Mobile Nav Toggle -->
            <button type="button" class="nav-toggle" id="navToggle" aria-label="Toggle navigation menu" aria-expanded="false">
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
                <span class="hamburger-bar"></span>
            </button>

            <!-- Dynamic Navigation Menu -->
            <nav class="main-nav" id="mainNav" aria-label="Primary Navigation">
                <ul class="nav-list">
                    <li class="nav-item">
                        <a href="<?= $appBase ?>/index.php" class="nav-link <?= ($currentPage ?? '') === 'home' ? 'active' : '' ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a href="<?= $appBase ?>/menu/list.php" class="nav-link <?= ($currentPage ?? '') === 'menu' ? 'active' : '' ?>">Our Menu</a>
                    </li>

                    <?php if (has_role('customer')): ?>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/orders/history.php" class="nav-link <?= ($currentPage ?? '') === 'orders' ? 'active' : '' ?>">My Orders</a>
                        </li>
                    <?php endif; ?>

                    <?php if (has_role('kitchen_staff')): ?>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/orders/kitchen.php" class="nav-link badge-nav <?= ($currentPage ?? '') === 'kitchen' ? 'active' : '' ?>">
                                <span>Kitchen Queue</span>
                                <span class="nav-badge-pill">Staff</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/menu/stock.php" class="nav-link <?= ($currentPage ?? '') === 'stock' ? 'active' : '' ?>">Inventory</a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/menu/manage_dishes.php" class="nav-link <?= ($currentPage ?? '') === 'dishes' ? 'active' : '' ?>">Dishes</a>
                        </li>
                    <?php endif; ?>

                    <?php if (has_role('admin')): ?>
                        <li class="nav-item dropdown">
                            <a href="<?= $appBase ?>/admin/dashboard.php" class="nav-link <?= ($currentPage ?? '') === 'admin' ? 'active' : '' ?>">
                                <span>Admin Panel</span>
                                <span class="nav-badge-pill admin-pill">Admin</span>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/admin/reports.php" class="nav-link <?= ($currentPage ?? '') === 'reports' ? 'active' : '' ?>">Sales Reports</a>
                        </li>
                        <li class="nav-item">
                            <a href="<?= $appBase ?>/orders/kitchen.php" class="nav-link <?= ($currentPage ?? '') === 'kitchen' ? 'active' : '' ?>">Orders</a>
                        </li>
                    <?php endif; ?>
                </ul>

                <!-- Right Actions: Cart & User Account -->
                <div class="nav-actions">
                    <!-- Cart Button (Visible for all visitors and customers) -->
                    <?php if (!has_role('kitchen_staff') && !has_role('admin')): ?>
                        <a href="<?= $appBase ?>/orders/cart.php" class="cart-btn <?= ($currentPage ?? '') === 'cart' ? 'active' : '' ?>" aria-label="View Shopping Cart">
                            <span class="cart-icon">🛒</span>
                            <span class="cart-text">Cart</span>
                            <span class="cart-count" id="cartCountBadge"><?= $cartItemCount ?></span>
                        </a>
                    <?php endif; ?>

                    <!-- Account / Authentication Links -->
                    <?php if (is_logged_in()): ?>
                        <div class="user-dropdown">
                            <button type="button" class="user-btn" id="userMenuBtn" aria-expanded="false">
                                <div class="user-avatar"><?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?></div>
                                <div class="user-info-text">
                                    <span class="user-name"><?= e($currentUser['name'] ?? 'User') ?></span>
                                    <span class="user-role-label"><?= ucfirst(str_replace('_', ' ', $currentUser['role_name'] ?? '')) ?></span>
                                </div>
                                <span class="dropdown-chevron">▾</span>
                            </button>
                            <div class="user-menu-dropdown" id="userMenuDropdown">
                                <?php if (has_role('admin')): ?>
                                    <a href="<?= $appBase ?>/admin/dashboard.php" class="dropdown-item">Admin Dashboard</a>
                                    <a href="<?= $appBase ?>/admin/users.php" class="dropdown-item">Manage Users</a>
                                    <a href="<?= $appBase ?>/admin/audit_log.php" class="dropdown-item">System Audit Log</a>
                                    <div class="dropdown-divider"></div>
                                <?php endif; ?>
                                
                                <a href="<?= $appBase ?>/auth/profile.php" class="dropdown-item">Profile & Password</a>
                                
                                <?php if (has_role('customer')): ?>
                                    <a href="<?= $appBase ?>/orders/history.php" class="dropdown-item">Order History</a>
                                <?php endif; ?>
                                
                                <div class="dropdown-divider"></div>
                                <a href="<?= $appBase ?>/auth/logout.php" class="dropdown-item text-danger">Logout</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="auth-buttons">
                            <a href="<?= $appBase ?>/auth/login.php" class="btn btn-outline-sm">Log In</a>
                            <a href="<?= $appBase ?>/auth/register.php" class="btn btn-primary-sm">Register</a>
                        </div>
                    <?php endif; ?>
                </div>
            </nav>
        </div>
    </header>

    <!-- Main Content Wrapper with Auto-Rendered Flash Messages -->
    <main class="site-main" id="mainContent">
        <div class="site-container">
            <?= render_flash_messages() ?>
        </div>
