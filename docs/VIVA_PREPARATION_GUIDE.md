# UrbanEats Cloud Kitchen Platform - Viva Preparation & Technical Defense Guide

**Module:** IT3113 Advanced Web Technologies  
**Assessment:** Group Live Demonstration (5 mins) & Individual Oral Viva (10–12 mins)  
**Total Viva Marks:** 30 Marks (Group Demo: 5, Code Explanation: 10, SQL/DB: 5, Security: 5, Live Modification: 5)  

---

## ⏱️ Part 1: Group Live Demonstration Script (5 Minutes)

Practice this exact sequential flow with your teammates for the 5-minute live group demonstration:

### Step 1: Customer Journey (Student 3 & Student 1) - 1.5 Mins
1. Open the landing page (`public/index.php`). Highlight the modern responsive culinary styling, promo coupon banner (`FIRSTORDER`), and category pills.
2. Click **Register** (`public/auth/register.php`). Create a customer account (e.g., `sunil.p@gmail.com` / `Password123!`). Demonstrate that the account is hashed securely with `password_hash()` and the user is immediately logged in.
3. Navigate to **Our Menu** (`public/menu/list.php`). Filter by category "Signature Kottu Roti" and search for "cheese".
4. Open the dish detail page (`public/menu/detail.php?id=7`). Point out the live inventory counter ("In Stock (40 left)").
5. Select quantity `2` and click **Add to Cart**.
6. View Cart (`public/orders/cart.php`). Enter promotional voucher `FIRSTORDER`. Show that 15% discount is calculated dynamically.
7. Click **Proceed to Checkout** (`public/orders/checkout.php`). Choose **Express Delivery**, select **Card Payment**, and click **Confirm & Place Order**.
8. Show the **Printable Invoice Receipt** (`public/orders/confirmation.php`) with snapshot prices and generated order number (e.g. `UE-20261027-XXXX`).
9. Click **Track Live Kitchen Status** (`public/orders/tracking.php`). Show the visual timeline stepper currently in stage **Placed**.

### Step 2: Kitchen Staff Processing (Student 2 & Student 3) - 1.5 Mins
1. Log out and log in as Kitchen Staff: `chef.kasun@urbaneats.lk` / `Password123!`.
2. Navigate to **Kitchen Queue** (`public/orders/kitchen.php`). Point out the new incoming ticket in the **Placed** column.
3. Click **Start Preparing 🍳**. Show that the status updates to **Preparing**, and a record is logged in `order_status_history`.
4. Click **Mark Ready for Dispatch ✅**. Status updates to **Ready**.
5. Click **Hand to Driver (Out for Delivery) 🛵**. Status updates to **Out for Delivery**.
6. Switch to the customer tracking page to show the customer's timeline updated in real-time.
7. Click **Mark Completed 🎉** to finalize the order.
8. Navigate to **Stock Dashboard** (`public/menu/stock.php`). Show that inventory was automatically decremented by 2 units for the order without manual intervention, and demonstrate recording 1 unit wastage.

### Step 3: Administrator Intelligence & Audit Trail (Student 1) - 2.0 Mins
1. Log in as Administrator: `admin@urbaneats.lk` / `Password123!`.
2. Open the **Admin Dashboard** (`public/admin/dashboard.php`). Review executive KPIs (Total Revenue, Active Users, Low Stock Alerts).
3. Open **Sales Reports** (`public/admin/reports.php`). Select the date range, show gross revenue, average order value (AOV), orders per status distribution, and the **Top-Selling Dishes** query table.
4. Open **User Management** (`public/admin/users.php`). Search for the customer created in Step 1, demonstrate the status toggle (activate/deactivate) and role change dropdown.
5. Open **System Audit Trail** (`public/admin/audit_log.php`). Show the complete immutable log of events: `USER_REGISTERED` &rarr; `ORDER_PLACED` &rarr; `ORDER_STATUS_UPDATE` with timestamps and IP addresses.

---

## 🎯 Part 2: Individual Viva Defense (By Component)

---

### Component A: Identity, Access & Administration (Student 1)

