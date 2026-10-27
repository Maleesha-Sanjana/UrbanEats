# UrbanEats Cloud Kitchen Platform - Comprehensive Test Plan & Results

**Module:** IT3113 Advanced Web Technologies  
**Assignment:** Group Project Testing Evidence (Section 6 Requirement)  
**Total Test Cases:** 45 Formal Cases (15 per Component covering Functional, Validation, Security, and Edge Cases)  
**Execution Status:** 100% Passed  

---

## Component A: Identity, Access and Administration (Student 1)

| Test ID | Category | Test Case Description | Test Input / Action | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| **TC-A01** | Functional | Successful customer registration | Name: `Chamari Atapattu`, Email: `chamari@test.lk`, Phone: `+94771122334`, Pwd: `Password123!` | Account inserted into `users` with `role_id = 3`, session started, redirected to homepage with welcome message | User created, session logged in, redirect succeeded | **PASS** |
| **TC-A02** | Validation | Duplicate email registration rejection | Submit registration with existing email `admin@urbaneats.lk` | Error displayed: "An account with this email address already exists. Please log in." | Rejected with validation banner | **PASS** |
| **TC-A03** | Validation | Password length below minimum | Submit registration with password `short` | Form rejected with error: "Password must be at least 8 characters long." | Form blocked, inline error shown | **PASS** |
| **TC-A04** | Validation | Password mismatch validation | Enter password `Password123!` and confirm `Mismatch999!` | Error displayed: "Password confirmation does not match." | Rejected, inputs preserved | **PASS** |
| **TC-A05** | Functional | Valid administrator authentication | Login with `admin@urbaneats.lk` / `Password123!` | Password verified via `password_verify()`, session regenerated, redirected to `/admin/dashboard.php` | Successfully logged in, redirected to admin panel | **PASS** |
| **TC-A06** | Security | Login attempt throttling (Lockout after 5 failed attempts) | Submit 5 consecutive incorrect passwords for `amara.j@gmail.com` | On 5th failure, account status locked for 15 minutes. Subsequent attempts return lockout error with remaining minutes | Temporary lockout triggered, remaining minutes displayed | **PASS** |
| **TC-A07** | Security | Session fixation prevention | Inspect session ID before and immediately after successful login | `session_regenerate_id(true)` issues a brand new session token, discarding old session ID | Session ID regenerated | **PASS** |
| **TC-A08** | Security | Inactivity session timeout | Wait 30 minutes (or simulate `last_activity` timestamp > 1800s) | Session destroyed, user redirected to login with notice: "Your session has timed out due to inactivity." | Session invalidated, redirected to login | **PASS** |
| **TC-A09** | Security | Unauthorized admin URL access guard | Log in as customer (`amara.j@gmail.com`) and navigate to `/admin/users.php` | `require_role('admin')` denies request, responds with HTTP 403 Forbidden and styled access denied page | HTTP 403 page displayed | **PASS** |
| **TC-A10** | Security | CSRF token validation on login form | Submit login POST request with missing or altered `csrf_token` | Request rejected with HTTP 403 Forbidden and notice: "Invalid Security Token" | HTTP 403 error page rendered | **PASS** |
| **TC-A11** | Functional | Password reset token generation | Enter registered email `amara.j@gmail.com` in `/auth/forgot.php` | Cryptographic SHA-256 token generated, stored in `password_resets` with 1h expiry, link shown on screen | Reset link displayed on screen | **PASS** |
| **TC-A12** | Security | Expired or reused password reset token | Submit password reset with already used or manipulated token | Error displayed: "This password reset link is invalid, expired, or has already been used." | Blocked from resetting password | **PASS** |
| **TC-A13** | Functional | User status deactivation by administrator | Admin toggles customer `Damith Wickrama` status to Deactivated in `/admin/users.php` | `is_active` set to 0 in database, audit log entry recorded, user prevented from logging in | Status updated, login blocked | **PASS** |
| **TC-A14** | Edge Case | Admin self-deactivation prevention | Admin Maleesha attempts to deactivate own account | System rejects action with error: "Security Violation: You cannot deactivate your own administrative account." | Blocked, self-lockout prevented | **PASS** |
| **TC-A15** | Functional | Audit logging of critical events | Perform password change in `/auth/profile.php` | `log_action()` records event `PASSWORD_CHANGED` with user ID, IP address, and timestamp in `audit_log` | Audit record verified in `/admin/audit_log.php` | **PASS** |

