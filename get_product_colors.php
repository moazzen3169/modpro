<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$colorsStmt = $conn->prepare('SELECT DISTINCT color FROM Product_Variants ORDER BY color');
$colorsStmt->execute();
$colorsResult = $colorsStmt->get_result();

$colors = [];
while ($color = $colorsResult->fetch_assoc()) {
    $colors[] = $color['color'];
}

echo json_encode($colors, JSON_UNESCAPED_UNICODE);