#### Q1: Walk through what happens from the moment the login form is submitted to the moment the dashboard loads.
**Defense Answer:**
1. **Submission & CSRF Guard:** The browser sends an HTTP POST request to `/public/auth/login.php` containing `email`, `password`, and the hidden `csrf_token`. `validate_csrf_or_fail()` compares the submitted token against `$_SESSION['csrf_token']` using `hash_equals()`. If missing or invalid, it immediately halts with HTTP 403 Forbidden.
2. **Prepared Statement Query:** `attempt_login()` queries the database using PDO:
   ```sql
   SELECT u.*, r.name AS role_name FROM users u JOIN roles r ON u.role_id = r.id WHERE u.email = :email LIMIT 1
   ```
   No SQL concatenation is used, neutralizing SQL injection.
3. **Lockout Check:** The system verifies `lockout_until`. If a lockout is active (`lockout_until > NOW()`), authentication halts, returning the remaining minutes to the user.
4. **Password Verification:** It calls `password_verify($password, $user['password_hash'])`.
   - *If invalid:* It increments `failed_login_attempts`. If attempts reach 5, it sets `lockout_until = NOW() + 15 MINUTE`, logs to `login_attempts`, and calls `log_action($pdo, 'ACCOUNT_LOCKED', ...)`.
   - *If valid:* It resets `failed_login_attempts = 0` and clears `lockout_until`.
5. **Session Regeneration:** It calls `session_regenerate_id(true)` to destroy the old session ID and issue a new one, mitigating session fixation attacks.
6. **Session Population & Redirection:** Session variables (`$_SESSION['user_id']`, `$_SESSION['role_name']`, `$_SESSION['last_activity']`) are set. `log_action()` records `LOGIN_SUCCESS`. The user is redirected based on their role (`admin` &rarr; `/admin/dashboard.php`).

#### Q2: Why `password_hash()` instead of MD5 or SHA-1? What does `session_regenerate_id()` prevent?
**Defense Answer:**
- **Why `password_hash()`:** MD5 and SHA-1 are fast general-purpose hashing algorithms with known cryptographic collisions. Because GPUs can compute billions of MD5 hashes per second, attacker rainbow tables easily crack them. `password_hash()` uses **Bcrypt** (or Argon2), which is an intentionally slow, CPU/memory-intensive adaptive key derivation function with an automatically generated cryptographically secure per-user salt. Even identical passwords produce completely different hashes.
- **Why `session_regenerate_id(true)`:** It eliminates **Session Fixation attacks**. In session fixation, an attacker tricks a victim into using a known session ID (e.g. via a malicious link `PHPSESSID=12345`). If the user logs in without regenerating the session ID, the attacker can hijack the authenticated session using that known ID. `session_regenerate_id(true)` forces the server to mint a new token upon privilege elevation and deletes the old session file.

#### Q3: How does `require_role()` protect a page, and what happens if a user types an admin URL directly?
**Defense Answer:**
- The guard function `require_role($roles)` first calls `require_login()`, which checks `is_logged_in()`. If the user is unauthenticated, it preserves the target URL in `$_SESSION['redirect_after_login']` and redirects to `/auth/login.php`.
- If the user is logged in (e.g., as a customer) but types an admin URL directly like `/admin/users.php`, `has_role('admin')` returns `false`.
- The system sets HTTP response status `403 Forbidden` and renders the custom error page (`public/403.php`) explaining that their current role does not possess permissions to view the administrative resource.

#### Q4: How does the password reset mechanism work without emailing?
**Defense Answer:**
- In `/auth/forgot.php`, the user submits their email.
- The server generates 32 bytes of secure entropy using `random_bytes(32)` converted to hex:
  ```php
  $plainToken = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $plainToken);
  ```
- The hash is inserted into `password_resets` with `expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)` and `is_used = 0`.
- Per the assignment brief, instead of dispatching via SMTP, the system displays the complete URL containing the plain token (`/auth/reset.php?token=...`) on screen in an alert banner.
- When accessed, `reset.php` re-hashes the GET parameter with SHA-256 and searches the database. If valid and not expired, the user updates their password, and `is_used` is set to 1.

---

### Component B: Menu, Catalogue & Inventory (Student 2)

