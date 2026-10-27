# UrbanEats Cloud Kitchen Platform

**Module:** IT3113 Advanced Web Technologies (Year 3, Semester 1)  
**Assignment Type:** Group Project with Individual Component Defense & Viva  
**Technology Stack:** HTML5, Hand-crafted CSS3, PHP 8.x (Vanilla PDO), MySQL / MariaDB (phpMyAdmin)

---

## 🍽️ Project Overview

**UrbanEats** is a complete, database-driven web application designed for a high-volume cloud kitchen operating in Colombo, Sri Lanka. Unlike traditional dine-in restaurants, UrbanEats processes orders exclusively through express home delivery and kitchen counter pickup.

The platform eliminates paper ticket errors, prevents ordering out-of-stock dishes via atomic inventory locking, offers real-time order tracking timelines, and delivers business intelligence reports for management.

---

## 👥 Component Ownership & Scope

| Component | Scope | Key Features |
|---|---|---|
| **Component A: Identity, Access & Administration** *(Student 1)* | User Authentication, RBAC, Sessions, Admin Portal & Security | Registration, secure login with `password_hash()`, login throttling (lockout after 5 failed attempts), session hardening & inactivity timeout, time-limited password recovery tokens, centralized audit logger (`log_action()`), user management (role promotion, deactivation), access-denied handling. |
| **Component B: Menu, Catalogue & Inventory** *(Student 2)* | Catalogue CRUD, Stock Control, Search, Filtering & Image Upload | Public menu with category pills, keyword search, price & name sorting (whitelisted), dietary filters (veg, spicy), pagination, dish detail view, staff CRUD with soft delete (`is_active = 0`), secure image upload (MIME, size, extension, unguessable filenames), transaction-safe `reduce_stock()` refusing negative stock, restock and wastage audits. |
| **Component C: Ordering, Payment & Tracking** *(Student 3)* | Cart, Checkout Transaction, Workflow Engine & Reports | Session-based cart with live DB price re-verification, atomic database transaction checkout (orders, items, payment, stock deduction, rollback), coupon engine (percentage/fixed discounts, usage limits), order workflow state machine (`Placed` &rarr; `Preparing` &rarr; `Ready` &rarr; `Out for Delivery` / `Collected` &rarr; `Completed`), printable invoice receipts, kitchen queue board, sales reports (JOIN, GROUP BY, aggregates). |

---

## 🔑 Test Login Credentials (Viva & Evaluation)

All seeded test accounts use the default password: **`Password123!`**

| Role | Email Address | Password | Name | Access Scope |
|---|---|---|---|---|
| **Administrator** | `admin@urbaneats.lk` | `Password123!` | Maleesha Sanjana | Full administrative panel, user management, audit logs, sales reports, kitchen queue, catalogue |
| **Administrator** | `nethmi.admin@urbaneats.lk` | `Password123!` | Nethmi Mohotti | Full administrative privileges |
| **Kitchen Staff** | `chef.kasun@urbaneats.lk` | `Password123!` | Kasun Fernando | Kitchen order queue, live status progression, stock adjustments, dish catalogue |
| **Kitchen Staff** | `dinuka.kitchen@urbaneats.lk` | `Password123!` | Dinuka Silva | Kitchen line operations & stock dashboard |
| **Kitchen Staff** | `nuwan.stock@urbaneats.lk` | `Password123!` | Nuwan Bandara | Inventory & wastage controller |
| **Customer** | `amara.j@gmail.com` | `Password123!` | Amara Jayawardena | Menu browsing, cart, checkout, live tracking, order history, profile |
| **Customer** | `roshan.desilva@yahoo.com` | `Password123!` | Roshan De Silva | Menu browsing & order history |
| **Customer** | `kavindi.p@outlook.com` | `Password123!` | Kavindi Perera | Menu browsing & order history |

*Note: Visitors can also create new customer accounts via the `/public/auth/register.php` portal.*

---

## 🎟️ Active Promotional Coupons

