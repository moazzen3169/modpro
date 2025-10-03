<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از طریق خط فرمان قابل اجرا است.');
}

include 'env/db.php';

// SQL to create Returns table
$returns_sql = "CREATE TABLE IF NOT EXISTS Returns (
    return_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT DEFAULT 0,
    return_date DATE NOT NULL,
    reason TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";

// SQL to create Return_Items table
$return_items_sql = "CREATE TABLE IF NOT EXISTS Return_Items (
    return_item_id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    variant_id INT NOT NULL,
    quantity INT NOT NULL,
    return_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (return_id) REFERENCES Returns(return_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES Product_Variants(variant_id) ON DELETE CASCADE
)";

// SQL to create Purchase_Returns table
$purchase_returns_sql = "CREATE TABLE IF NOT EXISTS Purchase_Returns (
    purchase_return_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NULL,
    supplier_id INT NOT NULL,
    return_date DATE NOT NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES Purchases(purchase_id) ON DELETE SET NULL,
    FOREIGN KEY (supplier_id) REFERENCES Suppliers(supplier_id) ON DELETE CASCADE
)";

// SQL to create Supplier_Balances table
$supplier_balances_sql = "CREATE TABLE IF NOT EXISTS Supplier_Balances (
    balance_id INT AUTO_INCREMENT PRIMARY KEY,
    supplier_id INT NOT NULL,
    balance_year INT NOT NULL,
    balance_month INT NOT NULL,
    opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_purchases DECIMAL(15,2) NOT NULL DEFAULT 0,
    total_returns DECIMAL(15,2) NOT NULL DEFAULT 0,
    closing_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY supplier_month (supplier_id, balance_year, balance_month),
    FOREIGN KEY (supplier_id) REFERENCES Suppliers(supplier_id) ON DELETE CASCADE
)";

try {
    // Create Returns table
    if ($conn->query($returns_sql) === TRUE) {
        echo "جدول Returns با موفقیت ایجاد شد<br>";
    } else {
        echo "خطا در ایجاد جدول Returns: " . $conn->error . "<br>";
    }

    // Create Return_Items table
    if ($conn->query($return_items_sql) === TRUE) {
        echo "جدول Return_Items با موفقیت ایجاد شد<br>";
    } else {
        echo "خطا در ایجاد جدول Return_Items: " . $conn->error . "<br>";
    }

    // Create Purchase_Returns table
    if ($conn->query($purchase_returns_sql) === TRUE) {
        echo "جدول Purchase_Returns با موفقیت ایجاد شد<br>";
    } else {
        echo "خطا در ایجاد جدول Purchase_Returns: " . $conn->error . "<br>";
    }

    // Create Supplier_Balances table
    if ($conn->query($supplier_balances_sql) === TRUE) {
        echo "جدول Supplier_Balances با موفقیت ایجاد شد<br>";
    } else {
        echo "خطا در ایجاد جدول Supplier_Balances: " . $conn->error . "<br>";
    }

    echo "به‌روزرسانی دیتابیس کامل شد!";
} catch (Exception $e) {
    echo "خطا: " . $e->getMessage();
}

$conn->close();
?>
