-- کامل کردن دیتابیس SuitStore Manager Pro
-- این فایل شامل تمام جداول مورد نیاز است

-- جدول محصولات
CREATE TABLE IF NOT EXISTS `Products` (
  `product_id` INT NOT NULL AUTO_INCREMENT,
  `model_name` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `image_path` VARCHAR(500),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول انواع محصول (واریانت‌ها)
CREATE TABLE IF NOT EXISTS `Product_Variants` (
  `variant_id` INT NOT NULL AUTO_INCREMENT,
  `product_id` INT NOT NULL,
  `color` VARCHAR(100) NOT NULL,
  `size` VARCHAR(50) NOT NULL,
  `stock` INT NOT NULL DEFAULT 0,
  `price` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`variant_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`) REFERENCES `Products` (`product_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول مشتریان
CREATE TABLE IF NOT EXISTS `Customers` (
  `customer_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20),
  `address` TEXT,
  PRIMARY KEY (`customer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول تامین‌کنندگان
CREATE TABLE IF NOT EXISTS `suppliers` (
  `supplier_id` INT NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20),
  `address` TEXT,
  PRIMARY KEY (`supplier_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول خریدها
CREATE TABLE IF NOT EXISTS `Purchases` (
  `purchase_id` INT NOT NULL AUTO_INCREMENT,
  `supplier_id` INT NOT NULL,
  `purchase_date` DATE NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note` TEXT,
  PRIMARY KEY (`purchase_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `fk_purchases_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول آیتم‌های خرید
CREATE TABLE IF NOT EXISTS `Purchase_Items` (
  `purchase_item_id` INT NOT NULL AUTO_INCREMENT,
  `purchase_id` INT NOT NULL,
  `variant_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `purchase_price` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`purchase_item_id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `fk_purchase_items_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `Purchases` (`purchase_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `Product_Variants` (`variant_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول فروش‌ها
CREATE TABLE IF NOT EXISTS `Sales` (
  `sale_id` INT NOT NULL AUTO_INCREMENT,
  `customer_id` INT NOT NULL,
  `sale_date` DATE NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note` TEXT,
  PRIMARY KEY (`sale_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `Customers` (`customer_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول آیتم‌های فروش
CREATE TABLE IF NOT EXISTS `Sale_Items` (
  `sale_item_id` INT NOT NULL AUTO_INCREMENT,
  `sale_id` INT NOT NULL,
  `variant_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `sale_price` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `sale_id` (`sale_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `fk_sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES `Sales` (`sale_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sale_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `Product_Variants` (`variant_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول مرجوعی‌های فروش
CREATE TABLE IF NOT EXISTS `Returns` (
  `return_id` INT NOT NULL AUTO_INCREMENT,
  `customer_id` INT DEFAULT NULL,
  `return_date` DATE NOT NULL,
  `reason` TEXT,
  PRIMARY KEY (`return_id`),
  KEY `customer_id` (`customer_id`),
  CONSTRAINT `fk_returns_customer` FOREIGN KEY (`customer_id`) REFERENCES `Customers` (`customer_id`) ON DELETE SET NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول آیتم‌های مرجوعی فروش
CREATE TABLE IF NOT EXISTS `Return_Items` (
  `return_item_id` INT NOT NULL AUTO_INCREMENT,
  `return_id` INT NOT NULL,
  `variant_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `return_price` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`return_item_id`),
  KEY `return_id` (`return_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `Returns` (`return_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `Product_Variants` (`variant_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول مرجوعی‌های خرید
CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `purchase_return_id` INT NOT NULL AUTO_INCREMENT,
  `supplier_id` INT NOT NULL,
  `return_date` DATE NOT NULL,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `note` TEXT,
  PRIMARY KEY (`purchase_return_id`),
  KEY `supplier_id` (`supplier_id`),
  CONSTRAINT `fk_purchase_returns_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- جدول آیتم‌های مرجوعی خرید
CREATE TABLE IF NOT EXISTS `purchase_return_items` (
  `purchase_return_item_id` INT NOT NULL AUTO_INCREMENT,
  `purchase_return_id` INT NOT NULL,
  `variant_id` INT NOT NULL,
  `quantity` INT NOT NULL,
  `return_price` DECIMAL(10,2) NOT NULL,
  PRIMARY KEY (`purchase_return_item_id`),
  KEY `purchase_return_id` (`purchase_return_id`),
  KEY `variant_id` (`variant_id`),
  CONSTRAINT `fk_purchase_return_items_return` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`purchase_return_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_purchase_return_items_variant` FOREIGN KEY (`variant_id`) REFERENCES `Product_Variants` (`variant_id`) ON DELETE CASCADE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- داده‌های نمونه (اختیاری - اگر نیاز دارید uncomment کنید)
-- INSERT INTO `suppliers` (`supplier_id`, `name`, `phone`, `address`) VALUES
-- (1, 'تامین‌کننده نمونه', '09123456789', 'آدرس نمونه');
-- INSERT INTO `Products` (`product_id`, `model_name`, `description`) VALUES
-- (1, 'کت نمونه', 'توضیح نمونه');
-- INSERT INTO `Product_Variants` (`variant_id`, `product_id`, `color`, `size`, `stock`, `price`) VALUES
-- (1, 1, 'مشکی', 'M', 10, 100000.00);
