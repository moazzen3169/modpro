<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

try {
    $sql = "
        SELECT
            pv.variant_id,
            p.model_name,
            pv.color,
            pv.size,
            pv.price,
            pv.stock
        FROM Product_Variants pv
        JOIN Products p ON pv.product_id = p.product_id
    ";

    $where = '';
    if (isset($_GET['product_id'])) {
        $product_id = (int) $_GET['product_id'];
        $where = " WHERE p.product_id = ?";
    }

    $sql .= $where . " ORDER BY p.model_name, pv.color, pv.size";

    $stmt = $conn->prepare($sql);
    if ($where) {
        $stmt->bind_param('i', $product_id);
    }
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
