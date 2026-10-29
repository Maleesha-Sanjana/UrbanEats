# UrbanEats Cloud Kitchen Platform

**Module:** IT3113 Advanced Web Technologies (Year 3, Semester 1)  
**Assessment Type:** Group Project with Individual Component and Individual Viva  
**Technology Stack:** HTML5, Hand-written CSS3, PHP 8.x (PDO), MySQL / MariaDB (phpMyAdmin)  

---

## 1. Project Overview

**UrbanEats** is a database-driven web application developed for a cloud kitchen business in Sri Lanka. Operating exclusively through delivery and customer pickup, the platform provides:
- Online customer menu browsing, cart management, and order tracking.
- Kitchen staff order fulfillment queues, live inventory management, and stock adjustments.
- Administrative user access control, audit event logging, and executive sales reporting.

The system is developed using vanilla PHP 8.x with PDO prepared statements, semantic HTML5, responsive hand-written CSS3, and a 3NF normalized MySQL database.

---

## 2. Test Login Accounts

All test accounts use the default password: **`Password123!`**

| Role | Email | Password | Access / Purpose |
|---|---|---|---|
| **Administrator** | `admin@urbaneats.lk` | `Password123!` | System dashboard, user management, audit logs, sales reports |
| **Kitchen Staff** | `chef.kasun@urbaneats.lk` | `Password123!` | Kitchen order queue, dish management, stock/inventory dashboard |
| **Customer** | `amara.j@gmail.com` | `Password123!` | Menu browsing, cart, order placement, order tracking |

---

## 3. Setup and Installation

### Prerequisites
- Web server environment: **XAMPP / WAMP / LAMP** running Apache and MySQL/MariaDB with **PHP 8.0 or higher**.
- Web browser (Chrome, Firefox, Edge, Safari).

### Step 1: Place Project Files
Copy the project folder into your web server's document root (`htdocs` or `www`):
- **XAMPP (macOS):** `/Applications/XAMPP/xamppfiles/htdocs/UrbanEats`
- **XAMPP (Windows):** `C:\xampp\htdocs\UrbanEats`
- **WAMP (Windows):** `C:\wamp64\www\UrbanEats`

### Step 2: Import the Database
1. Open **phpMyAdmin** in your browser (`http://localhost/phpmyadmin`).
2. Navigate to the **Import** tab.
3. Select the SQL script located at:
   ```
   database/urbaneats_db.sql
   ```
4. Click **Import** (or **Go**).
5. The script creates the `urbaneats_db` database, generates all 14 tables with constraints and foreign keys, and inserts realistic seed data (including test user accounts).

### Step 3: Database Configuration
The application connects using `config/db.php`. Default settings:
- **Host:** `127.0.0.1`
- **Port:** `3306`
- **Database:** `urbaneats_db`
- **Username:** `root`
- **Password:** *(empty)*

If your local MySQL instance has a custom password, copy `config/config.sample.php` to `config/config.php` and set your credentials.

### Step 4: Access the Application
Open your web browser and navigate to:
```
http://localhost/UrbanEats/public/index.php
```

---

## 4. Component Scope and Features

### Component A: Identity, Access and Administration (Student 1)
- **User Authentication:** Registration, login, logout, profile update, and change-password functionality.
- **Security & Session Hardening:** Password hashing using `password_hash()` and `password_verify()`, `session_regenerate_id()` on login, HTTP-only cookie flags, and a 30-minute inactivity session timeout.
- **Access Control:** Reusable guard functions (`require_login()`, `require_role()`) and a custom 403 Access Denied page.
- **Login Throttling:** Temporary account lockout (15 minutes) after 5 consecutive failed login attempts, recorded in `login_attempts`.
- **Password Reset:** Time-limited cryptographic token reset workflow (token displayed on-screen per assignment guidelines).
- **Centralized Audit Trail:** Reusable `log_action()` function recording user events, IP addresses, and timestamps.
- **User Administration:** Admin user management panel with keyword search, role filtering, account activation/deactivation, and role reassignment.

### Component B: Menu, Catalogue and Inventory (Student 2)
- **Public Menu:** Menu listing with category filter pills, keyword search, price and name sorting (whitelisted), and pagination.
- **Dish Details:** Individual dish page displaying pricing, description, spice level, and real-time availability.
- **Staff Catalogue Management:** Full CRUD operations for dishes and categories, including soft deletion (`is_active = 0`) to preserve historical order records.
- **Secure Image Uploads:** Multi-layer validation checking file extensions, MIME types, and maximum file size (2MB) with randomized filenames.
- **Inventory & Stock Control:** Stock management dashboard highlighting low-stock and out-of-stock items, stock adjustment logging (restock/wastage), and the transaction-safe `reduce_stock()` function.