---

## Component B: Menu, Catalogue and Inventory (Student 2)

| Test ID | Category | Test Case Description | Test Input / Action | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| **TC-B01** | Functional | Category filter navigation | Click "Signature Kottu Roti" category chip in `/menu/list.php` | URL updates to `?category=kottu-roti`, displays only kottu dishes | Filtered list displayed accurately | **PASS** |
| **TC-B02** | Functional | Keyword search query | Search keyword "lamprais" in menu search bar | Executes prepared query `WHERE name LIKE ? OR description LIKE ?`, returns lamprais records | Lamprais dish returned | **PASS** |
| **TC-B03** | Security | SQL injection prevention in sorting parameter | Submit malicious GET request `?sort=price_asc;DROP+TABLE+users;--` | Whitelist `match` statement defaults safely to `d.id ASC` without executing concatenated SQL | Query parsed safely without syntax error | **PASS** |
| **TC-B04** | Functional | Dietary preference filtering | Select filter `🌱 Vegetarian Only` | Query executes with `is_vegetarian = 1`, only veg dishes displayed | Filtered accurately | **PASS** |
| **TC-B05** | Functional | Pagination navigation | Click Page 2 with 9 items per page | Query limits with `LIMIT 9 OFFSET 9`, displays subsequent dishes | Page 2 loaded smoothly | **PASS** |
| **TC-B06** | Functional | Dish detail page rendering | Navigate to `/menu/detail.php?id=1` | Displays dish title, high-res image, price, ingredients, culinary tags, and real-time inventory counter | Page rendered with accurate metadata | **PASS** |
| **TC-B07** | Functional | Staff dish creation with valid data | Fill form in `/menu/manage_dishes.php` with name, category, price, and initial stock | New dish inserted into `dishes`, initial stock inserted into `inventory`, restock logged in `stock_movements` | Dish and inventory created | **PASS** |
| **TC-B08** | Security | Image upload MIME type validation | Attempt to upload malicious `.php` disguised as image | `finfo` detects non-image MIME type, rejects upload with error: "Invalid image format. Allowed formats: JPG, PNG, WEBP." | Upload blocked, error shown | **PASS** |
| **TC-B09** | Security | Image upload file size limit (2MB) | Upload image larger than 2MB (e.g. 5MB photo) | Upload rejected with error: "Image size exceeds maximum limit of 2MB." | Rejected by server-side check | **PASS** |
| **TC-B10** | Security | Image filename sanitization & directory protection | Upload valid image `my_photo.jpg` | Saved with randomized unique name `dish_[hash].jpg`; `.htaccess` in `uploads/` prevents PHP execution | Unguessable name created, execution disabled | **PASS** |
| **TC-B11** | Functional | Soft delete of dish to protect order history | Click "Archive" on dish in `/menu/manage_dishes.php` | `is_active` set to 0. Dish hidden from public menu, but historical rows in `order_items` remain valid | Dish archived without DB FK violation | **PASS** |
| **TC-B12** | Functional | Transaction-safe stock reduction | Component C calls `reduce_stock($pdo, 1, 2)` | Decrements `inventory.current_stock` by 2 units atomically via conditional `UPDATE` | Stock decremented exactly | **PASS** |
| **TC-B13** | Edge Case | Stock reduction refusing negative balance | Call `reduce_stock($pdo, 1, 99999)` when available stock is 45 | Conditional update affects 0 rows, throws Exception: "Insufficient stock for [Dish]", triggers transaction rollback | Exception thrown, rollback verified | **PASS** |
| **TC-B14** | Functional | Kitchen staff restock recording | Staff submits restock of 30 units in `/menu/stock.php` | `current_stock` incremented, movement logged in `stock_movements` with type `restock` and staff user ID | Stock updated and logged | **PASS** |
| **TC-B15** | Functional | Kitchen wastage recording | Staff logs wastage of 2 units with reason "Dropped during packaging" | Stock decremented by 2, movement logged as `wastage` with recorded reason | Stock decremented and audited | **PASS** |

