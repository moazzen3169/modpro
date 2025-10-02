-- Database Schema for SuitStore Manager Pro
-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS suit_store;
USE suit_store;

-- Drop tables if they exist (in reverse order due to foreign keys)
DROP TABLE IF EXISTS Sale_Items;
DROP TABLE IF EXISTS Sales;
DROP TABLE IF EXISTS Product_Variants;
DROP TABLE IF EXISTS Products;
DROP TABLE IF EXISTS Customers;

-- Customers table
CREATE TABLE Customers (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    email VARCHAR(255),
    address TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products table
CREATE TABLE Products (
    product_id INT AUTO_INCREMENT PRIMARY KEY,
    model_name VARCHAR(255) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Product Variants table
CREATE TABLE Product_Variants (
    variant_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    color VARCHAR(50),
    size VARCHAR(20),
    stock INT DEFAULT 0,
    price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (product_id) REFERENCES Products(product_id) ON DELETE CASCADE
);

-- Sales table
CREATE TABLE Sales (
    sale_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT 0, -- 0 for walk-in customers
    sale_date DATE NOT NULL,
    payment_method ENUM('cash', 'credit_card', 'bank_transfer') NOT NULL,
    status ENUM('pending', 'paid') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Sale Items table
CREATE TABLE Sale_Items (
    sale_item_id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    variant_id INT NOT NULL,
    quantity INT NOT NULL,
    sell_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES Sales(sale_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES Product_Variants(variant_id) ON DELETE CASCADE
);

-- Insert some sample data
INSERT INTO Customers (name, phone, email) VALUES
('مشتری نمونه ۱', '09123456789', 'customer1@example.com'),
('مشتری نمونه ۲', '09123456788', 'customer2@example.com');

INSERT INTO Products (model_name, description, category) VALUES
('کت مردانه کلاسیک', 'کت رسمی برای مناسبت‌های خاص', 'کت'),
('شلوار رسمی', 'شلوار جین برای استفاده روزمره', 'شلوار'),
('پیراهن سفید', 'پیراهن سفید ساده', 'پیراهن');

INSERT INTO Product_Variants (product_id, color, size, stock, price) VALUES
(1, 'مشکی', 'L', 10, 150000),
(1, 'مشکی', 'XL', 8, 150000),
(1, 'navy', 'L', 5, 160000),
(2, 'مشکی', '32', 15, 80000),
(2, 'مشکی', '34', 12, 80000),
(3, 'سفید', 'M', 20, 50000),
(3, 'سفید', 'L', 18, 50000);