| Coupon Code | Discount Type | Value | Min. Order Amount | Notes |
|---|---|---|---|---|
| `FIRSTORDER` | Percentage | 15% off (Max LKR 500) | LKR 1,000.00 | Valid for all users |
| `KOTTU10` | Percentage | 10% off (Max LKR 400) | LKR 1,500.00 | Kottu special |
| `FLAT300` | Fixed Amount | LKR 300.00 off | LKR 2,000.00 | Flat discount |
| `WEEKEND20` | Percentage | 20% off (Max LKR 1,000) | LKR 2,500.00 | Weekend feast promo |
| `SPICY500` | Fixed Amount | LKR 500.00 off | LKR 3,500.00 | Bulk order discount |

---

## 🚀 Setup & Installation Guide

### Prerequisites
- **Web Server:** Apache (XAMPP, WAMP, MAMP, or LAMP) or standalone PHP 8.x CLI
- **Database:** MySQL 8.x or MariaDB managed via phpMyAdmin
- **Browser:** Any modern web browser (Chrome, Firefox, Safari, Edge)

### Step 1: Clone or Place Project
Place the project directory into your web server's root folder:
- **XAMPP (Windows):** `C:\xampp\htdocs\UrbanEats`
- **XAMPP (macOS):** `/Applications/XAMPP/xamppfiles/htdocs/UrbanEats`
- **WAMP (Windows):** `C:\wamp64\www\UrbanEats`

### Step 2: Import Database Schema & Seed Data
1. Open **phpMyAdmin** in your browser (`http://localhost/phpmyadmin`).
2. Click **Import** in the top navigation tab.
3. Choose the SQL file located at:
   ```
   database/urbaneats_db.sql
   ```
4. Click **Import** / **Go** at the bottom.
5. The script automatically creates the `urbaneats_db` database, generates all 14 normalized 3NF tables with foreign keys and constraints, and seeds over 20 realistic rows into every main table.

*Alternatively, import via MySQL Command Line:*
```bash
mysql -u root -p < database/urbaneats_db.sql
```

### Step 3: Configure Database Connection
The application reads settings from `config/db.php`. By default, it connects to:
- **Host:** `127.0.0.1`
- **Port:** `3306`
- **Database:** `urbaneats_db`
- **Username:** `root`
- **Password:** *(empty)*

If your local MySQL environment requires a custom password, copy `config/config.sample.php` to `config/config.php` and specify your credentials:
```php
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'dbname' => 'urbaneats_db',
        'username' => 'root',
        'password' => 'your_custom_password',
        'charset' => 'utf8mb4'
    ]
];
```

### Step 4: Run the Application
Open your browser and navigate to:
```
http://localhost/UrbanEats/public/index.php
```

*(If testing via built-in PHP development server from terminal):*
```bash
# From workspace root
php -S 127.0.0.1:8000 -t /path/to/parent_directory
# Then open: http://127.0.0.1:8000/UrbanEats/public/index.php
```

---

## 🧪 Running Automated Integration Tests

An automated test suite is provided to verify schema integrity, authentication, inventory stock deductions, checkout transactions, and reporting:
```bash
php tests/test_end_to_end.php
```
All 14 comprehensive test cases will execute and output formatted pass/fail assertions.

---

## 📁 Repository Structure (Appendix A Compliant)

