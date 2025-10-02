<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $product_id = validate_int($_GET['product_id'] ?? null, 1);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => 'شناسه محصول نامعتبر است.']);
    exit();
}

$colorsStmt = $conn->prepare('SELECT DISTINCT color FROM Product_Variants WHERE product_id = ? AND stock > 0 ORDER BY color');
$colorsStmt->bind_param('i', $product_id);
$colorsStmt->execute();
$colorsResult = $colorsStmt->get_result();

$color_list = [];
while ($color = $colorsResult->fetch_assoc()) {
    $color_value = (string) $color['color'];
    $color_list[] = [
        'color' => $color_value,
        'color_name' => $color_value,
    ];
}

$variantsStmt = $conn->prepare('SELECT variant_id, product_id, color, size, stock, price FROM Product_Variants WHERE product_id = ? ORDER BY variant_id');
$variantsStmt->bind_param('i', $product_id);
$variantsStmt->execute();
$variantsResult = $variantsStmt->get_result();

$variant_list = [];
while ($variant = $variantsResult->fetch_assoc()) {
    $variant_list[] = [
        'variant_id' => (int) $variant['variant_id'],
        'product_id' => (int) $variant['product_id'],
        'color' => (string) $variant['color'],
        'size' => (string) $variant['size'],
        'stock' => (int) $variant['stock'],
        'price' => (float) $variant['price'],
    ];
}

echo json_encode([
    'colors' => $color_list,
    'variants' => $variant_list,
], JSON_UNESCAPED_UNICODE);
