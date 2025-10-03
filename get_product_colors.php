<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$product_id = $_GET['product_id'] ?? null;

if ($product_id) {
    $colorsStmt = $conn->prepare('SELECT DISTINCT color FROM Product_Variants WHERE product_id = ? ORDER BY color');
    $colorsStmt->bind_param('i', $product_id);
} else {
    $colorsStmt = $conn->prepare('SELECT DISTINCT color FROM Product_Variants ORDER BY color');
}
$colorsStmt->execute();
$colorsResult = $colorsStmt->get_result();

$colors = [];
while ($color = $colorsResult->fetch_assoc()) {
    $colors[] = ['color' => $color['color']];
}

$variantsStmt = $conn->prepare('SELECT * FROM Product_Variants WHERE product_id = ?');
$variantsStmt->bind_param('i', $product_id);
$variantsStmt->execute();
$variantsResult = $variantsStmt->get_result();

$variants = [];
while ($variant = $variantsResult->fetch_assoc()) {
    $variants[] = $variant;
}

echo json_encode(['colors' => $colors, 'variants' => $variants], JSON_UNESCAPED_UNICODE);