```
urbaneats/
├── config/
│   ├── db.php                      # Shared PDO connection singleton & error handler
│   └── config.sample.php           # Template configuration without credentials
├── database/
│   └── urbaneats_db.sql            # Master schema (14 tables, 3NF) & 20+ seed rows
├── docs/
│   ├── GROUP_REPORT.md             # Complete 10-page equivalent group report
│   ├── TEST_PLAN.md                # 45+ formal test cases (Functional, Security, Edge)
│   └── VIVA_PREPARATION_GUIDE.md   # Comprehensive viva defense guide for Components A, B, C
├── includes/
│   ├── auth.php                    # RBAC, login throttling, session hardening, audit log
│   ├── csrf.php                    # Cryptographic synchronizer CSRF token validation
│   ├── errors.php                  # Central error/exception handler logging to logs/error.log
│   ├── footer.php                  # Semantic footer with role links & vanilla JS micro-interactions
│   ├── functions.php               # Helpers: reduce_stock, get_cart_details, upload_dish_image
│   └── header.php                  # Semantic header with dynamic role navigation & cart counter
├── logs/
│   └── error.log                   # Server-side error and security audit log
├── public/
│   ├── admin/
│   │   ├── audit_log.php           # Component A: Audit log viewer with filters
│   │   ├── dashboard.php           # Component A: Executive admin control center
│   │   ├── reports.php             # Component C: Date-filtered sales and revenue analytics
│   │   └── users.php               # Component A: User accounts, status toggle & role changes
│   ├── auth/
│   │   ├── forgot.php              # Component A: Password reset request & on-screen token
│   │   ├── login.php               # Component A: Secure login with throttling lockout
│   │   ├── logout.php              # Component A: Session destruction & audit logging
│   │   ├── profile.php             # Component A: Profile edit & password update
│   │   ├── register.php            # Component A: Customer registration with validation
│   │   └── reset.php               # Component A: Token-based password reset submission
│   ├── css/
│   │   └── style.css               # Shared pure hand-crafted CSS3 with design tokens
│   ├── menu/
│   │   ├── categories.php          # Component B: Category hierarchy management
│   │   ├── detail.php              # Component B: Dish detail with live stock status
│   │   ├── list.php                # Component B: Public menu with search, sort & pagination
│   │   ├── manage_dishes.php       # Component B: Staff CRUD, soft delete & image upload
│   │   └── stock.php               # Component B: Stock dashboard, restock & wastage tracking
│   ├── orders/
│   │   ├── cart.php                # Component C: Session cart with live DB price checks
│   │   ├── checkout.php            # Component C: Atomic transaction checkout with rollback
│   │   ├── confirmation.php        # Component C: Printable invoice and receipt
│   │   ├── history.php             # Component C: Customer order history
│   │   ├── kitchen.php             # Component C: Kitchen order queue & workflow progression
│   │   └── tracking.php            # Component C: Live order timeline & customer cancellation
│   ├── uploads/
│   │   ├── .htaccess               # Security: Prevents script execution in upload directory
│   │   └── *.jpg                   # Dish images
│   ├── 403.php                     # Friendly 403 Forbidden Access Denied page
│   ├── 404.php                     # Friendly 404 Not Found page
│   ├── 500.php                     # Friendly 500 Server Error page
│   └── index.php                   # Public landing page, promotions & signature specials
├── tests/
│   └── test_end_to_end.php         # Automated integration test runner
└── README.md                       # Setup, credentials, and architecture overview
```

---

## 🛡️ Security Measures Summary

1. **SQL Injection Defense:** All database queries utilize PDO prepared statements with strict parameter binding. Column names for sorting in `menu/list.php` are strictly whitelisted against an allowed array.
2. **Cross-Site Scripting (XSS):** All dynamic browser outputs are escaped using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` through the global helper `e()`.
3. **Cross-Site Request Forgery (CSRF):** Synchronizer tokens generated via `bin2hex(random_bytes(32))` are embedded in all POST forms via `csrf_field()` and verified via `validate_csrf_or_fail()` with timing-safe `hash_equals()`.
4. **Password Hashing:** Passwords are never stored in plaintext; hashed using `password_hash($password, PASSWORD_DEFAULT)` and verified with `password_verify()`.
5. **Login Throttling:** 5 consecutive failed login attempts trigger an automatic 15-minute lockout recorded in `login_attempts` and `users`.
6. **Session Hardening:** `session_regenerate_id(true)` on login prevents session fixation; `session.cookie_httponly = 1`, `session.cookie_samesite = Lax`, and 30-minute inactivity timeouts are enforced.
7. **Secure File Uploads:** Uploaded dish images are verified for MIME type (`finfo`), file extension, maximum size (2MB), renamed with cryptographically unguessable filenames (`dish_...`), and `.htaccess` disables script execution within `public/uploads/`.
8. **Role-Based Access Control (RBAC):** Guard functions `require_login()` and `require_role()` protect every restricted endpoint and return friendly 403 pages upon unauthorized access.
9. **Race Condition Stock Defense:** Stock reduction uses atomic conditional decrements (`WHERE dish_id = :id AND current_stock >= :qty`) within single database transactions with automatic rollback on error.
10. **Error Masking:** Raw SQL and PHP exceptions are never shown to end-users; friendly styled error pages are displayed while details are written to `logs/error.log`.