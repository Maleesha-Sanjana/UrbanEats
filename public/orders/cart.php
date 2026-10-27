<?php
/**
 * UrbanEats Cloud Kitchen Platform
 * Component C: Shopping Cart & Totals Calculation
 *
 * Implements session-based cart, live database price verification
 * (never trusts browser prices), coupon code preview, and stock validation.
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/errors.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';

$pdo = get_db_connection();

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// 1. Handle Cart Actions (Add, Update, Remove, Clear, Coupon)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validate_csrf_or_fail();

    $action = $_POST['action'] ?? '';
    $dishId = (int)($_POST['dish_id'] ?? 0);

    if ($action === 'add') {
        $qty = max(1, (int)($_POST['quantity'] ?? 1));
        if ($dishId > 0) {
            // Check current stock from DB
            $sStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = :id");
            $sStmt->execute([':id' => $dishId]);
            $availableStock = (int)$sStmt->fetchColumn();

            $currentInCart = (int)($_SESSION['cart'][$dishId] ?? 0);
            $newQty = $currentInCart + $qty;

            if ($newQty > $availableStock) {
                set_flash('warning', "Cannot add more than available kitchen stock ({$availableStock} units).");
                $_SESSION['cart'][$dishId] = max(1, $availableStock);
            } else {
                $_SESSION['cart'][$dishId] = $newQty;
                set_flash('success', 'Item added to your shopping cart!');
            }
        }
    } elseif ($action === 'update') {
        $qty = (int)($_POST['quantity'] ?? 1);
        if ($dishId > 0) {
            if ($qty <= 0) {
                unset($_SESSION['cart'][$dishId]);
                set_flash('info', 'Item removed from cart.');
            } else {
                $sStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE dish_id = :id");
                $sStmt->execute([':id' => $dishId]);
                $availableStock = (int)$sStmt->fetchColumn();

                if ($qty > $availableStock) {
                    $_SESSION['cart'][$dishId] = $availableStock;
                    set_flash('warning', "Quantity adjusted to maximum available stock ({$availableStock} units).");
                } else {
                    $_SESSION['cart'][$dishId] = $qty;
                    set_flash('success', 'Cart updated successfully.');
                }
            }
        }
    } elseif ($action === 'remove') {
        if ($dishId > 0) {
            unset($_SESSION['cart'][$dishId]);
            set_flash('info', 'Item removed from cart.');
        }
    } elseif ($action === 'clear') {
        $_SESSION['cart'] = [];
        unset($_SESSION['coupon_code']);
        set_flash('info', 'Your cart has been cleared.');
    } elseif ($action === 'apply_coupon') {
        $couponCode = trim(strtoupper($_POST['coupon_code'] ?? ''));
        if (!empty($couponCode)) {
            $_SESSION['coupon_code'] = $couponCode;
        } else {
            unset($_SESSION['coupon_code']);
        }
    }

    header("Location: {$appBase}/orders/cart.php");
    exit;
}

$currentPage = 'cart';
$pageTitle = 'Shopping Cart - UrbanEats Cloud Kitchen';
require_once __DIR__ . '/../../includes/header.php';

// 2. Fetch Live Cart Details from DB
$cartData = get_cart_details($pdo);
$cartItems = $cartData['items'];
$subtotal = $cartData['subtotal'];
$hasStockIssue = $cartData['has_stock_issue'];

// 3. Evaluate Applied Coupon
$couponDiscount = 0.0;
$couponError = '';
$appliedCouponCode = $_SESSION['coupon_code'] ?? '';

if (!empty($appliedCouponCode) && $subtotal > 0) {
    $couponValidation = validate_coupon($pdo, $appliedCouponCode, $subtotal);
    if ($couponValidation['valid']) {
        $couponDiscount = $couponValidation['discount_amount'];
    } else {
        $couponError = $couponValidation['error'];
        unset($_SESSION['coupon_code']);
    }
}
?>

<div class="site-container" style="margin-top: 1rem; margin-bottom: 3.5rem;">
    <!-- Page Header -->
    <div style="margin-bottom: 2rem;">
        <span style="font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--color-primary); font-weight: 700;">Component C: Ordering & Checkout</span>
        <h1 style="font-size: 2.25rem; margin-top: 0.25rem;">Your Cloud Kitchen Order Cart</h1>
    </div>

    <?php if (empty($cartItems)): ?>
        <div style="background: white; border: 1px dashed var(--border-color); border-radius: var(--radius-lg); padding: 4rem 2rem; text-align: center;">
            <span style="font-size: 3.5rem; display: block; margin-bottom: 0.75rem;">🛒</span>
            <h2 style="font-size: 1.5rem; margin-bottom: 0.5rem;">Your Cart is Currently Empty</h2>
            <p style="color: var(--text-muted); margin-bottom: 1.75rem; font-size: 0.95rem;">
                Explore our signature Sri Lankan kottu, rice & curry, and traditional short eats!
            </p>
            <a href="<?= $appBase ?>/menu/list.php" class="btn btn-primary btn-lg">Explore Menu Catalogue 🍛</a>
        </div>
    <?php else: ?>
        <?php if ($hasStockIssue): ?>
            <div class="alert alert-warning" style="margin-bottom: 1.5rem;">
                ⚠️ One or more items in your cart exceeds the chef's current available stock. Please adjust quantities before checkout.
            </div>
        <?php endif; ?>

        <div class="cart-layout">
            <!-- Left: Cart Items Table -->
            <div class="cart-table-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                    <h2 style="font-size: 1.25rem;">Items in Order (<?= $cartData['item_count'] ?>)</h2>
                    <form action="<?= $appBase ?>/orders/cart.php" method="POST" style="margin: 0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clear">
                        <button type="submit" class="btn btn-outline-sm text-danger" onclick="return confirm('Empty all items from your cart?');">
                            Clear Cart
                        </button>
                    </form>
                </div>

                <div style="overflow-x: auto;">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>Dish Description</th>
                                <th>Price</th>
                                <th>Quantity</th>
                                <th>Line Total</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cartItems as $item): ?>
                                <tr>
                                    <td>
                                        <div class="cart-item-info">
                                            <img src="<?= $appBase ?>/uploads/<?= e($item['image_url'] ?: 'default_dish.jpg') ?>" 
                                                 alt="<?= e($item['name']) ?>" 
                                                 class="cart-thumb"
                                                 onerror="this.src='https://images.unsplash.com/photo-1546069901-ba9599a7e63c?auto=format&fit=crop&w=120&q=80'">
                                            <div>
                                                <strong>
                                                    <a href="<?= $appBase ?>/menu/detail.php?id=<?= $item['dish_id'] ?>">
                                                        <?= e($item['name']) ?>
                                                    </a>
                                                </strong>
                                                <span style="display: block; font-size: 0.75rem; color: var(--text-muted);"><?= e($item['category_name']) ?></span>
                                                <?php if ($item['is_stock_low']): ?>
                                                    <span style="font-size: 0.75rem; color: var(--color-danger); font-weight: 700;">
                                                        Only <?= $item['current_stock'] ?> available!
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td><?= format_price($item['price']) ?></td>
                                    <td>
                                        <form action="<?= $appBase ?>/orders/cart.php" method="POST" style="display: flex; align-items: center; gap: 0.35rem; margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update">
                                            <input type="hidden" name="dish_id" value="<?= $item['dish_id'] ?>">
                                            <input type="number" 
                                                   name="quantity" 
                                                   value="<?= $item['quantity'] ?>" 
                                                   min="1" 
                                                   max="<?= $item['current_stock'] ?>" 
                                                   class="cart-qty-input" 
                                                   onchange="this.form.submit()">
                                        </form>
                                    </td>
                                    <td>
                                        <strong><?= format_price($item['line_total']) ?></strong>
                                    </td>
                                    <td style="text-align: right;">
                                        <form action="<?= $appBase ?>/orders/cart.php" method="POST" style="margin: 0;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="remove">
                                            <input type="hidden" name="dish_id" value="<?= $item['dish_id'] ?>">
                                            <button type="submit" class="btn btn-outline-sm text-danger" title="Remove item" style="padding: 0.25rem 0.5rem;">
                                                &times;
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 1.5rem;">
                    <a href="<?= $appBase ?>/menu/list.php" style="font-weight: 600; font-size: 0.9rem;">
                        &larr; Add More Dishes From Menu
                    </a>
                </div>
            </div>

            <!-- Right: Order Summary & Coupon Card -->
            <div class="summary-card">
                <h2 style="font-size: 1.25rem; margin-bottom: 1.25rem; padding-bottom: 0.75rem; border-bottom: 1px solid var(--border-color);">
                    Order Summary
                </h2>

                <div class="cart-summary-row">
                    <span>Items Subtotal</span>
                    <span><?= format_price($subtotal) ?></span>
                </div>

                <!-- Coupon Input Form -->
                <form action="<?= $appBase ?>/orders/cart.php" method="POST" class="coupon-box">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="apply_coupon">
                    <input type="text" 
                           name="coupon_code" 
                           class="form-control" 
                           placeholder="Coupon Code" 
                           value="<?= e($appliedCouponCode) ?>" 
                           style="text-transform: uppercase;">
                    <button type="submit" class="btn btn-secondary btn-sm">Apply</button>
                </form>

                <?php if (!empty($couponError)): ?>
                    <p class="form-hint" style="color: var(--color-danger); margin-top: -0.75rem; margin-bottom: 0.75rem;"><?= e($couponError) ?></p>
                <?php endif; ?>

                <?php if ($couponDiscount > 0): ?>
                    <div class="cart-summary-row" style="color: #16a34a; font-weight: 700;">
                        <span>Voucher Discount (<?= e($appliedCouponCode) ?>)</span>
                        <span>- <?= format_price($couponDiscount) ?></span>
                    </div>
                <?php endif; ?>

                <div class="cart-summary-row" style="font-size: 0.85rem;">
                    <span>Delivery Charges</span>
                    <span style="font-style: italic;">Calculated at checkout</span>
                </div>

                <div class="cart-summary-total">
                    <span>Estimated Total</span>
                    <span><?= format_price(max(0, $subtotal - $couponDiscount)) ?></span>
                </div>

                <div style="margin-top: 1.75rem;">
                    <?php if (!$hasStockIssue): ?>
                        <a href="<?= $appBase ?>/orders/checkout.php" class="btn btn-primary btn-block btn-lg">
                            Proceed to Checkout &rarr;
                        </a>
                    <?php else: ?>
                        <button type="button" class="btn btn-primary btn-block btn-lg" disabled style="opacity: 0.5;">
                            Fix Stock Quantities to Proceed
                        </button>
                    <?php endif; ?>
                </div>

                <div style="margin-top: 1.5rem; text-align: center; font-size: 0.775rem; color: var(--text-muted);">
                    🛡️ Re-verified directly with kitchen inventory before placement.
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
