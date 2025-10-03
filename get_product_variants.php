<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

try {
    $stmt = $conn->prepare("
        SELECT
            pv.variant_id,
            p.model_name,
            pv.color,
            pv.size
        FROM Product_Variants pv
        JOIN Products p ON pv.product_id = p.product_id
        ORDER BY p.model_name, pv.color, pv.size
    ");
    $stmt->execute();
    $result = $stmt->get_result();

    $variants = [];
    while ($row = $result->fetch_assoc()) {
        $variants[] = $row;
    }

    echo json_encode($variants);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در بارگذاری محصولات']);
}
?>