### Component C: Ordering, Payment and Tracking (Student 3)
- **Shopping Cart:** Session-based cart with live database price and availability re-verification.
- **Checkout Transaction:** Atomic database transaction handling order insertion, order items snapshot, payment record, and stock deduction with complete rollback on failure.
- **Coupons:** Promotional code verification checking expiry dates, minimum order values, and usage limits.
- **Order Workflow:** Status progression state machine (`Placed` → `Preparing` → `Ready` → `Out for Delivery` / `Collected` → `Completed` / `Cancelled`) with historical timeline logging.
- **Customer Tracking & Invoices:** Order tracking view and printable invoice receipts.
- **Reporting:** Kitchen staff live queue and administrative sales reports with date range filtering, revenue summaries, and top-selling dishes.

---

## 5. Project Directory Structure

```
UrbanEats/
├── config/
│   ├── config.sample.php        # Sample configuration template
│   └── db.php                   # Shared PDO database connection singleton
├── database/
│   ├── reset_db.php             # Database reset utility
│   └── urbaneats_db.sql         # Database schema (14 tables, 3NF) and seed data
├── includes/
│   ├── auth.php                 # Authentication, RBAC, session management, and audit log
│   ├── csrf.php                 # Cryptographic CSRF token validation
│   ├── errors.php               # Centralized error and exception handler
│   ├── footer.php               # Shared semantic footer
│   ├── functions.php            # Shared helper functions
│   └── header.php               # Shared semantic header with role-based navigation
├── logs/
│   └── error.log                # Server error log file
├── public/
│   ├── 403.php                  # Access Denied page
│   ├── 404.php                  # Page Not Found
│   ├── 500.php                  # Server Error page
│   ├── index.php                # Public landing page
│   ├── admin/
│   │   ├── audit_log.php        # Audit trail viewer (Component A)
│   │   ├── dashboard.php        # Administrator dashboard (Component A)
│   │   ├── reports.php          # Sales reports (Component C)
│   │   └── users.php            # User management (Component A)
│   ├── auth/
│   │   ├── forgot.php           # Password reset request (Component A)
│   │   ├── login.php            # Secure login (Component A)
│   │   ├── logout.php           # Session logout (Component A)
│   │   ├── profile.php          # Profile management (Component A)
│   │   ├── register.php         # Customer registration (Component A)
│   │   └── reset.php            # Password reset confirmation (Component A)
│   ├── css/
│   │   └── style.css            # Shared hand-written CSS stylesheet
│   ├── menu/
│   │   ├── categories.php       # Category management (Component B)
│   │   ├── detail.php           # Dish detail view (Component B)
│   │   ├── grn.php              # Goods received notes (Component B)
│   │   ├── list.php             # Public menu catalogue (Component B)
│   │   ├── manage_dishes.php    # Dish CRUD operations (Component B)
│   │   └── stock.php            # Stock management dashboard (Component B)
│   ├── orders/
│   │   ├── cart.php             # Session cart (Component C)
│   │   ├── checkout.php         # Checkout transaction (Component C)
│   │   ├── confirmation.php     # Order confirmation & invoice (Component C)
│   │   ├── history.php          # Order history (Component C)
│   │   ├── kitchen.php          # Kitchen staff order queue (Component C)
│   │   └── tracking.php         # Live order tracking timeline (Component C)
│   └── uploads/
│       └── *.jpg                # Uploaded dish images
├── tests/
│   ├── test_end_to_end.php      # End-to-end integration test suite
│   └── verify_component_b.php   # Component B validation suite
└── README.md
```

---

## 6. Security Implementation Summary

1. **SQL Injection Defense:** 100% PDO prepared statements with parameter binding throughout all components. Dynamic order parameters are strictly whitelisted.
2. **Cross-Site Scripting (XSS):** Context-aware output escaping using `htmlspecialchars()` with `ENT_QUOTES` via helper function `e()`.
3. **Cross-Site Request Forgery (CSRF):** Synchronizer token pattern using `random_bytes(32)` validated with `hash_equals()` on all POST submissions.
4. **Password Security:** Hashes stored using `password_hash(..., PASSWORD_DEFAULT)` and verified with `password_verify()`.
5. **Session Hardening:** `session_regenerate_id(true)` upon login, HTTP-only cookie flags, and 30-minute inactivity timeouts.
6. **Access Control:** Server-side role enforcement on every restricted page using `require_role()`.
7. **File Upload Security:** Multi-step verification checking MIME type, file extension, file size, and renaming stored files.
8. **Error Handling:** Raw SQL/PHP errors are captured by a custom exception handler and written to `logs/error.log`; friendly error pages are displayed to end-users.
