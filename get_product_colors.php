<?php
include 'env/db.php';

$product_id = $_GET['product_id'];

// Get distinct colors for this product
$colors = $conn->query("SELECT DISTINCT color FROM Product_Variants WHERE product_id = $product_id AND stock > 0");
$color_list = [];
while($color = $colors->fetch_assoc()){
    $color_list[] = [
        'color' => $color['color'],
        'color_name' => $color['color']
    ];
}

// Get all variants for this product
$variants = $conn->query("SELECT * FROM Product_Variants WHERE product_id = $product_id");
$variant_list = [];
while($variant = $variants->fetch_assoc()){
    $variant_list[] = $variant;
}

echo json_encode([
    'colors' => $color_list,
    'variants' => $variant_list
]);
?>