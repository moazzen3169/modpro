<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

$purchase_date_input = isset($_GET['purchase_date']) ? (string) $_GET['purchase_date'] : null;
$model_name = $_GET['model_name'] ?? null;
$color = $_GET['color'] ?? null;
$supplierId = null;
$purchaseId = isset($_GET['purchase_id']) ? (int) $_GET['purchase_id'] : null;
$variantId = isset($_GET['variant_id']) ? (int) $_GET['variant_id'] : null;

if (isset($_GET['supplier_id']) && $_GET['supplier_id'] !== '') {
    try {
        $candidateSupplier = validate_int($_GET['supplier_id'], 1);
        $supplierStmt = $conn->prepare('SELECT supplier_id FROM Suppliers WHERE supplier_id = ?');
        $supplierStmt->bind_param('i', $candidateSupplier);
        $supplierStmt->execute();
        if ($supplierStmt->get_result()->fetch_row()) {
            $supplierId = $candidateSupplier;
        }
        $supplierStmt->close();
    } catch (Throwable) {
        // Ignore invalid supplier filter
    }
}

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

if ($purchaseId !== null && $variantId !== null) {
    // Handle individual purchase item query for edit functionality
    $query = "
        SELECT pr.purchase_id, pr.purchase_date, p.model_name, pv.variant_id, pv.color, pv.size, pi.quantity, pi.buy_price,
               (pi.quantity * pi.buy_price) AS total_amount, s.name AS supplier_name
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        JOIN Suppliers s ON pr.supplier_id = s.supplier_id
        WHERE pr.purchase_id = ? AND pi.variant_id = ?
    ";

    $stmt = $conn->prepare($query);
    $stmt->bind_param('ii', $purchaseId, $variantId);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($item = $result->fetch_assoc()) {
        $item['purchase_date'] = convert_gregorian_to_jalali_for_display((string) $item['purchase_date']);
        $data[] = $item;
    }
    $stmt->close();
} elseif ($purchase_date && $model_name && $color) {
    $query = "
        SELECT pr.purchase_id, pr.purchase_date, p.model_name, pv.variant_id, pv.color, pv.size, pi.quantity, pi.buy_price,
               (pi.quantity * pi.buy_price) AS total_amount, s.name AS supplier_name
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        JOIN Suppliers s ON pr.supplier_id = s.supplier_id
        WHERE pr.purchase_date = ? AND p.model_name = ? AND pv.color = ?
    ";

    if ($supplierId !== null) {
        $query .= ' AND pr.supplier_id = ?';
    }

    $query .= ' ORDER BY pr.purchase_date DESC, p.model_name, pv.color, pv.size';

    $stmt = $conn->prepare($query);
    if ($supplierId !== null) {
        $stmt->bind_param('sssi', $purchase_date, $model_name, $color, $supplierId);
    } else {
        $stmt->bind_param('sss', $purchase_date, $model_name, $color);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $data = [];
    while ($item = $result->fetch_assoc()) {
        $item['purchase_date'] = convert_gregorian_to_jalali_for_display((string) $item['purchase_date']);
        $data[] = $item;
    }
    $stmt->close();
} else {
    $query = "
        SELECT pr.purchase_id, pr.purchase_date, p.model_name, pv.variant_id, pv.color, pv.size, pi.quantity, pi.buy_price,
               (pi.quantity * pi.buy_price) AS total_amount, s.name AS supplier_name
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        JOIN Suppliers s ON pr.supplier_id = s.supplier_id
    ";

    if ($supplierId !== null) {
        $query .= ' WHERE pr.supplier_id = ?';
    }

    $query .= ' ORDER BY pr.purchase_date DESC, p.model_name, pv.color, pv.size';

    $stmt = $conn->prepare($query);
    if ($supplierId !== null) {
        $stmt->bind_param('i', $supplierId);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $data = [];
    while ($item = $result->fetch_assoc()) {
        $item['purchase_date'] = convert_gregorian_to_jalali_for_display((string) $item['purchase_date']);
        $data[] = $item;
    }
    $stmt->close();
}

echo json_encode($data);
?>