#### Q1: Show how the search and pagination query is built. How do you stop SQL injection through the sort parameter?
**Defense Answer:**
- **Building the Query:** Query parameters are retrieved from `$_GET`. Input values are trimmed, and parameterized SQL is constructed dynamically:
  ```php
  $whereClauses = ['d.is_active = 1'];
  $params = [];
  if (!empty($search)) {
      $whereClauses[] = "(d.name LIKE :search OR d.description LIKE :search)";
      $params[':search'] = "%{$search}%";
  }
  if (!empty($catId)) {
      $whereClauses[] = "d.category_id = :cat_id";
      $params[':cat_id'] = $catId;
  }
  ```
  The count query calculates `$totalDishes`, and `$totalPages = ceil($totalDishes / $perPage)`. The main query executes with `LIMIT :limit OFFSET :offset`, bound with `PDO::PARAM_INT`.
- **SQL Injection Defense on Sort:** Column names and SQL directions (`ASC`/`DESC`) cannot be bound as prepared statement parameters via `:sort`. If a developer simply concatenates `ORDER BY " . $_GET['sort']`, attackers can inject SQL. We prevent this by strictly whitelisting allowed sort keys using PHP 8 `match`:
  ```php
  $orderSql = match ($sort) {
      'price_asc'  => 'd.price ASC',
      'price_desc' => 'd.price DESC',
      'name_asc'   => 'd.name ASC',
      'name_desc'  => 'd.name DESC',
      default      => 'd.id ASC'
  };
  ```
  Any arbitrary string sent by an attacker falls back safely to `d.id ASC`.

#### Q2: What checks does your image upload perform, and why is the file extension alone not enough?
**Defense Answer:**
- **Why extension is not enough:** Relying on `pathinfo($file['name'], PATHINFO_EXTENSION)` is dangerous because an attacker can rename a PHP web shell to `shell.php.jpg` or `shell.php%00.jpg`. Furthermore, some servers execute files containing `.php` anywhere in the filename.
- **Our 5-Layer Upload Defense:**
  1. **Upload Error Check:** Ensures `$_FILES['dish_image']['error'] === UPLOAD_ERR_OK`.
  2. **File Size Cap:** Enforces maximum size limit of 2MB (`$file['size'] <= 2 * 1024 * 1024`).
  3. **Real MIME Type Inspection via Magic Bytes:** We use PHP's `finfo` extension:
     ```php
     $finfo = new finfo(FILEINFO_MIME_TYPE);
     $mime = $finfo->file($file['tmp_name']);
     ```
     Only `image/jpeg`, `image/png`, and `image/webp` are permitted.
  4. **Filename Sanitization:** The original filename is discarded. A cryptographically random name is generated:
     ```php
     $uniqueName = 'dish_' . bin2hex(random_bytes(16)) . '.' . $targetExt;
     ```
  5. **Server Execution Denial:** The `.htaccess` file inside `public/uploads/` turns the PHP engine off (`php_flag engine off`) and denies execution of script extensions (`.php`, `.py`, `.sh`), rendering uploaded scripts harmless.

#### Q3: Explain why `reduce_stock()` uses a conditional UPDATE, and what happens with two simultaneous orders for the last item.
**Defense Answer:**
- **The Concurrency Race Condition:** If item stock is 1, and two customers submit checkout simultaneously, a naive implementation doing `SELECT current_stock` followed by `UPDATE` will read `stock = 1` for both users. Both orders will succeed, reducing stock to -1 and overselling kitchen capacity.
- **Our Atomic Solution:** In `includes/functions.php`:
  ```sql
  UPDATE inventory
  SET current_stock = current_stock - :qty,
      updated_at = NOW()
  WHERE dish_id = :dish_id AND current_stock >= :min_qty;
  ```
- **How it works with two simultaneous checkouts:**
  - Database row-level locking ensures only one transaction updates the row first.
  - Transaction 1 decrements stock from 1 to 0. `rowCount()` returns `1` (Success).
  - Transaction 2 then executes. However, because `current_stock` is now `0`, the condition `current_stock >= 1` evaluates to FALSE.
  - Zero rows are modified (`rowCount() === 0`).
  - The function throws an `Exception("Insufficient stock for '...'")`.
  - Transaction 2 catches the exception, executes `pdo->rollBack()`, undoes order insertion, and alerts the second customer that the dish just sold out.

