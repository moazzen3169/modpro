-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Oct 07, 2025 at 03:37 PM
-- Server version: 8.2.0
-- PHP Version: 8.2.13

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `suit_store`
--

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

DROP TABLE IF EXISTS `customers`;
CREATE TABLE IF NOT EXISTS `customers` (
  `customer_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`customer_id`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `name`, `phone`, `email`, `address`, `created_at`) VALUES
(1, 'مشتری نمونه ۱', '09123456789', 'customer1@example.com', NULL, '2025-10-03 12:48:13'),
(2, 'مشتری نمونه ۲', '09123456788', 'customer2@example.com', NULL, '2025-10-03 12:48:13');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
CREATE TABLE IF NOT EXISTS `products` (
  `product_id` int NOT NULL AUTO_INCREMENT,
  `model_name` varchar(255) NOT NULL,
  `description` text,
  `category` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`)
) ENGINE=MyISAM AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `model_name`, `description`, `category`, `created_at`) VALUES
(7, 'باطری', NULL, 'کت و شلوار', '2025-10-07 13:47:29');

-- --------------------------------------------------------

--
-- Table structure for table `product_variants`
--

DROP TABLE IF EXISTS `product_variants`;
CREATE TABLE IF NOT EXISTS `product_variants` (
  `variant_id` int NOT NULL AUTO_INCREMENT,
  `product_id` int NOT NULL,
  `color` varchar(50) DEFAULT NULL,
  `size` varchar(20) DEFAULT NULL,
  `stock` int DEFAULT '0',
  `price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`variant_id`),
  KEY `product_id` (`product_id`)
) ENGINE=MyISAM AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `product_variants`
--

INSERT INTO `product_variants` (`variant_id`, `product_id`, `color`, `size`, `stock`, `price`) VALUES
(23, 7, 'مشکی', '42', 1, 1500000.00),
(22, 7, 'مشکی', '40', 0, 1500000.00),
(21, 7, 'مشکی', '38', 0, 1500000.00),
(26, 7, 'مشکی', '48', 1, 1500000.00),
(25, 7, 'مشکی', '46', 1, 1500000.00),
(24, 7, 'مشکی', '44', 1, 1500000.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

DROP TABLE IF EXISTS `purchases`;
CREATE TABLE IF NOT EXISTS `purchases` (
  `purchase_id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `purchase_date` date NOT NULL,
  `payment_method` enum('cash','credit_card','bank_transfer') NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`purchase_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=MyISAM AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`purchase_id`, `supplier_id`, `purchase_date`, `payment_method`, `status`, `created_at`) VALUES
(1, 1, '2025-10-03', 'cash', '', '2025-10-03 15:29:58'),
(2, 1, '2025-10-03', 'cash', '', '2025-10-03 15:30:28'),
(3, 1, '2025-10-03', 'cash', '', '2025-10-03 15:31:13'),
(4, 1, '2025-10-03', 'cash', '', '2025-10-03 15:33:12'),
(5, 1, '2025-10-03', 'cash', '', '2025-10-03 15:33:58'),
(6, 1, '2025-10-03', 'cash', '', '2025-10-03 15:40:54'),
(7, 1, '2025-10-03', 'cash', '', '2025-10-03 15:40:57'),
(8, 1, '2025-10-03', 'cash', '', '2025-10-03 15:41:27'),
(9, 1, '2025-10-03', 'cash', '', '2025-10-03 15:43:13'),
(10, 1, '2025-10-03', 'cash', '', '2025-10-03 15:44:41'),
(11, 1, '2025-10-03', 'cash', '', '2025-10-03 15:54:19'),
(12, 1, '2025-10-03', 'cash', '', '2025-10-03 16:07:51'),
(13, 1, '2025-10-03', 'cash', '', '2025-10-03 16:08:57'),
(14, 1, '2025-10-03', 'cash', '', '2025-10-03 16:09:30'),
(15, 1, '2025-10-04', 'cash', '', '2025-10-03 17:12:21'),
(16, 1, '2025-10-03', 'cash', '', '2025-10-03 17:53:56'),
(17, 1, '2025-10-03', 'cash', '', '2025-10-03 17:55:05'),
(18, 1, '2025-10-07', 'cash', '', '2025-10-07 13:47:29');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

DROP TABLE IF EXISTS `purchase_items`;
CREATE TABLE IF NOT EXISTS `purchase_items` (
  `purchase_item_id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int NOT NULL,
  `variant_id` int NOT NULL,
  `quantity` int NOT NULL,
  `buy_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`purchase_item_id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `variant_id` (`variant_id`)
) ENGINE=MyISAM AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`purchase_item_id`, `purchase_id`, `variant_id`, `quantity`, `buy_price`) VALUES
(1, 4, 8, 1, 1000.00),
(2, 4, 9, 1, 1000.00),
(3, 5, 8, 1, 1000.00),
(4, 5, 9, 1, 1000.00),
(5, 6, 8, 1, 1000.00),
(6, 6, 9, 1, 1000.00),
(7, 7, 8, 1, 1000.00),
(8, 7, 9, 1, 1000.00),
(9, 8, 8, 1, 1000.00),
(10, 8, 9, 1, 1000.00),
(11, 9, 8, 1, 1000.00),
(12, 9, 9, 1, 1000.00),
(13, 10, 8, 1, 1000.00),
(14, 10, 9, 1, 1000.00),
(15, 11, 8, 1, 1000.00),
(16, 11, 9, 1, 1000.00),
(17, 14, 10, 2, 1000.00),
(18, 14, 6, 2, 1000.00),
(19, 14, 11, 2, 1000.00),
(20, 14, 12, 2, 1000.00),
(21, 15, 13, 2, 1000.00),
(22, 15, 14, 1, 1000.00),
(23, 16, 10, 1, 1000.00),
(24, 16, 6, 1, 1000.00),
(25, 16, 7, 1, 1000.00),
(26, 16, 11, 3, 1000.00),
(27, 17, 15, 1, 12000.00),
(28, 17, 16, 1, 12000.00),
(29, 17, 17, 1, 12000.00),
(30, 17, 18, 1, 12000.00),
(31, 17, 19, 1, 12000.00),
(32, 17, 20, 5, 12000.00),
(33, 18, 21, 1, 1500000.00),
(34, 18, 22, 1, 1500000.00),
(35, 18, 23, 1, 1500000.00),
(36, 18, 24, 1, 1500000.00),
(37, 18, 25, 1, 1500000.00),
(38, 18, 26, 1, 1500000.00);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

