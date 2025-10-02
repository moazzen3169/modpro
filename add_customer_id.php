<?php
include 'env/db.php';

try {
    $sql = "ALTER TABLE Returns ADD COLUMN customer_id INT DEFAULT 0";
    if ($conn->query($sql) === TRUE) {
        echo "ستون customer_id با موفقیت اضافه شد.";
    } else {
        echo "خطا در اضافه کردن ستون: " . $conn->error;
    }
} catch (Exception $e) {
    echo "خطا: " . $e->getMessage();
}

$conn->close();
?>
