-- ============================================================================
-- UrbanEats Cloud Kitchen Platform - Database Schema & Seed Data
-- Module: IT3113 Advanced Web Technologies
-- Compatible with MySQL 8.x and MariaDB (phpMyAdmin importable)
-- Normalized to 3NF with Primary Keys, Foreign Keys, Indexes & Constraints
-- ============================================================================

CREATE DATABASE IF NOT EXISTS `urbaneats_db`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `urbaneats_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- Drop existing tables (clean import)
-- ----------------------------------------------------------------------------
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `grn_items`;
DROP TABLE IF EXISTS `grn_headers`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `dishes`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- COMPONENT A: IDENTITY, ACCESS AND ADMINISTRATION TABLES
-- ============================================================================

-- Table 1: roles
CREATE TABLE `roles` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50) NOT NULL,
  `description` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 2: users
CREATE TABLE `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `role_id` INT UNSIGNED NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `phone` VARCHAR(20) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `address` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_login_attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `lockout_until` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_active` (`is_active`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 3: login_attempts (Login throttling & security logging)
CREATE TABLE `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_address` VARCHAR(45) NOT NULL,
  `email_attempted` VARCHAR(150) NOT NULL,
  `is_successful` TINYINT(1) NOT NULL,
  `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempts_ip_email` (`ip_address`, `email_attempted`),
  KEY `idx_attempts_time` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 4: audit_log (Centralized audit trail called across Components A, B, and C)
CREATE TABLE `audit_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_time` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 5: password_resets (Secure token-based password recovery)
CREATE TABLE `password_resets` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `is_used` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pwd_token` (`token_hash`),
  KEY `idx_pwd_user` (`user_id`),
  KEY `idx_pwd_expires` (`expires_at`),
  CONSTRAINT `fk_pwd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- COMPONENT B: MENU, CATALOGUE AND INVENTORY TABLES
-- ============================================================================

-- Table 6: categories
CREATE TABLE `categories` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `slug` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_categories_name` (`name`),
  UNIQUE KEY `uk_categories_slug` (`slug`),
  KEY `idx_categories_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 7: dishes (Soft delete via is_active ensures historical order integrity)
CREATE TABLE `dishes` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `price` DECIMAL(10,2) NOT NULL,
  `image_url` VARCHAR(255) NULL,
  `is_spicy` TINYINT(1) NOT NULL DEFAULT 0,
  `is_vegetarian` TINYINT(1) NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_dishes_category` (`category_id`),
  KEY `idx_dishes_active` (`is_active`),
  KEY `idx_dishes_price` (`price`),
  KEY `idx_dishes_name` (`name`),
  CONSTRAINT `fk_dishes_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 8: inventory (Current real-time stock and reorder thresholds)
CREATE TABLE `inventory` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dish_id` INT UNSIGNED NOT NULL,
  `current_stock` INT NOT NULL DEFAULT 0,
  `reorder_level` INT NOT NULL DEFAULT 10,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_inventory_dish` (`dish_id`),
  KEY `idx_inventory_stock` (`current_stock`),
  CONSTRAINT `fk_inventory_dish` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 9: stock_movements (Audit history for restocking, wastage, sales & adjustments)
CREATE TABLE `stock_movements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `dish_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NULL,
  `movement_type` ENUM('restock', 'wastage', 'sale', 'adjustment') NOT NULL,
  `quantity` INT NOT NULL,
  `reason` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movements_dish` (`dish_id`),
  KEY `idx_movements_user` (`user_id`),
  KEY `idx_movements_type` (`movement_type`),
  KEY `idx_movements_time` (`created_at`),
  CONSTRAINT `fk_movements_dish` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_movements_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 9a: grn_headers (Goods Received Note master records - Student 2 Component B)
CREATE TABLE `grn_headers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grn_number` VARCHAR(30) NOT NULL,
  `supplier_name` VARCHAR(150) NOT NULL,
  `invoice_reference` VARCHAR(100) NULL,
  `received_by_user_id` INT UNSIGNED NOT NULL,
  `notes` TEXT NULL,
  `total_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grn_number` (`grn_number`),
  KEY `idx_grn_user` (`received_by_user_id`),
  KEY `idx_grn_received_at` (`received_at`),
  CONSTRAINT `fk_grn_user` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 9b: grn_items (Line items received under each Goods Received Note)
CREATE TABLE `grn_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `grn_id` INT UNSIGNED NOT NULL,
  `dish_id` INT UNSIGNED NOT NULL,
  `quantity_received` INT UNSIGNED NOT NULL,
  `unit_cost` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `batch_number` VARCHAR(50) NULL,
  `expiry_date` DATE NULL,
  PRIMARY KEY (`id`),
  KEY `idx_grn_items_grn` (`grn_id`),
  KEY `idx_grn_items_dish` (`dish_id`),
  CONSTRAINT `fk_grn_items_grn` FOREIGN KEY (`grn_id`) REFERENCES `grn_headers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_grn_items_dish` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- COMPONENT C: ORDERING, PAYMENT AND TRACKING TABLES
-- ============================================================================

-- Table 10: coupons (Promotions & discount enforcement)
CREATE TABLE `coupons` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code` VARCHAR(50) NOT NULL,
  `discount_type` ENUM('percentage', 'fixed') NOT NULL,
  `discount_value` DECIMAL(10,2) NOT NULL,
  `min_order_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_discount` DECIMAL(10,2) NULL,
  `usage_limit` INT UNSIGNED NULL,
  `times_used` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_coupons_code` (`code`),
  KEY `idx_coupons_active_exp` (`is_active`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 11: orders
CREATE TABLE `orders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_number` VARCHAR(30) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `order_type` ENUM('delivery', 'pickup') NOT NULL DEFAULT 'delivery',
  `delivery_address` TEXT NULL,
  `contact_phone` VARCHAR(20) NOT NULL,
  `notes` TEXT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `coupon_id` INT UNSIGNED NULL,
  `delivery_fee` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('Placed', 'Preparing', 'Ready', 'Out for Delivery', 'Collected', 'Completed', 'Cancelled') NOT NULL DEFAULT 'Placed',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orders_num` (`order_number`),
  KEY `idx_orders_user` (`user_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_created` (`created_at`),
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 12: order_items (Stores unit_price snapshot at time of purchase)
CREATE TABLE `order_items` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `dish_id` INT UNSIGNED NOT NULL,
  `dish_name` VARCHAR(150) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `subtotal` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_items_order` (`order_id`),
  KEY `idx_items_dish` (`dish_id`),
  CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_items_dish` FOREIGN KEY (`dish_id`) REFERENCES `dishes` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 13: payments (Simulated payment gateways: COD, Card, Bank Transfer)
CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `payment_method` ENUM('cash_on_delivery', 'card', 'bank_transfer') NOT NULL,
  `payment_status` ENUM('pending', 'completed', 'failed', 'refunded') NOT NULL DEFAULT 'pending',
  `transaction_reference` VARCHAR(100) NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `paid_at` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_payments_order` (`order_id`),
  KEY `idx_payments_status` (`payment_status`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table 14: order_status_history (Audit timeline for order stage workflow)
CREATE TABLE `order_status_history` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id` INT UNSIGNED NOT NULL,
  `changed_by_user_id` INT UNSIGNED NULL,
  `old_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NOT NULL,
  `comment` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_order` (`order_id`),
  KEY `idx_history_time` (`created_at`),
  CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SEED DATA (At least 20 realistic rows in each main table)
-- Default test password for all seeded users: Password123!
-- ============================================================================

-- 1. Seed Roles
INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'admin', 'Administrator with full system privileges, user management, and financial reporting'),
(2, 'kitchen_staff', 'Kitchen operations team managing dishes, live inventory stock, and order queues'),
(3, 'customer', 'Registered patrons browsing menu, placing delivery/pickup orders, and tracking status');

