<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_GET['variant_id']) || !is_numeric($_GET['variant_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid variant_id']);
    exit;
}

$variantId = (int) $_GET['variant_id'];

try {
    $stmt = $conn->prepare("SELECT price FROM Product_Variants WHERE variant_id = ?");
    $stmt->bind_param('i', $variantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($row) {
        $price = (float) $row['price'];
        echo json_encode([
            'price' => $price,
            'buy_price' => $price,
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Variant not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error fetching price']);
}
?>
