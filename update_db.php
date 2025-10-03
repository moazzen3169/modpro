<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('این اسکریپت فقط از طریق خط فرمان قابل اجرا است.');
}

include 'env/db.php';

// SQL to create Returns table
$returns_sql = "CREATE TABLE IF NOT EXISTS Returns (
    return_id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT NOT NULL,
    supplier_id INT NOT NULL,
    return_date DATE NOT NULL,
    reason TEXT,
    total_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (purchase_id) REFERENCES Purchases(purchase_id) ON DELETE CASCADE,
    FOREIGN KEY (supplier_id) REFERENCES Suppliers(supplier_id) ON DELETE CASCADE
)";

// SQL to create Return_Items table
$return_items_sql = "CREATE TABLE IF NOT EXISTS Return_Items (
    return_item_id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT NOT NULL,
    purchase_item_id INT NOT NULL,
    variant_id INT NOT NULL,
    quantity INT NOT NULL,
    return_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (return_id) REFERENCES Returns(return_id) ON DELETE CASCADE,
    FOREIGN KEY (purchase_item_id) REFERENCES Purchase_Items(purchase_item_id) ON DELETE CASCADE,
    FOREIGN KEY (variant_id) REFERENCES Product_Variants(variant_id) ON DELETE CASCADE
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

    echo "به‌روزرسانی دیتابیس کامل شد!";
} catch (Exception $e) {
    echo "خطا: " . $e->getMessage();
}

$conn->close();
?>
