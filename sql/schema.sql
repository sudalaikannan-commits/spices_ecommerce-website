-- ============================================================
-- Samand Co. — Ecommerce Database Schema (MySQL / MariaDB)
-- Run through install.php (recommended) or:
--   mysql -u root -p < sql/schema.sql
-- ============================================================

CREATE DATABASE IF NOT EXISTS `samand_co`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `samand_co`;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `order_status_history`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `coupons`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `settings`;
SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- ADMINS
-- ------------------------------------------------------------
CREATE TABLE `admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role`          ENUM('superadmin','staff') NOT NULL DEFAULT 'staff',
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- CUSTOMERS
-- ------------------------------------------------------------
CREATE TABLE `customers` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `email`         VARCHAR(190) NOT NULL,
  `phone`         VARCHAR(40) NULL DEFAULT NULL,
  `password_hash` VARCHAR(255) NULL DEFAULT NULL,
  `address`       TEXT NULL,
  `city`          VARCHAR(80) NULL DEFAULT NULL,
  `state`         VARCHAR(80) NULL DEFAULT NULL,
  `pincode`       VARCHAR(20) NULL DEFAULT NULL,
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `total_orders`  INT UNSIGNED NOT NULL DEFAULT 0,
  `total_spent`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customer_email` (`email`),
  KEY `ix_customer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE `categories` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120) NOT NULL,
  `slug`          VARCHAR(140) NOT NULL,
  `description`   TEXT NULL,
  `image_url`     VARCHAR(255) NULL DEFAULT NULL,
  `display_order` INT NOT NULL DEFAULT 0,
  `status`        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category_slug` (`slug`),
  KEY `ix_category_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PRODUCTS
-- ------------------------------------------------------------
CREATE TABLE `products` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `category_id`       INT UNSIGNED NULL DEFAULT NULL,
  `name`              VARCHAR(160) NOT NULL,
  `slug`              VARCHAR(180) NOT NULL,
  `sku`               VARCHAR(60) NOT NULL,
  `short_description` VARCHAR(255) NULL DEFAULT NULL,
  `description`       TEXT NULL,
  `ingredients`       TEXT NULL,
  `usage`             TEXT NULL,
  `price`             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sale_price`        DECIMAL(10,2) NULL DEFAULT NULL,
  `image_url`         VARCHAR(255) NULL DEFAULT NULL,
  `pack_sizes`        TEXT NULL DEFAULT NULL,
  `status`            ENUM('active','draft','archived') NOT NULL DEFAULT 'active',
  `is_featured`       TINYINT(1) NOT NULL DEFAULT 0,
  `rating_avg`        DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  `rating_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_slug` (`slug`),
  UNIQUE KEY `uq_product_sku` (`sku`),
  KEY `ix_product_category` (`category_id`),
  KEY `ix_product_status` (`status`),
  CONSTRAINT `fk_product_category` FOREIGN KEY (`category_id`)
    REFERENCES `categories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- INVENTORY (1:1 with products — stock lives here, decrements on order)
-- ------------------------------------------------------------
CREATE TABLE `inventory` (
  `product_id`   INT UNSIGNED NOT NULL,
  `stock`        INT NOT NULL DEFAULT 0,
  `reorder_level` INT NOT NULL DEFAULT 5,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`),
  CONSTRAINT `fk_inventory_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- COUPONS
-- ------------------------------------------------------------
CREATE TABLE `coupons` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(40) NOT NULL,
  `type`          ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value`         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `min_order`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_discount`  DECIMAL(10,2) NULL DEFAULT NULL,
  `usage_limit`   INT UNSIGNED NULL DEFAULT NULL,
  `used_count`    INT UNSIGNED NOT NULL DEFAULT 0,
  `starts_at`     DATE NULL DEFAULT NULL,
  `expires_at`    DATE NULL DEFAULT NULL,
  `status`        ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`code`),
  KEY `ix_coupon_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ORDERS
-- ------------------------------------------------------------
CREATE TABLE `orders` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_code`     VARCHAR(30) NOT NULL,
  `customer_id`    INT UNSIGNED NULL DEFAULT NULL,
  `customer_name`  VARCHAR(120) NOT NULL,
  `email`          VARCHAR(190) NOT NULL,
  `phone`          VARCHAR(40) NULL DEFAULT NULL,
  `address`        TEXT NOT NULL,
  `city`           VARCHAR(80) NULL DEFAULT NULL,
  `state`          VARCHAR(80) NULL DEFAULT NULL,
  `pincode`        VARCHAR(20) NULL DEFAULT NULL,
  `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `shipping`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `coupon_id`      INT UNSIGNED NULL DEFAULT NULL,
  `coupon_code`    VARCHAR(40) NULL DEFAULT NULL,
  `payment_method` VARCHAR(40) NOT NULL DEFAULT 'COD',
  `payment_status` ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `status`         ENUM('pending','confirmed','packed','shipped','delivered','cancelled','returned')
                   NOT NULL DEFAULT 'pending',
  `notes`          TEXT NULL,
  `placed_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_order_code` (`order_code`),
  KEY `ix_order_status` (`status`),
  KEY `ix_order_customer` (`customer_id`),
  KEY `ix_order_placed` (`placed_at`),
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_order_coupon` FOREIGN KEY (`coupon_id`)
    REFERENCES `coupons` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ORDER ITEMS