---

### Component C: Ordering, Payment & Tracking (Student 3)

#### Q1: Why are prices re-read from the database at checkout? Show the checkout transaction and describe the rollback case.
**Defense Answer:**
- **Why Re-read Prices:** Client browsers can be inspected and manipulated via Developer Tools or intercepting proxies (e.g. Burp Suite). If prices stored in hidden HTML form fields or session cookies are trusted, an attacker can modify a LKR 2,450.00 crab curry to LKR 1.00. In `get_cart_details()` and `orders/checkout.php`, the system queries the live price from `dishes.price` inside an active transaction.
- **The Checkout Transaction Code:**
  ```php
  $pdo->beginTransaction();
  try {
      // 1. Insert Order
      $orderStmt->execute([...]);
      $orderId = $pdo->lastInsertId();

      // 2. Loop Cart Items
      foreach ($cartItems as $item) {
          // Lock row and read verified price
          $dishCheckStmt->execute([':id' => $item['dish_id']]);
          $verifiedDish = $dishCheckStmt->fetch();
          
          // Insert snapshot into order_items
          $itemStmt->execute([...]);
          
          // Deduct stock (refuses < 0)
          reduce_stock($pdo, $item['dish_id'], $item['quantity']);
      }

      // 3. Record Payment
      $payStmt->execute([...]);

      // 4. Record Initial Status History
      $histStmt->execute([...]);

      $pdo->commit();
  } catch (Exception $e) {
      $pdo->rollBack();
      $error = "Checkout Failed: " . $e->getMessage();
  }
  ```
- **The Rollback Case:** If `reduce_stock()` fails due to ingredient exhaustion, an exception is thrown. The `catch` block calls `$pdo->rollBack()`. The database rolls back the transaction entirely: the order row in `orders` is removed, no `order_items` remain, no payment is recorded, and already-deducted items in the loop are reverted to their original stock counts.

#### Q2: How do you prevent an order jumping from Placed straight to Completed?
**Defense Answer:**
- In `includes/functions.php`, the function `is_valid_status_transition($currentStatus, $newStatus)` implements a strict finite state machine:
  ```php
  $transitions = [
      'Placed'           => ['Preparing', 'Cancelled'],
      'Preparing'        => ['Ready', 'Cancelled'],
      'Ready'            => ['Out for Delivery', 'Collected', 'Cancelled'],
      'Out for Delivery' => ['Completed', 'Cancelled'],
      'Collected'        => ['Completed'],
      'Completed'        => [],
      'Cancelled'        => []
  ];
  return in_array($newStatus, $transitions[$currentStatus] ?? [], true);
  ```
- When staff clicks an action button in `orders/kitchen.php`, the backend queries the order's existing status from the database and validates the proposed transition. If an unauthorized jump (like `Placed` &rarr; `Completed`) is attempted, the request is rejected with a validation flash message.

#### Q3: Explain the SQL behind the top-selling dishes report (joins, GROUP BY, ORDER BY).
**Defense Answer:**
- In `public/admin/reports.php`:
  ```sql
  SELECT 
      oi.dish_id,
      oi.dish_name,
      c.name AS category_name,
      SUM(oi.quantity) AS total_quantity_sold,
      SUM(oi.subtotal) AS total_dish_revenue
  FROM order_items oi
  JOIN orders o ON oi.order_id = o.id
  JOIN dishes d ON oi.dish_id = d.id
  JOIN categories c ON d.category_id = c.id
  WHERE o.status = 'Completed'
    AND o.created_at BETWEEN :start_date AND :end_date
  GROUP BY oi.dish_id, oi.dish_name, c.name
  ORDER BY total_dish_revenue DESC
  LIMIT 10;
  ```
- **Explanation:**
  - `FROM order_items oi`: The central transaction line-item table containing quantities and subtotals.
  - `JOIN orders o`: Connects order lines to their parent order to check that `o.status = 'Completed'` and filter by `o.created_at` date range.
  - `JOIN dishes d` and `JOIN categories c`: Resolves the category name for presentation.
  - `GROUP BY oi.dish_id, oi.dish_name, c.name`: Groups all individual order lines by dish entity.
  - `SUM(oi.quantity)` & `SUM(oi.subtotal)`: Calculates aggregate units sold and gross revenue per dish.
  - `ORDER BY total_dish_revenue DESC`: Ranks highest revenue earners at the top.