DROP TABLE IF EXISTS `purchase_returns`;
CREATE TABLE IF NOT EXISTS `purchase_returns` (
  `purchase_return_id` int NOT NULL AUTO_INCREMENT,
  `purchase_id` int DEFAULT NULL,
  `supplier_id` int NOT NULL,
  `return_date` date NOT NULL,
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`purchase_return_id`),
  KEY `purchase_id` (`purchase_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `returns`
--

DROP TABLE IF EXISTS `returns`;
CREATE TABLE IF NOT EXISTS `returns` (
  `return_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT '0',
  `return_date` date NOT NULL,
  `reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`return_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `returns`
--

INSERT INTO `returns` (`return_id`, `customer_id`, `return_date`, `reason`, `created_at`) VALUES
(4, 0, '2025-10-07', '', '2025-10-07 14:51:02');

-- --------------------------------------------------------

--
-- Table structure for table `return_items`
--

DROP TABLE IF EXISTS `return_items`;
CREATE TABLE IF NOT EXISTS `return_items` (
  `return_item_id` int NOT NULL AUTO_INCREMENT,
  `return_id` int NOT NULL,
  `variant_id` int NOT NULL,
  `quantity` int NOT NULL,
  `return_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`return_item_id`),
  KEY `return_id` (`return_id`),
  KEY `variant_id` (`variant_id`)
) ENGINE=MyISAM AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `return_items`
--

INSERT INTO `return_items` (`return_item_id`, `return_id`, `variant_id`, `quantity`, `return_price`) VALUES
(4, 4, 22, 1, 1500000.00);

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

DROP TABLE IF EXISTS `sales`;
CREATE TABLE IF NOT EXISTS `sales` (
  `sale_id` int NOT NULL AUTO_INCREMENT,
  `customer_id` int DEFAULT '0',
  `sale_date` date NOT NULL,
  `payment_method` enum('cash','credit_card','bank_transfer') NOT NULL,
  `status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`sale_id`)
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`sale_id`, `customer_id`, `sale_date`, `payment_method`, `status`, `created_at`) VALUES
(9, 0, '2025-10-07', 'cash', 'paid', '2025-10-07 14:31:42');

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

DROP TABLE IF EXISTS `sale_items`;
CREATE TABLE IF NOT EXISTS `sale_items` (
  `sale_item_id` int NOT NULL AUTO_INCREMENT,
  `sale_id` int NOT NULL,
  `variant_id` int NOT NULL,
  `quantity` int NOT NULL,
  `sell_price` decimal(10,2) NOT NULL,
  PRIMARY KEY (`sale_item_id`),
  KEY `sale_id` (`sale_id`),
  KEY `variant_id` (`variant_id`)
) ENGINE=MyISAM AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`sale_item_id`, `sale_id`, `variant_id`, `quantity`, `sell_price`) VALUES
(12, 9, 21, 1, 2500000.00);

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE IF NOT EXISTS `suppliers` (
  `supplier_id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`supplier_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_balances`
--

DROP TABLE IF EXISTS `supplier_balances`;
CREATE TABLE IF NOT EXISTS `supplier_balances` (
  `balance_id` int NOT NULL AUTO_INCREMENT,
  `supplier_id` int NOT NULL,
  `balance_year` int NOT NULL,
  `balance_month` int NOT NULL,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_purchases` decimal(15,2) NOT NULL DEFAULT '0.00',
  `total_returns` decimal(15,2) NOT NULL DEFAULT '0.00',
  `closing_balance` decimal(15,2) NOT NULL DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`balance_id`),
  UNIQUE KEY `supplier_month` (`supplier_id`,`balance_year`,`balance_month`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
