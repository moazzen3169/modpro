<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

$purchase_date = $_GET['purchase_date'] ?? null;
$model_name = $_GET['model_name'] ?? null;
$color = $_GET['color'] ?? null;

if ($purchase_date && $model_name && $color) {
    $stmt = $conn->prepare("
        SELECT pr.purchase_date, p.model_name, pv.color, pv.size, pi.quantity, pi.buy_price, (pi.quantity * pi.buy_price) as total_amount
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        WHERE pr.purchase_date = ? AND p.model_name = ? AND pv.color = ?
        ORDER BY pr.purchase_date DESC, p.model_name, pv.color, pv.size
    ");
    $stmt->bind_param('sss', $purchase_date, $model_name, $color);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($item = $result->fetch_assoc()) {
        $data[] = $item;
    }
    $stmt->close();
} else {
    $allPurchaseItems = $conn->query("
        SELECT pr.purchase_date, p.model_name, pv.color, pv.size, pi.quantity, pi.buy_price, (pi.quantity * pi.buy_price) as total_amount
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        ORDER BY pr.purchase_date DESC, p.model_name, pv.color, pv.size
    ");

    $data = [];
    while ($item = $allPurchaseItems->fetch_assoc()) {
        $data[] = $item;
    }
}

echo json_encode($data);
?>