-- ------------------------------------------------------------
CREATE TABLE `order_items` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`     INT UNSIGNED NOT NULL,
  `product_id`   INT UNSIGNED NULL DEFAULT NULL,
  `product_name` VARCHAR(160) NOT NULL,
  `sku`          VARCHAR(60) NULL DEFAULT NULL,
  `unit_price`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `quantity`     INT UNSIGNED NOT NULL DEFAULT 1,
  `line_total`   DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `ix_item_order` (`order_id`),
  KEY `ix_item_product` (`product_id`),
  CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_item_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ORDER STATUS HISTORY
-- ------------------------------------------------------------
CREATE TABLE `order_status_history` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `status`     VARCHAR(30) NOT NULL,
  `note`       VARCHAR(255) NULL DEFAULT NULL,
  `changed_by` VARCHAR(120) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_hist_order` (`order_id`),
  CONSTRAINT `fk_hist_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- REVIEWS
-- ------------------------------------------------------------
CREATE TABLE `reviews` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id`    INT UNSIGNED NOT NULL,
  `customer_id`   INT UNSIGNED NULL DEFAULT NULL,
  `customer_name` VARCHAR(120) NOT NULL,
  `rating`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
  `title`         VARCHAR(160) NULL DEFAULT NULL,
  `comment`       TEXT NULL,
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_review_product` (`product_id`),
  KEY `ix_review_status` (`status`),
  CONSTRAINT `fk_review_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_review_customer` FOREIGN KEY (`customer_id`)
    REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- PAYMENTS
-- ------------------------------------------------------------
CREATE TABLE `payments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_id`   INT UNSIGNED NOT NULL,
  `method`     VARCHAR(40) NOT NULL DEFAULT 'COD',
  `gateway`    VARCHAR(40) NULL DEFAULT NULL,
  `txn_ref`    VARCHAR(120) NULL DEFAULT NULL,
  `amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status`     ENUM('pending','paid','failed','refunded') NOT NULL DEFAULT 'pending',
  `paid_at`    DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_payment_order` (`order_id`),
  KEY `ix_payment_status` (`status`),
  CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`)
    REFERENCES `orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SETTINGS (key/value store)
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `setting_key`   VARCHAR(80) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(40) NOT NULL DEFAULT 'general',
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- (admin account is created by install.php so the password can be hashed)
-- ============================================================

INSERT INTO `categories` (`name`,`slug`,`description`,`image_url`,`display_order`,`status`) VALUES
('Whole Spices','whole-spices','Whole spices in their natural form.','https://thumb.wikimedia.org/wikipedia/commons/thumb/8/82/Green_Cardamom_Pods.jpg/960px-Green_Cardamom_Pods.jpg',1,'active'),
('Ground Spices','ground-spices','Finely ground spices for everyday cooking.','https://thumb.wikimedia.org/wikipedia/commons/thumb/3/3e/Turmeric_Powder_Spelled_Out.jpg/960px-Turmeric_Powder_Spelled_Out.jpg',2,'active'),
('Masala Blends','masala-blends','Ready blends for authentic Indian flavour.','https://thumb.wikimedia.org/wikipedia/commons/thumb/2/23/Garam_Masala_Powder_by_Chlorofeel_Groups.jpg/960px-Garam_Masala_Powder_by_Chlorofeel_Groups.jpg',3,'active'),
('Herbs & Leaves','herbs-leaves','Dried herbs, leaves and garnishes.','https://thumb.wikimedia.org/wikipedia/commons/thumb/8/86/Coriander_Seeds.jpg/960px-Coriander_Seeds.jpg',4,'active');

