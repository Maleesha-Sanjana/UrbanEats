    </main>

    <!-- Site Footer -->
    <footer class="site-footer" id="mainFooter">
        <div class="site-container footer-content">
            <div class="footer-grid">
                <!-- Col 1: Brand & Mission -->
                <div class="footer-col brand-col">
                    <div class="footer-brand">
                        <span class="logo-flame">🍲</span>
                        <span class="footer-title">Urban<span class="brand-accent">Eats</span></span>
                    </div>
                    <p class="footer-desc">
                        Sri Lanka's premier next-generation cloud kitchen. Delivering authentic rice & curry,
                        signature kottu, flame-grilled biryani, and artisanal short eats freshly prepared to order.
                    </p>
                    <div class="currency-badge">
                        <span>All Prices in Sri Lankan Rupees (LKR)</span>
                    </div>
                </div>

                <!-- Col 2: Quick Links -->
                <div class="footer-col">
                    <h3 class="footer-heading">Explore</h3>
                    <ul class="footer-links">
                        <li><a href="<?= $appBase ?>/index.php">Home Page</a></li>
                        <li><a href="<?= $appBase ?>/menu/list.php">Our Full Menu</a></li>
                        <li><a href="<?= $appBase ?>/orders/cart.php">Shopping Cart</a></li>
                        <?php if (is_logged_in()): ?>
                            <li><a href="<?= $appBase ?>/orders/history.php">My Order History</a></li>
                            <li><a href="<?= $appBase ?>/auth/profile.php">Account Profile</a></li>
                        <?php else: ?>
                            <li><a href="<?= $appBase ?>/auth/login.php">Customer Login</a></li>
                            <li><a href="<?= $appBase ?>/auth/register.php">Create Account</a></li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Col 3: Cloud Kitchen Operations -->
                <div class="footer-col">
                    <h3 class="footer-heading">Kitchen & Operations</h3>
                    <ul class="footer-links">
                        <li><a href="<?= $appBase ?>/orders/kitchen.php">Kitchen Order Queue</a></li>
                        <li><a href="<?= $appBase ?>/menu/stock.php">Inventory & Reorder Levels</a></li>
                        <li><a href="<?= $appBase ?>/menu/manage_dishes.php">Dish Catalogue</a></li>
                        <li><a href="<?= $appBase ?>/admin/reports.php">Sales & Analytics</a></li>
                        <li><a href="<?= $appBase ?>/admin/audit_log.php">System Audit Log</a></li>
                    </ul>
                </div>

                <!-- Col 4: Operations & Hours -->
                <div class="footer-col">
                    <h3 class="footer-heading">Kitchen Hours & Location</h3>
                    <p class="kitchen-hours">
                        <strong>Daily Delivery & Pickup:</strong><br>
                        Monday – Sunday: 10:30 AM – 11:00 PM
                    </p>
                    <p class="kitchen-location">
                        📍 45 Galle Road, Colombo 03, Sri Lanka<br>
                        📞 Hotlines: +94 11 234 5678 / +94 77 123 4567
                    </p>
                    <div class="payment-methods">
                        <span class="pay-tag">Cash on Delivery</span>
                        <span class="pay-tag">Simulated Card</span>
                        <span class="pay-tag">Bank Transfer</span>
                    </div>
                </div>
            </div>

            <!-- Bottom Bar -->
            <div class="footer-bottom">
                <p>&copy; <?= date('Y') ?> UrbanEats Cloud Kitchen Platform. All rights reserved.</p>
                <p class="academic-note">
                    Developed for <strong>IT3113 Advanced Web Technologies</strong> • Group Assignment (Components A, B & C)
                </p>
            </div>
        </div>
    </footer>

    <!-- Vanilla JavaScript for Interactive UI Enhancements -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Mobile Navigation Toggle
            const navToggle = document.getElementById('navToggle');
            const mainNav = document.getElementById('mainNav');
            if (navToggle && mainNav) {
                navToggle.addEventListener('click', function () {
                    const expanded = navToggle.getAttribute('aria-expanded') === 'true';
                    navToggle.setAttribute('aria-expanded', !expanded);
                    mainNav.classList.toggle('nav-open');
                });
            }

            // User Profile Dropdown Toggle
            const userMenuBtn = document.getElementById('userMenuBtn');
            const userMenuDropdown = document.getElementById('userMenuDropdown');
            if (userMenuBtn && userMenuDropdown) {
                userMenuBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    const expanded = userMenuBtn.getAttribute('aria-expanded') === 'true';
                    userMenuBtn.setAttribute('aria-expanded', !expanded);
                    userMenuDropdown.classList.toggle('show');
                });

                document.addEventListener('click', function (e) {
                    if (!userMenuDropdown.contains(e.target) && !userMenuBtn.contains(e.target)) {
                        userMenuDropdown.classList.remove('show');
                        userMenuBtn.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            // Auto-dismiss alerts after 6 seconds
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function (alert) {
                setTimeout(function () {
                    alert.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(function () { alert.remove(); }, 400);
                }, 6000);
            });
        });
    </script>
</body>
</html>