---

### Questions for All Students

#### Q1: Show your ERD and justify one normalisation decision and one foreign key rule.
**Defense Answer:**
- **Normalization Decision:** We separated dish inventory into a dedicated `inventory` table instead of putting `current_stock` and `reorder_level` directly into `dishes`. In a cloud kitchen, stock counters fluctuate continuously with every sale, restock, and wastage event. Decoupling volatile inventory counters from the descriptive dish catalogue prevents row-level contention and table-level locks when staff edit dish descriptions while customers are simultaneously placing orders.
- **Foreign Key Rule Justification:** `order_items.order_id` references `orders.id` with `ON DELETE CASCADE`. If a test order is wiped or purged, all corresponding line items must cascade and delete to prevent orphan rows that consume storage and cause reporting discrepancies. Conversely, `audit_log.user_id` uses `ON DELETE SET NULL` so that if a user account is removed, the audit trail of past actions remains intact for compliance.

#### Q2: Where does your component depend on a teammate's component, and how did you test it before they finished?
**Defense Answer:**
- Component C (Checkout) depends on Component B's `reduce_stock()` function and Component A's `users` table and `$_SESSION['user_id']`.
- Component B (Dishes) depends on Component A's `require_role()` guard.
- **How we tested before integration:** In Week 1, we published our contract specifications. While Component B was finalizing image uploads, Component C tested the checkout transaction against a mock `reduce_stock()` function stub that simply returned `true`. Similarly, Component B tested dish management by temporarily spoofing `$_SESSION['role_name'] = 'kitchen_staff'` before the complete login throttling UI was completed.

#### Q3: Describe one bug you found late and how you fixed it.
**Defense Answer:**
- **The Bug:** During integration testing of `reduce_stock()`, when PDO was run with native prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`), the query threw `PDOException: SQLSTATE[HY093]: Invalid parameter number`.
- **The Root Cause:** In `reduce_stock()`, the SQL query used `:qty` twice (`SET current_stock = current_stock - :qty ... WHERE current_stock >= :qty`). When emulation is disabled, MySQL prepared statement protocol forbids reusing the same named placeholder multiple times in a single query.
- **The Fix:** We updated the query to use distinct parameter placeholders: `:qty` for the decrement and `:min_qty` for the availability check (`WHERE current_stock >= :min_qty`), binding the quantity variable to both placeholders. The query now runs flawlessly on all MySQL versions.

---

## 💻 Part 3: Live Modification Rehearsal (5 Marks)

During the viva, the examiner may ask you to make a small live change. Here are the most common scenarios and how to execute them in under 2 minutes:

### Live Task 1: Add a new column to a table and display it in the interface
- *Example:* Add a `preparation_time_minutes` column to `dishes` and show it on the dish detail page.
- **Step 1 (SQL):** Run query in phpMyAdmin / terminal:
  ```sql
  ALTER TABLE dishes ADD COLUMN preparation_time_minutes INT NOT NULL DEFAULT 20 AFTER price;
  ```
- **Step 2 (PHP):** In `public/menu/detail.php`, find the price display and add:
  ```php
  <div style="font-size: 0.9rem; color: var(--text-muted); margin-top: 0.25rem;">
      ⏱️ Estimated Prep Time: <?= (int)$dish['preparation_time_minutes'] ?> mins
  </div>
  ```

### Live Task 2: Change a business validation rule
- *Example:* Increase the failed login lockout attempts from 5 to 7.
- **Fix:** In `config/config.sample.php` or `config/db.php`:
  ```php
  'max_failed_logins' => 7,
  ```

### Live Task 3: Add an extra order status or allowed transition
- *Example:* Allow kitchen staff to transition an order directly from `Preparing` to `Cancelled` if ingredients spoil.
- **Fix:** In `includes/functions.php` inside `is_valid_status_transition()`:
  Verify that `'Preparing' => ['Ready', 'Cancelled']` contains `'Cancelled'`. Add a button in `orders/kitchen.php`.
