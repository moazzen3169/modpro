<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $purchase_id = validate_int($_GET['purchase_id'] ?? null, 1);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'شناسه خرید نامعتبر است.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$purchaseStmt = $conn->prepare('SELECT p.purchase_id, p.purchase_date, p.supplier_id, s.name AS supplier_name FROM Purchases p JOIN Suppliers s ON p.supplier_id = s.supplier_id WHERE p.purchase_id = ?');
$purchaseStmt->bind_param('i', $purchase_id);
$purchaseStmt->execute();
$purchase = $purchaseStmt->get_result()->fetch_assoc();

if (!$purchase) {
    echo json_encode([
        'success' => false,
        'message' => 'خرید موردنظر یافت نشد.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$itemsStmt = $conn->prepare(
    'SELECT
        pi.purchase_item_id,
        pi.variant_id,
        pi.quantity,
        pi.buy_price,
        pr.model_name,
        pv.color,
        pv.size,
        COALESCE(SUM(ri.quantity), 0) AS returned_quantity
    FROM Purchase_Items pi
    JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
    JOIN Products pr ON pv.product_id = pr.product_id
    LEFT JOIN Return_Items ri ON ri.purchase_item_id = pi.purchase_item_id
    LEFT JOIN Returns r ON r.return_id = ri.return_id AND r.purchase_id = pi.purchase_id
    WHERE pi.purchase_id = ?
    GROUP BY pi.purchase_item_id, pi.variant_id, pi.quantity, pi.buy_price, pr.model_name, pv.color, pv.size'
);
$itemsStmt->bind_param('i', $purchase_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$items = [];
while ($row = $itemsResult->fetch_assoc()) {
    $quantityPurchased = (int) $row['quantity'];
    $returnedQuantity = (int) $row['returned_quantity'];
    $availableQuantity = max(0, $quantityPurchased - $returnedQuantity);

    $items[] = [
        'purchase_item_id' => (int) $row['purchase_item_id'],
        'variant_id' => (int) $row['variant_id'],
        'quantity' => $quantityPurchased,
        'available_quantity' => $availableQuantity,
        'buy_price' => (float) $row['buy_price'],
        'product_name' => (string) $row['model_name'],
        'color' => (string) $row['color'],
        'size' => (string) $row['size'],
    ];
}

$response = [
    'success' => true,
    'purchase' => [
        'purchase_id' => (int) $purchase['purchase_id'],
        'purchase_date' => (string) $purchase['purchase_date'],
        'supplier_id' => (int) $purchase['supplier_id'],
        'supplier_name' => (string) $purchase['supplier_name'],
    ],
    'items' => $items,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
