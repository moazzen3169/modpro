<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['purchase_id']) || !isset($input['variant_id']) || !isset($input['quantity']) || !isset($input['buy_price'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input data']);
    exit;
}

$purchaseId = (int) $input['purchase_id'];
$variantId = (int) $input['variant_id'];
$newQuantity = (int) $input['quantity'];
$newBuyPrice = (float) $input['buy_price'];

if ($purchaseId <= 0 || $variantId <= 0 || $newQuantity <= 0 || $newBuyPrice <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data values']);
    exit;
}

$conn->begin_transaction();

try {
    // Get current purchase item data
    $stmt = $conn->prepare('SELECT quantity, buy_price FROM Purchase_Items WHERE purchase_id = ? AND variant_id = ?');
    $stmt->bind_param('ii', $purchaseId, $variantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $currentItem = $result->fetch_assoc();
    $stmt->close();

    if (!$currentItem) {
        throw new Exception('Purchase item not found');
    }

    $oldQuantity = (int) $currentItem['quantity'];
    $quantityDiff = $newQuantity - $oldQuantity;

    // Update stock in Product_Variants
    $stmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
    $stmt->bind_param('ii', $quantityDiff, $variantId);
    $stmt->execute();
    $stmt->close();

    // Update purchase item
    $stmt = $conn->prepare('UPDATE Purchase_Items SET quantity = ?, buy_price = ? WHERE purchase_id = ? AND variant_id = ?');
    $stmt->bind_param('idii', $newQuantity, $newBuyPrice, $purchaseId, $variantId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode(['success' => true, 'message' => 'Purchase item updated successfully']);

} catch (Exception $e) {
    $conn->rollback();
    error_log('Error updating purchase item: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update purchase item: ' . $e->getMessage()]);
}
?>