-- 2. Seed Users (22 Realistic Users)
-- Hash: $2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO -> Password123!
INSERT INTO `users` (`id`, `role_id`, `full_name`, `email`, `phone`, `password_hash`, `address`, `is_active`, `failed_login_attempts`, `lockout_until`) VALUES
-- Administrators (2)
(1, 1, 'Maleesha Sanjana', 'admin@urbaneats.lk', '+94771234567', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'UrbanEats HQ, 45 Galle Road, Colombo 03', 1, 0, NULL),
(2, 1, 'Nethmi Mohotti', 'nethmi.admin@urbaneats.lk', '+94719876543', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'Level 4, Orion City, Colombo 09', 1, 0, NULL),
-- Kitchen Staff (4)
(3, 2, 'Kasun Fernando', 'chef.kasun@urbaneats.lk', '+94762345678', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'Kitchen Station 1, Colombo 03', 1, 0, NULL),
(4, 2, 'Dinuka Silva', 'dinuka.kitchen@urbaneats.lk', '+94753456789', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'Kitchen Station 2, Colombo 03', 1, 0, NULL),
(5, 2, 'Nuwan Bandara', 'nuwan.stock@urbaneats.lk', '+94704567890', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'Inventory Depo, Colombo 04', 1, 0, NULL),
(6, 2, 'Chathura Wickramasinghe', 'chathura@urbaneats.lk', '+94785678901', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', 'Prep Line, Colombo 03', 1, 0, NULL),
-- Customers (16)
(7, 3, 'Amara Jayawardena', 'amara.j@gmail.com', '+94776789012', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '12/4 Flower Road, Colombo 07', 1, 0, NULL),
(8, 3, 'Roshan De Silva', 'roshan.desilva@yahoo.com', '+94717890123', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '55 Havelock Road, Colombo 05', 1, 0, NULL),
(9, 3, 'Kavindi Perera', 'kavindi.p@outlook.com', '+94728901234', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '89 Duplication Road, Colombo 04', 1, 0, NULL),
(10, 3, 'Sahan Gunaratne', 'sahan.g@gmail.com', '+94769012345', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '34 Barnes Place, Colombo 07', 1, 0, NULL),
(11, 3, 'Tharushi Weerasinghe', 'tharushi.w@hotmail.com', '+94750123456', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '101 Kynsey Road, Colombo 08', 1, 0, NULL),
(12, 3, 'Janith Alwis', 'janith.alwis@gmail.com', '+94781234567', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '215 High Level Road, Nugegoda', 1, 0, NULL),
(13, 3, 'Dilani Rajapaksha', 'dilani.r@gmail.com', '+94702345678', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '18 Vajira Road, Colombo 04', 1, 0, NULL),
(14, 3, 'Praveen Senanayake', 'praveen.s@gmail.com', '+94773456789', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '77 Ward Place, Colombo 07', 1, 0, NULL),
(15, 3, 'Sanduni Fonseka', 'sanduni.f@yahoo.com', '+94714567890', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '42 Horton Place, Colombo 07', 1, 0, NULL),
(16, 3, 'Isuru Madushan', 'isuru.m@gmail.com', '+94765678901', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '63 Cotta Road, Borella, Colombo 08', 1, 0, NULL),
(17, 3, 'Nadeesha Karunaratne', 'nadeesha.k@gmail.com', '+94756789012', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '14 Stratford Avenue, Colombo 06', 1, 0, NULL),
(18, 3, 'Gayantha Kulatunga', 'gayantha.k@outlook.com', '+94787890123', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '9 Bagatalle Road, Colombo 03', 1, 0, NULL),
(19, 3, 'Hashini Abeykoon', 'hashini.a@gmail.com', '+94708901234', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '28 De Fonseka Road, Colombo 05', 1, 0, NULL),
(20, 3, 'Mahesh Ratnayake', 'mahesh.r@gmail.com', '+94779012345', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '150 Baseline Road, Colombo 09', 1, 0, NULL),
(21, 3, 'Shenali Cooray', 'shenali.c@yahoo.com', '+94710123456', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '33 Queen\'s Road, Colombo 03', 1, 0, NULL),
(22, 3, 'Damith Wickrama', 'damith.w@gmail.com', '+94721234567', '$2y$12$knheuVeGNB6xtm8vfX2H5e4dMWeWETlcBXgmW7PtCi/EXI0qobCBO', '56 Dharmapala Mawatha, Colombo 07', 1, 0, NULL);

-- 3. Seed Login Attempts (22 Rows)
INSERT INTO `login_attempts` (`ip_address`, `email_attempted`, `is_successful`, `attempted_at`) VALUES
('192.168.1.10', 'admin@urbaneats.lk', 1, '2026-10-25 08:30:12'),
('192.168.1.11', 'chef.kasun@urbaneats.lk', 1, '2026-10-25 09:00:20'),
('192.168.1.55', 'amara.j@gmail.com', 1, '2026-10-25 11:15:45'),
('203.115.24.12', 'attacker@random.com', 0, '2026-10-25 12:01:05'),
('203.115.24.12', 'admin@urbaneats.lk', 0, '2026-10-25 12:02:11'),
('192.168.1.88', 'roshan.desilva@yahoo.com', 1, '2026-10-25 12:45:00'),
('192.168.1.92', 'kavindi.p@outlook.com', 1, '2026-10-25 13:10:22'),
('192.168.1.10', 'admin@urbaneats.lk', 1, '2026-10-26 08:15:00'),
('192.168.1.12', 'dinuka.kitchen@urbaneats.lk', 1, '2026-10-26 08:45:10'),
('192.168.1.45', 'sahan.g@gmail.com', 1, '2026-10-26 10:20:30'),
('192.168.1.48', 'tharushi.w@hotmail.com', 1, '2026-10-26 11:05:15'),
('192.168.1.60', 'janith.alwis@gmail.com', 1, '2026-10-26 12:30:40'),
('192.168.1.75', 'dilani.r@gmail.com', 1, '2026-10-26 13:00:00'),
('192.168.1.80', 'praveen.s@gmail.com', 1, '2026-10-26 14:10:55'),
('192.168.1.85', 'sanduni.f@yahoo.com', 1, '2026-10-26 15:40:12'),
('192.168.1.90', 'isuru.m@gmail.com', 1, '2026-10-26 17:25:33'),
('192.168.1.10', 'admin@urbaneats.lk', 1, '2026-10-27 08:10:00'),
('192.168.1.11', 'chef.kasun@urbaneats.lk', 1, '2026-10-27 08:35:12'),
('192.168.1.33', 'nadeesha.k@gmail.com', 1, '2026-10-27 10:15:40'),
('192.168.1.35', 'gayantha.k@outlook.com', 1, '2026-10-27 11:20:05'),
('192.168.1.40', 'hashini.a@gmail.com', 1, '2026-10-27 12:40:50'),
('192.168.1.42', 'mahesh.r@gmail.com', 1, '2026-10-27 13:15:10');

-- 4. Seed Audit Log (25 Rows)
INSERT INTO `audit_log` (`user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES
(1, 'SYSTEM_INIT', 'Database initialized and core configuration verified', '127.0.0.1', '2026-10-24 09:00:00'),
(1, 'USER_CREATED', 'Created administrative account for Nethmi Mohotti', '192.168.1.10', '2026-10-24 09:30:00'),
(1, 'USER_CREATED', 'Added Kitchen Staff: Kasun Fernando', '192.168.1.10', '2026-10-24 09:45:00'),
(1, 'USER_CREATED', 'Added Kitchen Staff: Dinuka Silva', '192.168.1.10', '2026-10-24 09:50:00'),
(3, 'DISH_ADDED', 'Chef Kasun added signature dish: Classic Chicken Kottu', '192.168.1.11', '2026-10-24 10:15:00'),
(3, 'DISH_ADDED', 'Chef Kasun added signature dish: Special Seafood Lamprais', '192.168.1.11', '2026-10-24 10:30:00'),
(5, 'STOCK_RESTOCK', 'Restocked 50 units for Classic Chicken Kottu', '192.168.1.13', '2026-10-24 11:00:00'),
(7, 'USER_REGISTERED', 'Customer Amara Jayawardena registered via portal', '192.168.1.55', '2026-10-25 11:10:00'),
(7, 'ORDER_PLACED', 'Order UE-20261025-001 placed for LKR 2,450.00', '192.168.1.55', '2026-10-25 11:20:00'),
(3, 'ORDER_STATUS_UPDATE', 'Order UE-20261025-001 updated to Preparing', '192.168.1.11', '2026-10-25 11:25:00'),
(3, 'ORDER_STATUS_UPDATE', 'Order UE-20261025-001 updated to Ready', '192.168.1.11', '2026-10-25 11:45:00'),
(8, 'USER_REGISTERED', 'Customer Roshan De Silva registered via portal', '192.168.1.88', '2026-10-25 12:40:00'),
(8, 'ORDER_PLACED', 'Order UE-20261025-002 placed for LKR 3,100.00', '192.168.1.88', '2026-10-25 12:50:00'),
(4, 'ORDER_STATUS_UPDATE', 'Order UE-20261025-002 updated to Out for Delivery', '192.168.1.12', '2026-10-25 13:15:00'),
(1, 'COUPON_CREATED', 'Admin Maleesha created promotional code: FIRSTORDER', '192.168.1.10', '2026-10-26 08:30:00'),
(10, 'ORDER_PLACED', 'Order UE-20261026-003 placed with coupon FIRSTORDER', '192.168.1.45', '2026-10-26 10:30:00'),
(5, 'STOCK_ADJUSTMENT', 'Adjusted inventory after morning physical audit', '192.168.1.13', '2026-10-26 11:00:00'),
(11, 'ORDER_PLACED', 'Order UE-20261026-004 placed for LKR 4,200.00', '192.168.1.48', '2026-10-26 11:15:00'),
(3, 'ORDER_STATUS_UPDATE', 'Order UE-20261026-004 marked as Completed', '192.168.1.11', '2026-10-26 12:00:00'),
(1, 'ROLE_CHANGED', 'Admin promoted Nuwan Bandara to Senior Inventory Manager', '192.168.1.10', '2026-10-26 14:00:00'),
(14, 'ORDER_PLACED', 'Order UE-20261026-005 placed for LKR 1,850.00', '192.168.1.80', '2026-10-26 14:20:00'),
(1, 'REPORT_VIEWED', 'Admin generated monthly sales overview', '192.168.1.10', '2026-10-26 18:00:00'),
(3, 'ORDER_STATUS_UPDATE', 'Order UE-20261027-006 moved to Preparing', '192.168.1.11', '2026-10-27 09:00:00'),
(5, 'STOCK_WASTAGE', 'Recorded 2 units wastage for Fresh Milk Hoppers due to spill', '192.168.1.13', '2026-10-27 10:00:00'),
(1, 'USER_STATUS_UPDATE', 'Admin updated user status for Dilani Rajapaksha', '192.168.1.10', '2026-10-27 11:00:00');

-- 5. Seed Password Resets (5 Sample Records)
INSERT INTO `password_resets` (`user_id`, `token_hash`, `expires_at`, `is_used`, `created_at`) VALUES
(7, 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', '2026-10-28 12:00:00', 0, '2026-10-27 11:00:00'),
(8, '4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b', '2026-10-26 10:00:00', 1, '2026-10-26 09:00:00'),
(9, 'd4735e3a265e16eee03f59718b9b5d03019c07d8b6c51f90da3a666eec13ab35', '2026-10-27 18:00:00', 0, '2026-10-27 17:00:00'),
(12, '4e07408562bedb8b60ce05c1decfe3ad16b72230967de01f640b7e4729b49fce', '2026-10-25 15:00:00', 1, '2026-10-25 14:00:00'),
(14, '4b227777d4dd1fc61c6f884f48641d02b4d121d3fd328cb08b5531fcacdabf8a', '2026-10-28 15:30:00', 0, '2026-10-27 14:30:00');

-- 6. Seed Categories (6 Categories)
INSERT INTO `categories` (`id`, `name`, `slug`, `description`, `is_active`) VALUES
(1, 'Rice & Curry Specialties', 'rice-curry', 'Authentic Sri Lankan rice feasts, Dutch-Burgher lamprais, and regional curries cooked with fresh coconut milk and roasted spices.', 1),
(2, 'Signature Kottu Roti', 'kottu-roti', 'Chopped godamba roti stir-fried on smoking iron griddles with fresh vegetables, egg, savory gravies, and succulent meats.', 1),
(3, 'Dum Biryani Specials', 'biryani-specials', 'Fragrant long-grain basmati rice layered with marinated meats, caramelized onions, boiled eggs, and mint sambol.', 1),
(4, 'Short Eats & Street Bites', 'short-eats', 'Sri Lanka\'s beloved snacks: crispy fish cutlets, spicy vegetable rotis, egg hoppers, and hot butter cuttlefish.', 1),
(5, 'Traditional Sweets & Desserts', 'desserts', 'Indulgent traditional delights: steamed jaggery watalappan, buffalo curd drizzled with kitul treacle, and caramel puddings.', 1),
(6, 'Tropical Beverages', 'beverages', 'Fresh king coconut water, chilled Colombo faluda, tangy woodapple smoothies, and hot aromatic Ceylon spiced tea.', 1);

-- 7. Seed Dishes (22 Authentic Sri Lankan Cloud Kitchen Dishes)
INSERT INTO `dishes` (`id`, `category_id`, `name`, `description`, `price`, `image_url`, `is_spicy`, `is_vegetarian`, `is_active`) VALUES
-- Category 1: Rice & Curry
(1, 1, 'Traditional Chicken Rice & Curry', 'Steamed samba rice served with tender Sri Lankan chicken curry, dhal curry, tempered potato fry, pol sambol, and crispy papadum.', 950.00, 'chicken_rice_curry.jpg', 1, 0, 1),
(2, 1, 'Dutch-Burgher Special Lamprais', 'Fragrant samba rice cooked in rich stock, mixed meat curry (chicken and mutton), frikkadels, seeni sambol, and ash plantain wrapped in toasted banana leaf.', 1650.00, 'lamprais.jpg', 1, 0, 1),
(3, 1, 'Jaffna Fiery Crab Curry Feast', 'Fresh blue lagoon crab cooked in rich toasted spices, crushed roasted chili, moringa leaves, and creamy coconut milk.', 2450.00, 'jaffna_crab.jpg', 1, 0, 1),
(4, 1, 'Southern Black Pork Curry Meal', 'Succulent pork cubes simmered with roasted goraka, dark roasted curry powder, and black pepper, served with red rice and tempered beans.', 1350.00, 'black_pork.jpg', 1, 0, 1),
(5, 1, 'Village Pol Roti & Dhal Curry Platter', 'Four freshly griddled coconut rotis served with creamy yellow lentil curry, fiery katta sambol, and onion seeni sambol.', 650.00, 'pol_roti_platter.jpg', 1, 1, 1),
-- Category 2: Kottu
(6, 2, 'Classic Shredded Chicken Kottu', 'Handmade godamba roti chopped with fried chicken slices, shredded leeks, carrots, egg, and spicy chicken gravy.', 1150.00, 'chicken_kottu.jpg', 1, 0, 1),
(7, 2, 'Urban Roast Chicken Cheese Kottu', 'Loaded with double cheddar and mozzarella, tender roast chicken chunks, green chilies, and creamy curry reduction.', 1550.00, 'cheese_kottu.jpg', 1, 0, 1),
(8, 2, 'Hot Butter Cuttlefish Kottu', 'Crispy batter-fried cuttlefish tossed with sliced green chilies, capsicum, butter chili sauce, and shredded flaky roti.', 1750.00, 'hbc_kottu.jpg', 1, 0, 1),
(9, 2, 'Vegetable & Paneer Masala Kottu', 'Fresh shredded roti stir-fried with farm vegetables, spiced paneer cubes, roasted cumin, and rich tomato-onion reduction.', 950.00, 'paneer_kottu.jpg', 0, 1, 1),
-- Category 3: Biryani
(10, 3, 'Colombo Royal Chicken Dum Biryani', 'Fragrant basmati rice layered with spiced chicken thigh, golden fried onions, boiled egg, mint sambol, and cooling cucumber raita.', 1450.00, 'chicken_biryani.jpg', 1, 0, 1),
(11, 3, 'Slow-Cooked Mutton Biryani', 'Slow-cooked succulent mutton in aromatic spices, basmati rice infused with saffron, fried cashew nuts, boiled egg, and pineapple chutney.', 2150.00, 'mutton_biryani.jpg', 1, 0, 1),
(12, 3, 'Garden Vegetable & Cashew Biryani', 'Long grain basmati steamed with fresh garden peas, baby carrots, potatoes, golden cashews, and aromatic biryani spices.', 1050.00, 'veg_biryani.jpg', 0, 1, 1),
-- Category 4: Short Eats
(13, 4, 'Crispy Sri Lankan Fish Cutlets (5 Pcs)', 'Panko-crusted spheres filled with spiced jack mackerel, mashed potato, diced onions, and crushed black pepper.', 550.00, 'fish_cutlets.jpg', 1, 0, 1),
(14, 4, 'Hot Butter Cuttlefish Bites', 'Crisp seasoned cuttlefish rings wok-tossed with fresh butter, dried chili flakes, sliced spring onions, and garlic.', 1450.00, 'hbc_bites.jpg', 1, 0, 1),
(15, 4, 'Spicy Vegetable Triangular Rotis (3 Pcs)', 'Flaky griddled triangular godamba roti parcels packed with curried potatoes, carrots, and green peas.', 400.00, 'veg_roti.jpg', 1, 1, 1),
(16, 4, 'Egg Hopper & Seeni Sambol Set (4 Pcs)', 'Two crispy bowl-shaped rice flour egg hoppers and two plain hoppers accompanied by caramelized sweet and spicy onion seeni sambol.', 550.00, 'hoppers_set.jpg', 0, 1, 1),
(17, 4, 'Devilled Chicken Wings', 'Tender chicken wings coated in a sweet, spicy, and tangy chili glaze with chunks of sautéed capsicum and red onions.', 1200.00, 'devilled_chicken.jpg', 1, 0, 1),
-- Category 5: Desserts
(18, 5, 'Traditional Spiced Watalappan', 'Steamed coconut milk and kitul jaggery pudding infused with ground nutmeg, cardamom, and topped with toasted cashews.', 450.00, 'watalappan.jpg', 0, 1, 1),
(19, 5, 'Buffalo Curd with Pure Kitul Treacle', 'Creamy clay-pot curd served with generous drizzles of pure organic sweet palm nectar.', 500.00, 'curd_treacle.jpg', 0, 1, 1),
(20, 5, 'Rich Caramel Pudding Slice', 'Silky baked custard slice topped with golden caramelized sugar glaze.', 420.00, 'caramel_pudding.jpg', 0, 1, 1),
-- Category 6: Beverages
(21, 6, 'Royal Colombo Rose Faluda', 'Chilled rose milk layered with sweet basil seeds (kasa kasa), grass jelly cubes, and topped with rich vanilla ice cream.', 550.00, 'faluda.jpg', 0, 1, 1),
(22, 6, 'Fresh King Coconut Water', 'Pure, electrolyte-rich natural chilled water served straight from golden Sri Lankan king coconuts.', 350.00, 'king_coconut.jpg', 0, 1, 1);

-- 8. Seed Inventory (Current Stock and Reorder Thresholds for All 22 Dishes)
INSERT INTO `inventory` (`dish_id`, `current_stock`, `reorder_level`) VALUES
(1, 45, 10),
(2, 28, 8),
(3, 12, 5),
(4, 25, 8),
(5, 50, 15),
(6, 65, 15),
(7, 40, 12),
(8, 18, 6),
(9, 30, 8),
(10, 50, 12),
(11, 15, 5),
(12, 25, 6),
(13, 80, 20),
(14, 22, 8),
(15, 45, 15),
(16, 35, 10),
(17, 30, 8),
(18, 40, 10),
(19, 50, 12),
(20, 30, 8),
(21, 40, 10),
(22, 60, 15);

-- 9. Seed Stock Movements (22 Audit Records)
INSERT INTO `stock_movements` (`dish_id`, `user_id`, `movement_type`, `quantity`, `reason`, `created_at`) VALUES
(1, 5, 'restock', 50, 'Morning batch kitchen preparation', '2026-10-25 08:00:00'),
(2, 5, 'restock', 30, 'Banana leaf wrap batch completed', '2026-10-25 08:30:00'),
(6, 5, 'restock', 70, 'Fresh godamba roti prep', '2026-10-25 08:45:00'),
(10, 5, 'restock', 55, 'Dum biryani pot slow simmered', '2026-10-25 09:00:00'),
(1, NULL, 'sale', -2, 'Deducted for Order #UE-20261025-001', '2026-10-25 11:20:00'),
(6, NULL, 'sale', -1, 'Deducted for Order #UE-20261025-001', '2026-10-25 11:20:00'),
(2, NULL, 'sale', -1, 'Deducted for Order #UE-20261025-002', '2026-10-25 12:50:00'),
(7, NULL, 'sale', -1, 'Deducted for Order #UE-20261025-002', '2026-10-25 12:50:00'),
(3, 5, 'restock', 15, 'Lagoon crab delivery processed', '2026-10-26 08:00:00'),
(11, 5, 'restock', 20, 'Slow cooked mutton batch finished', '2026-10-26 08:30:00'),
(13, 5, 'restock', 90, 'Fish cutlet batch fried', '2026-10-26 09:00:00'),
(21, 5, 'restock', 45, 'Chilled faluda mix prepared', '2026-10-26 09:15:00'),
(10, NULL, 'sale', -2, 'Deducted for Order #UE-20261026-003', '2026-10-26 10:30:00'),
(18, NULL, 'sale', -2, 'Deducted for Order #UE-20261026-003', '2026-10-26 10:30:00'),
(3, NULL, 'sale', -1, 'Deducted for Order #UE-20261026-004', '2026-10-26 11:15:00'),
(8, NULL, 'sale', -1, 'Deducted for Order #UE-20261026-004', '2026-10-26 11:15:00'),
(16, 5, 'wastage', -2, 'Dropped hopper tray during prep', '2026-10-26 16:00:00'),
(4, 5, 'restock', 25, 'Southern black pork curry simmered', '2026-10-27 08:00:00'),
(14, 5, 'restock', 25, 'Fresh cuttlefish cleaned and prepped', '2026-10-27 08:15:00'),
(22, 5, 'restock', 60, 'Fresh king coconuts received from Kurunegala', '2026-10-27 08:30:00'),
(6, NULL, 'sale', -1, 'Deducted for Order #UE-20261027-006', '2026-10-27 09:00:00'),
(21, NULL, 'sale', -1, 'Deducted for Order #UE-20261027-006', '2026-10-27 09:00:00');

-- 9a. Seed Goods Received Notes (8 Audited GRN Headers - Component B)
INSERT INTO `grn_headers` (`id`, `grn_number`, `supplier_name`, `invoice_reference`, `received_by_user_id`, `notes`, `total_cost`, `received_at`, `created_at`) VALUES
(1, 'GRN-20261023-1001', 'Ceylon Agro Poultry & Spices Ltd', 'INV-CAG-8921', 5, 'Morning fresh poultry and spices batch delivery. All quality checks passed.', 64500.00, '2026-10-23 07:45:00', '2026-10-23 07:45:00'),
(2, 'GRN-20261024-1002', 'Jaffna Coastal Fisheries Union', 'DN-JCF-4410', 5, 'Chilled lagoon crab, prawn, and cuttlefish in temperature-controlled coolers.', 71750.00, '2026-10-24 07:30:00', '2026-10-24 07:30:00'),
(3, 'GRN-20261024-1003', 'Colombo Dairy & Beverages Depot', 'CDCD-7729', 3, 'Fresh curd, rose syrup, and king coconut batch from Kurunegala.', 27600.00, '2026-10-24 08:45:00', '2026-10-24 08:45:00'),
(4, 'GRN-20261025-1004', 'Kandy Valley Organic Millers', 'KVP-9031', 4, 'Flour, godamba roti, cutlet crumbs, and fresh pol roti mix for shifts.', 38000.00, '2026-10-25 07:15:00', '2026-10-25 07:15:00'),
(5, 'GRN-20261025-1005', 'Dutch Heritage Specialty Foods', 'DHF-3312', 5, 'Banana leaf wrapped lamprais prep and spiced cashew watalappan trays.', 49000.00, '2026-10-25 08:30:00', '2026-10-25 08:30:00'),
(6, 'GRN-20261026-1006', 'Lanka Highland Spices & Livestock Depot', 'LHS-7890', 3, 'Tender mutton cut pieces, basmati rice, and poultry bites for devilled dish.', 62500.00, '2026-10-26 07:20:00', '2026-10-26 07:20:00'),
(7, 'GRN-20261026-1007', 'Southern Coast Cuttlefish & Provisions', 'SCC-5541', 4, 'Cleaned cuttlefish rings, paneer blocks, and kottu seasonings.', 51750.00, '2026-10-26 08:00:00', '2026-10-26 08:00:00'),
(8, 'GRN-20261027-1008', 'Ceylon Bakeries & Confectionery Ltd', 'CBC-8822', 3, 'Fresh hopper batter, egg pudding base, and jaggery custard.', 28500.00, '2026-10-27 07:00:00', '2026-10-27 07:00:00');

-- 9b. Seed Goods Received Note Items (24 Item Transactions on Dishes 1-22 - Component B)
INSERT INTO `grn_items` (`grn_id`, `dish_id`, `quantity_received`, `unit_cost`, `line_total`, `batch_number`, `expiry_date`) VALUES
(1, 1, 50, 450.00, 22500.00, 'BATCH-20261023-A1', '2026-10-27'),
(1, 4, 30, 800.00, 24000.00, 'BATCH-20261023-A2', '2026-10-28'),
(1, 10, 30, 600.00, 18000.00, 'BATCH-20261023-A3', '2026-10-26'),
(2, 3, 20, 1600.00, 32000.00, 'BATCH-20261024-B1', '2026-10-28'),
(2, 5, 25, 1100.00, 27500.00, 'BATCH-20261024-B2', '2026-10-27'),
(2, 14, 15, 816.67, 12250.00, 'BATCH-20261024-B3', '2026-10-29'),
(3, 20, 40, 300.00, 12000.00, 'BATCH-20261024-C1', '2026-11-04'),
(3, 21, 45, 200.00, 9000.00, 'BATCH-20261024-C2', '2026-11-01'),
(3, 22, 60, 110.00, 6600.00, 'BATCH-20261024-C3', '2026-11-05'),
(4, 6, 70, 380.00, 26600.00, 'BATCH-20261025-D1', '2026-10-30'),
(4, 13, 90, 60.00, 5400.00, 'BATCH-20261025-D2', '2026-10-29'),
(4, 17, 40, 150.00, 6000.00, 'BATCH-20261025-D3', '2026-10-28'),
(5, 2, 35, 750.00, 26250.00, 'BATCH-20261025-E1', '2026-10-27'),
(5, 7, 30, 520.00, 15600.00, 'BATCH-20261025-E2', '2026-10-28'),
(5, 18, 35, 204.29, 7150.00, 'BATCH-20261025-E3', '2026-11-05'),
(6, 11, 25, 1400.00, 35000.00, 'BATCH-20261026-F1', '2026-10-29'),
(6, 12, 30, 550.00, 16500.00, 'BATCH-20261026-F2', '2026-10-29'),
(6, 15, 25, 440.00, 11000.00, 'BATCH-20261026-F3', '2026-10-28'),
(7, 8, 30, 825.00, 24750.00, 'BATCH-20261026-G1', '2026-10-29'),
(7, 9, 25, 480.00, 12000.00, 'BATCH-20261026-G2', '2026-10-30'),
(7, 14, 20, 750.00, 15000.00, 'BATCH-20261026-G3', '2026-10-30'),
(8, 16, 40, 300.00, 12000.00, 'BATCH-20261027-H1', '2026-10-29'),
(8, 19, 35, 300.00, 10500.00, 'BATCH-20261027-H2', '2026-11-03'),
(8, 18, 30, 200.00, 6000.00, 'BATCH-20261027-H3', '2026-11-06');

-- 10. Seed Coupons (6 Promotional Codes)
INSERT INTO `coupons` (`id`, `code`, `discount_type`, `discount_value`, `min_order_amount`, `max_discount`, `usage_limit`, `times_used`, `expires_at`, `is_active`) VALUES
(1, 'FIRSTORDER', 'percentage', 15.00, 1000.00, 500.00, 500, 34, '2026-12-31 23:59:59', 1),
(2, 'KOTTU10', 'percentage', 10.00, 1500.00, 400.00, 200, 18, '2026-11-30 23:59:59', 1),
(3, 'FLAT300', 'fixed', 300.00, 2000.00, NULL, 150, 12, '2026-11-15 23:59:59', 1),
(4, 'WEEKEND20', 'percentage', 20.00, 2500.00, 1000.00, 100, 9, '2026-12-15 23:59:59', 1),
(5, 'SPICY500', 'fixed', 500.00, 3500.00, NULL, 50, 6, '2026-11-20 23:59:59', 1),
(6, 'EXPIRED50', 'percentage', 50.00, 500.00, 500.00, 10, 10, '2026-09-01 00:00:00', 0);

-- 11. Seed Orders (22 Orders)
INSERT INTO `orders` (`id`, `order_number`, `user_id`, `order_type`, `delivery_address`, `contact_phone`, `notes`, `subtotal`, `discount_amount`, `coupon_id`, `delivery_fee`, `total_amount`, `status`, `created_at`) VALUES
(1, 'UE-20261025-001', 7, 'delivery', '12/4 Flower Road, Colombo 07', '+94776789012', 'Please make the chicken kottu extra spicy.', 3050.00, 457.50, 1, 300.00, 2892.50, 'Completed', '2026-10-25 11:20:00'),
(2, 'UE-20261025-002', 8, 'delivery', '55 Havelock Road, Colombo 05', '+94717890123', 'Ring bell upon arrival.', 3200.00, 0.00, NULL, 300.00, 3500.00, 'Completed', '2026-10-25 12:50:00'),
(3, 'UE-20261026-003', 10, 'pickup', NULL, '+94769012345', 'Will pick up in 20 minutes.', 3800.00, 380.00, 2, 0.00, 3420.00, 'Completed', '2026-10-26 10:30:00'),
(4, 'UE-20261026-004', 11, 'delivery', '101 Kynsey Road, Colombo 08', '+94750123456', 'Leave at security gate.', 4200.00, 0.00, NULL, 300.00, 4500.00, 'Completed', '2026-10-26 11:15:00'),
(5, 'UE-20261026-005', 14, 'delivery', '77 Ward Place, Colombo 07', '+94773456789', 'Less gravy on rice please.', 1900.00, 0.00, NULL, 300.00, 2200.00, 'Completed', '2026-10-26 14:20:00'),
(6, 'UE-20261027-006', 9, 'delivery', '89 Duplication Road, Colombo 04', '+94728901234', 'Contactless delivery preferred.', 1700.00, 0.00, NULL, 300.00, 2000.00, 'Preparing', '2026-10-27 09:00:00'),
(7, 'UE-20261027-007', 12, 'delivery', '215 High Level Road, Nugegoda', '+94781234567', 'Include extra papadum.', 2600.00, 300.00, 3, 300.00, 2600.00, 'Placed', '2026-10-27 09:45:00'),
(8, 'UE-20261027-008', 13, 'pickup', NULL, '+94702345678', 'Ready by 11:30 AM.', 1600.00, 0.00, NULL, 0.00, 1600.00, 'Ready', '2026-10-27 10:15:00'),
(9, 'UE-20261027-009', 15, 'delivery', '42 Horton Place, Colombo 07', '+94714567890', 'No plastic cutlery needed.', 3100.00, 465.00, 1, 300.00, 2935.00, 'Out for Delivery', '2026-10-27 10:30:00'),
(10, 'UE-20261027-010', 16, 'delivery', '63 Cotta Road, Borella, Colombo 08', '+94765678901', 'Call on arrival.', 2150.00, 0.00, NULL, 300.00, 2450.00, 'Preparing', '2026-10-27 10:45:00'),
(11, 'UE-20261027-011', 17, 'delivery', '14 Stratford Avenue, Colombo 06', '+94756789012', 'Cutlets must be hot.', 2000.00, 0.00, NULL, 300.00, 2300.00, 'Placed', '2026-10-27 11:00:00'),
(12, 'UE-20261027-012', 18, 'pickup', NULL, '+94787890123', 'Office lunch pickup.', 4300.00, 500.00, 5, 0.00, 3800.00, 'Placed', '2026-10-27 11:15:00'),
(13, 'UE-20261027-013', 19, 'delivery', '28 De Fonseka Road, Colombo 05', '+94708901234', 'Mild spice only.', 1800.00, 0.00, NULL, 300.00, 2100.00, 'Preparing', '2026-10-27 11:30:00'),
(14, 'UE-20261027-014', 20, 'delivery', '150 Baseline Road, Colombo 09', '+94779012345', 'Deliver to 3rd floor.', 2900.00, 290.00, 2, 300.00, 2910.00, 'Placed', '2026-10-27 11:45:00'),
(15, 'UE-20261027-015', 21, 'delivery', '33 Queen\'s Road, Colombo 03', '+94710123456', 'Fresh mint sambol extra.', 3450.00, 0.00, NULL, 300.00, 3750.00, 'Placed', '2026-10-27 12:00:00'),
(16, 'UE-20261027-016', 22, 'pickup', NULL, '+94721234567', 'Quick lunch box.', 1500.00, 0.00, NULL, 0.00, 1500.00, 'Collected', '2026-10-27 12:15:00'),
(17, 'UE-20261027-017', 7, 'delivery', '12/4 Flower Road, Colombo 07', '+94776789012', 'Second order today.', 2750.00, 0.00, NULL, 300.00, 3050.00, 'Placed', '2026-10-27 12:30:00'),
(18, 'UE-20261027-018', 8, 'delivery', '55 Havelock Road, Colombo 05', '+94717890123', 'Keep separate bags.', 3600.00, 300.00, 3, 300.00, 3600.00, 'Placed', '2026-10-27 12:45:00'),
(19, 'UE-20261027-019', 9, 'delivery', '89 Duplication Road, Colombo 04', '+94728901234', 'Dessert spoons please.', 1950.00, 0.00, NULL, 300.00, 2250.00, 'Placed', '2026-10-27 13:00:00'),
(20, 'UE-20261027-020', 10, 'pickup', NULL, '+94769012345', 'Direct counter pickup.', 1450.00, 0.00, NULL, 0.00, 1450.00, 'Ready', '2026-10-27 13:15:00'),
(21, 'UE-20261027-021', 11, 'delivery', '101 Kynsey Road, Colombo 08', '+94750123456', 'Customer requested cancellation.', 2400.00, 0.00, NULL, 300.00, 2700.00, 'Cancelled', '2026-10-27 13:30:00'),
(22, 'UE-20261027-022', 12, 'delivery', '215 High Level Road, Nugegoda', '+94781234567', 'Evening dinner order.', 3100.00, 310.00, 2, 300.00, 3090.00, 'Placed', '2026-10-27 13:45:00');

-- 12. Seed Order Items (Snapshot unit_price at time of purchase)
INSERT INTO `order_items` (`order_id`, `dish_id`, `dish_name`, `unit_price`, `quantity`, `subtotal`) VALUES
(1, 1, 'Traditional Chicken Rice & Curry', 950.00, 2, 1900.00),
(1, 6, 'Classic Shredded Chicken Kottu', 1150.00, 1, 1150.00),
(2, 2, 'Dutch-Burgher Special Lamprais', 1650.00, 1, 1650.00),
(2, 7, 'Urban Roast Chicken Cheese Kottu', 1550.00, 1, 1550.00),
(3, 10, 'Colombo Royal Chicken Dum Biryani', 1450.00, 2, 2900.00),
(3, 18, 'Traditional Spiced Watalappan', 450.00, 2, 900.00),
(4, 3, 'Jaffna Fiery Crab Curry Feast', 2450.00, 1, 2450.00),
(4, 8, 'Hot Butter Cuttlefish Kottu', 1750.00, 1, 1750.00),
(5, 4, 'Southern Black Pork Curry Meal', 1350.00, 1, 1350.00),
(5, 21, 'Royal Colombo Rose Faluda', 550.00, 1, 550.00),
(6, 6, 'Classic Shredded Chicken Kottu', 1150.00, 1, 1150.00),
(6, 21, 'Royal Colombo Rose Faluda', 550.00, 1, 550.00),
(7, 10, 'Colombo Royal Chicken Dum Biryani', 1450.00, 1, 1450.00),
(7, 6, 'Classic Shredded Chicken Kottu', 1150.00, 1, 1150.00),
(8, 1, 'Traditional Chicken Rice & Curry', 950.00, 1, 950.00),
(8, 5, 'Village Pol Roti & Dhal Curry Platter', 650.00, 1, 650.00),
(9, 7, 'Urban Roast Chicken Cheese Kottu', 1550.00, 2, 3100.00),
(10, 11, 'Slow-Cooked Mutton Biryani', 2150.00, 1, 2150.00),
(11, 13, 'Crispy Sri Lankan Fish Cutlets (5 Pcs)', 550.00, 2, 1100.00),
(11, 18, 'Traditional Spiced Watalappan', 450.00, 2, 900.00),
(12, 11, 'Slow-Cooked Mutton Biryani', 2150.00, 2, 4300.00),
(13, 12, 'Garden Vegetable & Cashew Biryani', 1050.00, 1, 1050.00),
(13, 16, 'Egg Hopper & Seeni Sambol Set (4 Pcs)', 550.00, 1, 550.00),
(13, 22, 'Fresh King Coconut Water', 350.00, 1, 350.00),
(14, 10, 'Colombo Royal Chicken Dum Biryani', 1450.00, 2, 2900.00),
(15, 8, 'Hot Butter Cuttlefish Kottu', 1750.00, 1, 1750.00),
(15, 14, 'Hot Butter Cuttlefish Bites', 1450.00, 1, 1450.00),
(15, 22, 'Fresh King Coconut Water', 350.00, 1, 350.00),
(16, 1, 'Traditional Chicken Rice & Curry', 950.00, 1, 950.00),
(16, 21, 'Royal Colombo Rose Faluda', 550.00, 1, 550.00),
(17, 7, 'Urban Roast Chicken Cheese Kottu', 1550.00, 1, 1550.00),
(17, 17, 'Devilled Chicken Wings', 1200.00, 1, 1200.00),
(18, 3, 'Jaffna Fiery Crab Curry Feast', 2450.00, 1, 2450.00),
(18, 6, 'Classic Shredded Chicken Kottu', 1150.00, 1, 1150.00),
(19, 10, 'Colombo Royal Chicken Dum Biryani', 1450.00, 1, 1450.00),
(19, 19, 'Buffalo Curd with Pure Kitul Treacle', 500.00, 1, 500.00),
(20, 10, 'Colombo Royal Chicken Dum Biryani', 1450.00, 1, 1450.00),
(21, 4, 'Southern Black Pork Curry Meal', 1350.00, 1, 1350.00),
(21, 12, 'Garden Vegetable & Cashew Biryani', 1050.00, 1, 1050.00),
(22, 7, 'Urban Roast Chicken Cheese Kottu', 1550.00, 2, 3100.00);

-- 13. Seed Payments (22 Records matching Orders)
INSERT INTO `payments` (`order_id`, `payment_method`, `payment_status`, `transaction_reference`, `amount`, `paid_at`, `created_at`) VALUES
(1, 'card', 'completed', 'TXN-CARD-987214', 2892.50, '2026-10-25 11:21:00', '2026-10-25 11:20:00'),
(2, 'cash_on_delivery', 'completed', 'COD-20261025-002', 3500.00, '2026-10-25 13:45:00', '2026-10-25 12:50:00'),
(3, 'bank_transfer', 'completed', 'BFT-HNB-55421', 3420.00, '2026-10-26 10:35:00', '2026-10-26 10:30:00'),
(4, 'card', 'completed', 'TXN-CARD-112344', 4500.00, '2026-10-26 11:16:00', '2026-10-26 11:15:00'),
(5, 'cash_on_delivery', 'completed', 'COD-20261026-005', 2200.00, '2026-10-26 15:10:00', '2026-10-26 14:20:00'),
(6, 'card', 'completed', 'TXN-CARD-665432', 2000.00, '2026-10-27 09:01:00', '2026-10-27 09:00:00'),
(7, 'cash_on_delivery', 'pending', NULL, 2600.00, NULL, '2026-10-27 09:45:00'),
(8, 'card', 'completed', 'TXN-CARD-776543', 1600.00, '2026-10-27 10:16:00', '2026-10-27 10:15:00'),
(9, 'card', 'completed', 'TXN-CARD-887654', 2935.00, '2026-10-27 10:31:00', '2026-10-27 10:30:00'),
(10, 'bank_transfer', 'completed', 'BFT-COMB-99881', 2450.00, '2026-10-27 10:50:00', '2026-10-27 10:45:00'),
(11, 'cash_on_delivery', 'pending', NULL, 2300.00, NULL, '2026-10-27 11:00:00'),
(12, 'card', 'completed', 'TXN-CARD-223344', 3800.00, '2026-10-27 11:16:00', '2026-10-27 11:15:00'),
(13, 'cash_on_delivery', 'pending', NULL, 2100.00, NULL, '2026-10-27 11:30:00'),
(14, 'card', 'completed', 'TXN-CARD-554433', 2910.00, '2026-10-27 11:46:00', '2026-10-27 11:45:00'),
(15, 'bank_transfer', 'completed', 'BFT-SAMP-12345', 3750.00, '2026-10-27 12:05:00', '2026-10-27 12:00:00'),
(16, 'cash_on_delivery', 'completed', 'COD-20261027-016', 1500.00, '2026-10-27 12:20:00', '2026-10-27 12:15:00'),
(17, 'card', 'completed', 'TXN-CARD-991122', 3050.00, '2026-10-27 12:31:00', '2026-10-27 12:30:00'),
(18, 'cash_on_delivery', 'pending', NULL, 3600.00, NULL, '2026-10-27 12:45:00'),
(19, 'card', 'completed', 'TXN-CARD-445566', 2250.00, '2026-10-27 13:01:00', '2026-10-27 13:00:00'),
(20, 'cash_on_delivery', 'pending', NULL, 1450.00, NULL, '2026-10-27 13:15:00'),
(21, 'card', 'refunded', 'REF-CARD-778899', 2700.00, NULL, '2026-10-27 13:30:00'),
(22, 'card', 'completed', 'TXN-CARD-102938', 3090.00, '2026-10-27 13:46:00', '2026-10-27 13:45:00');

-- 14. Seed Order Status History (Workflow Progression Audit)
INSERT INTO `order_status_history` (`order_id`, `changed_by_user_id`, `old_status`, `new_status`, `comment`, `created_at`) VALUES
-- Order 1 progression
(1, 7, NULL, 'Placed', 'Order submitted by customer', '2026-10-25 11:20:00'),
(1, 3, 'Placed', 'Preparing', 'Kitchen staff commenced food prep', '2026-10-25 11:25:00'),
(1, 3, 'Preparing', 'Ready', 'Order packed and tagged', '2026-10-25 11:45:00'),
(1, 4, 'Ready', 'Out for Delivery', 'Handed to delivery driver', '2026-10-25 11:55:00'),
(1, 4, 'Out for Delivery', 'Completed', 'Driver confirmed delivery to customer', '2026-10-25 12:25:00'),
-- Order 2 progression
(2, 8, NULL, 'Placed', 'Order submitted by customer', '2026-10-25 12:50:00'),
(2, 3, 'Placed', 'Preparing', 'Kottu griddle station active', '2026-10-25 12:55:00'),
(2, 3, 'Preparing', 'Ready', 'Food packed warm in thermal bag', '2026-10-25 13:15:00'),
(2, 4, 'Ready', 'Out for Delivery', 'Driver en route to Havelock Road', '2026-10-25 13:20:00'),
(2, 4, 'Out for Delivery', 'Completed', 'Cash on delivery collected', '2026-10-25 13:45:00'),
-- Order 3 progression
(3, 10, NULL, 'Placed', 'Pickup order placed', '2026-10-26 10:30:00'),
(3, 4, 'Placed', 'Preparing', 'Dum biryani portioned', '2026-10-26 10:35:00'),
(3, 4, 'Preparing', 'Ready', 'Ready at kitchen dispatch counter', '2026-10-26 10:50:00'),
(3, 3, 'Ready', 'Collected', 'Customer collected package', '2026-10-26 11:05:00'),
(3, 3, 'Collected', 'Completed', 'Order marked completed', '2026-10-26 11:06:00'),
-- Order 6 progression (Active)
(6, 9, NULL, 'Placed', 'Order placed by customer', '2026-10-27 09:00:00'),
(6, 3, 'Placed', 'Preparing', 'Chef started preparing Chicken Kottu', '2026-10-27 09:10:00'),
-- Order 8 progression (Ready for pickup)
(8, 13, NULL, 'Placed', 'Customer placed pickup order', '2026-10-27 10:15:00'),
(8, 4, 'Placed', 'Preparing', 'Cooking rice and curries', '2026-10-27 10:20:00'),
(8, 4, 'Preparing', 'Ready', 'Order boxed and on pickup shelf', '2026-10-27 10:40:00'),
-- Order 9 progression (Out for delivery)
(9, 15, NULL, 'Placed', 'Delivery order submitted', '2026-10-27 10:30:00'),
(9, 3, 'Placed', 'Preparing', 'Cooking cheese kottu', '2026-10-27 10:35:00'),
(9, 3, 'Preparing', 'Ready', 'Sealed with tamper proof tape', '2026-10-27 10:55:00'),
(9, 4, 'Ready', 'Out for Delivery', 'Dispatched with rider #3', '2026-10-27 11:05:00'),
-- Order 10 progression (Preparing)
(10, 16, NULL, 'Placed', 'Order submitted', '2026-10-27 10:45:00'),
(10, 3, 'Placed', 'Preparing', 'Mutton biryani warming in clay pot', '2026-10-27 10:50:00'),
-- Order 16 progression (Collected)
(16, 22, NULL, 'Placed', 'Customer pickup placed', '2026-10-27 12:15:00'),
(16, 4, 'Placed', 'Preparing', 'Prep started', '2026-10-27 12:16:00'),
(16, 4, 'Preparing', 'Ready', 'Order ready', '2026-10-27 12:25:00'),
(16, 4, 'Ready', 'Collected', 'Customer received takeaway', '2026-10-27 12:35:00'),
-- Order 21 progression (Cancelled)
(21, 11, NULL, 'Placed', 'Order submitted', '2026-10-27 13:30:00'),
(21, 1, 'Placed', 'Cancelled', 'Customer phoned to cancel; card payment refunded', '2026-10-27 13:35:00');

-- Backfill missing transitions so every seeded order has a complete timeline.
INSERT INTO `order_status_history`
  (`order_id`, `changed_by_user_id`, `old_status`, `new_status`, `comment`, `created_at`)
SELECT o.id,
       CASE WHEN s.step_no = 1 THEN o.user_id ELSE 3 END,
       s.old_status,
       s.new_status,
       CASE
         WHEN s.new_status = 'Placed' THEN 'Order placed by customer'
         WHEN s.new_status = 'Cancelled' THEN 'Order cancelled before completion'
         ELSE CONCAT('Order advanced to ', s.new_status)
       END,
       DATE_ADD(o.created_at, INTERVAL ((s.step_no - 1) * 5) MINUTE)
FROM `orders` o
JOIN (
  SELECT 'delivery' AS order_type, 1 AS step_no, NULL AS old_status, 'Placed' AS new_status
  UNION ALL SELECT 'delivery', 2, 'Placed', 'Preparing'
  UNION ALL SELECT 'delivery', 3, 'Preparing', 'Ready'
  UNION ALL SELECT 'delivery', 4, 'Ready', 'Out for Delivery'
  UNION ALL SELECT 'delivery', 5, 'Out for Delivery', 'Completed'
  UNION ALL SELECT 'delivery', 2, 'Placed', 'Cancelled'
  UNION ALL SELECT 'pickup', 1, NULL, 'Placed'
  UNION ALL SELECT 'pickup', 2, 'Placed', 'Preparing'
  UNION ALL SELECT 'pickup', 3, 'Preparing', 'Ready'
  UNION ALL SELECT 'pickup', 4, 'Ready', 'Collected'
  UNION ALL SELECT 'pickup', 5, 'Collected', 'Completed'
  UNION ALL SELECT 'pickup', 2, 'Placed', 'Cancelled'
) AS s ON s.order_type = o.order_type
WHERE (
    (o.status = 'Cancelled' AND s.new_status IN ('Placed', 'Cancelled'))
    OR (
      o.status <> 'Cancelled'
      AND s.new_status <> 'Cancelled'
      AND s.step_no <= CASE o.status
        WHEN 'Placed' THEN 1
        WHEN 'Preparing' THEN 2
        WHEN 'Ready' THEN 3
        WHEN 'Out for Delivery' THEN 4
        WHEN 'Collected' THEN 4
        WHEN 'Completed' THEN 5
        ELSE 0
      END
    )
  )
  AND NOT EXISTS (
    SELECT 1
    FROM `order_status_history` existing
    WHERE existing.order_id = o.id
      AND existing.new_status = s.new_status
  );

-- End of schema and seed script
