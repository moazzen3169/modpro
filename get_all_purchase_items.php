<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

$purchase_date_input = isset($_GET['purchase_date']) ? (string) $_GET['purchase_date'] : null;
$model_name = $_GET['model_name'] ?? null;
$color = $_GET['color'] ?? null;

$purchase_date = null;
if ($purchase_date_input !== null && $purchase_date_input !== '') {
    try {
        $purchase_date = validate_date($purchase_date_input);
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode(['error' => normalize_error_message($e)]);
        exit();
    }
}

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
        $item['purchase_date'] = convert_gregorian_to_jalali_for_display((string) $item['purchase_date']);
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
        $item['purchase_date'] = convert_gregorian_to_jalali_for_display((string) $item['purchase_date']);
        $data[] = $item;
    }
}

echo json_encode($data);
?>