INSERT INTO `products`
(`category_id`,`name`,`slug`,`sku`,`short_description`,`description`,`ingredients`,`usage`,`price`,`sale_price`,`image_url`,`pack_sizes`,`status`,`is_featured`,`rating_avg`,`rating_count`) VALUES
((SELECT id FROM `categories` WHERE `slug`='ground-spices'),'Turmeric Powder','turmeric-powder','SC-TUR-500','Bright, aromatic turmeric powder made for everyday cooking.','Bright, aromatic turmeric powder made for everyday cooking.','100% turmeric','Curries, rice, marinades and drinks.',249.00,199.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/3/3e/Turmeric_Powder_Spelled_Out.jpg/960px-Turmeric_Powder_Spelled_Out.jpg','["100g","500g","1kg"]','active',1,4.80,124),
((SELECT id FROM `categories` WHERE `slug`='ground-spices'),'Red Chilli Powder','red-chilli-powder','SC-CHI-500','Bold colour and balanced heat for authentic Indian dishes.','Bold colour and balanced heat for authentic Indian dishes.','100% red chilli','Curries, chutneys and spice mixes.',239.00,189.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/d/d4/Red_Chili_Powder_%28Lall_Mirch%29_%2849695826571%29.jpg/960px-Red_Chili_Powder_%28Lall_Mirch%29_%2849695826571%29.jpg','["100g","500g","1kg"]','active',1,4.70,98),
((SELECT id FROM `categories` WHERE `slug`='ground-spices'),'Coriander Powder','coriander-powder','SC-COR-500','Fresh, citrusy coriander powder with a warm aroma.','Fresh, citrusy coriander powder with a warm aroma.','100% coriander','Curries, gravies and masalas.',219.00,179.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/8/86/Coriander_Seeds.jpg/960px-Coriander_Seeds.jpg','["100g","500g","1kg"]','active',0,4.80,76),
((SELECT id FROM `categories` WHERE `slug`='masala-blends'),'Garam Masala','garam-masala','SC-GAR-200','A fragrant blend designed to finish Indian dishes beautifully.','A fragrant blend designed to finish Indian dishes beautifully.','Coriander, cumin, pepper, cinnamon and more.','Add near the end of cooking.',299.00,249.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/2/23/Garam_Masala_Powder_by_Chlorofeel_Groups.jpg/960px-Garam_Masala_Powder_by_Chlorofeel_Groups.jpg','["100g","200g","500g"]','active',1,4.90,141),
((SELECT id FROM `categories` WHERE `slug`='whole-spices'),'Black Pepper','black-pepper','SC-PEP-200','Aromatic whole black pepper with a warm, sharp finish.','Aromatic whole black pepper with a warm, sharp finish.','100% black pepper','Grind fresh for best aroma.',349.00,299.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/8/81/Tellicherry_Black_Peppercorns.jpg/960px-Tellicherry_Black_Peppercorns.jpg','["100g","200g","500g"]','active',0,4.80,88),
((SELECT id FROM `categories` WHERE `slug`='whole-spices'),'Cardamom','cardamom','SC-CAR-100','Fragrant green cardamom for sweets, tea and savoury dishes.','Fragrant green cardamom for sweets, tea and savoury dishes.','100% green cardamom','Crush pods or use whole.',399.00,349.00,'https://thumb.wikimedia.org/wikipedia/commons/thumb/8/82/Green_Cardamom_Pods.jpg/960px-Green_Cardamom_Pods.jpg','["50g","100g","250g"]','active',1,4.90,167);

INSERT INTO `inventory` (`product_id`,`stock`,`reorder_level`)
SELECT `id`, 40, 8 FROM `products`;

UPDATE `inventory` SET `stock` = 4 WHERE `product_id` = (SELECT `id` FROM `products` WHERE `sku`='SC-CAR-100');
UPDATE `inventory` SET `stock` = 0 WHERE `product_id` = (SELECT `id` FROM `products` WHERE `sku`='SC-PEP-200');

INSERT INTO `coupons` (`code`,`type`,`value`,`min_order`,`max_discount`,`usage_limit`,`starts_at`,`expires_at`,`status`) VALUES
('WELCOME10','percent',10.00,499.00,150.00,500,'2026-01-01','2027-12-31','active'),
('FLAT100','fixed',100.00,999.00,NULL,200,'2026-01-01','2027-12-31','active'),
('SPICE20','percent',20.00,1999.00,400.00,100,'2026-01-01','2027-06-30','disabled');

INSERT INTO `settings` (`setting_key`,`setting_value`,`setting_group`) VALUES
('store_name','Samand Co.','store'),
('store_tagline','Premium Indian Spices & Masalas','store'),
('store_email','hello@samandco.com','contact'),
('store_phone','+91 98765 43210','contact'),
('store_address','12 Spice Market Road, Mumbai, Maharashtra 400001, India','contact'),
('currency_symbol','₹','general'),
('shipping_fee','49.00','shipping'),
('free_shipping_above','999.00','shipping'),
('shipping_flat_rate','49.00','shipping'),
('cod_enabled','1','payments'),
('online_payment_enabled','1','payments'),
('low_stock_threshold','8','inventory'),
('tax_percent','0','general'),
('site_status','live','general');