---

## Component C: Ordering, Payment and Tracking (Student 3)

| Test ID | Category | Test Case Description | Test Input / Action | Expected Result | Actual Result | Status |
|---|---|---|---|---|---|---|
| **TC-C01** | Functional | Add dish to shopping cart | Click "Add to Cart" on Chicken Kottu | Dish stored in `$_SESSION['cart'][6] = 1`, cart badge updates | Item added to session, badge incremented | **PASS** |
| **TC-C02** | Security | Database price re-verification at cart/checkout | Modify client session price and submit checkout | `get_cart_details()` re-reads dish price directly from database table `dishes.price`, ignoring any client-sent price | Price strictly verified against DB | **PASS** |
| **TC-C03** | Validation | Cart quantity exceeding available stock | Attempt to increase cart quantity to 100 when stock is 15 | System clamps quantity to 15 with warning: "Cannot add more than available kitchen stock." | Clamped to available stock | **PASS** |
| **TC-C04** | Functional | Valid coupon code application | Apply coupon `FIRSTORDER` on subtotal LKR 2,000 | 15% discount applied (LKR 300.00), running total reflects deduction | Discount computed and applied | **PASS** |
| **TC-C05** | Validation | Expired coupon code rejection | Apply expired coupon code `EXPIRED50` | Error displayed: "This coupon has expired.", no discount applied | Rejected with error notice | **PASS** |
| **TC-C06** | Validation | Coupon below minimum order threshold | Apply coupon `SPICY500` (min order LKR 3,500) on order of LKR 1,500 | Error displayed: "Minimum order amount for this coupon is LKR 3,500.00." | Rejected with minimum amount hint | **PASS** |
| **TC-C07** | Functional | Atomic checkout transaction (Delivery) | Customer places delivery order with simulated Card payment | Transaction inserts into `orders`, `order_items`, `payments`, `order_status_history`, calls `reduce_stock()`, commits | All 5 tables updated atomically | **PASS** |
| **TC-C08** | Edge Case | Transaction rollback on stockout failure | Simulate second user ordering last dish concurrently | `reduce_stock()` fails, transaction invokes `rollBack()`, undoing order and payment insertions | Complete rollback, zero orphan records | **PASS** |
| **TC-C09** | Functional | Price snapshot in `order_items` | Checkout dish priced at LKR 1,150.00; later edit dish price in catalogue to LKR 1,500.00 | Historical order item retains original `unit_price = 1150.00` | Historical order price preserved | **PASS** |
| **TC-C10** | Functional | Printable invoice receipt rendering | Navigate to `/orders/confirmation.php?order_number=...` | Displays complete invoice breakdown, tax ID, items, delivery fee, grand total, print button | Printable receipt rendered cleanly | **PASS** |
| **TC-C11** | Functional | Order status progression (Kitchen Queue) | Kitchen staff clicks "Start Preparing" on Placed ticket | Order status advances to `Preparing`, transition recorded in `order_status_history` | Status updated and logged | **PASS** |
| **TC-C12** | Security | Workflow state machine jump prevention | Attempt to transition order directly from `Placed` to `Completed` | `is_valid_status_transition()` returns false, rejects illegal jump | Transition rejected with error | **PASS** |
| **TC-C13** | Functional | Live status timeline tracking | Customer views `/orders/tracking.php?order_number=...` | Interactive visual stepper highlights current stage with glowing node and historical timestamps | Stepper renders exact status node | **PASS** |
| **TC-C14** | Functional | Customer order cancellation while in 'Placed' state | Customer clicks "Cancel This Order" on Placed order | Status updated to `Cancelled`, dish quantities restored to `inventory`, payment marked refunded | Order cancelled and stock restored | **PASS** |
| **TC-C15** | Functional | Administrator sales report aggregates | Admin selects date range in `/admin/reports.php` | Advanced query computes gross completed sales, order volume, AOV, status distribution, and top-selling dishes | Financial aggregates computed | **PASS** |
