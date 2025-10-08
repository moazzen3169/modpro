<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request payload']);
    exit;
}

try {
    $purchaseId = validate_int($input['purchase_id'] ?? null, 1);
    $currentVariantId = validate_int($input['variant_id'] ?? null, 1);
    $newQuantity = validate_int($input['quantity'] ?? null, 1);
    $newBuyPrice = validate_price($input['buy_price'] ?? null);

    $productName = trim((string) ($input['product_name'] ?? ''));
    if ($productName === '') {
        throw new InvalidArgumentException('نام محصول نمی‌تواند خالی باشد.');
    }

    $color = trim((string) ($input['color'] ?? ''));
    if ($color === '') {
        throw new InvalidArgumentException('رنگ نمی‌تواند خالی باشد.');
    }

    $size = trim((string) ($input['size'] ?? ''));
    if ($size === '') {
        throw new InvalidArgumentException('سایز نمی‌تواند خالی باشد.');
    }

    $purchaseDateInput = trim((string) ($input['purchase_date'] ?? ''));
    if ($purchaseDateInput === '') {
        throw new InvalidArgumentException('تاریخ خرید نمی‌تواند خالی باشد.');
    }
    $purchaseDate = validate_date($purchaseDateInput);

    $supplierId = validate_int($input['supplier_id'] ?? null, 1);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => normalize_error_message($e)]);
    exit;
}

// اطمینان از وجود تامین‌کننده انتخاب شده
$supplierCheckStmt = $conn->prepare('SELECT supplier_id FROM Suppliers WHERE supplier_id = ?');
$supplierCheckStmt->bind_param('i', $supplierId);
$supplierCheckStmt->execute();
if (!$supplierCheckStmt->get_result()->fetch_row()) {
    $supplierCheckStmt->close();
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'تامین‌کننده انتخاب‌شده یافت نشد.']);
    exit;
}
$supplierCheckStmt->close();

$conn->begin_transaction();

try {
    // دریافت اطلاعات فعلی آیتم خرید
    $currentStmt = $conn->prepare('
        SELECT
            pi.purchase_item_id,
            pi.quantity AS old_quantity,
            pi.buy_price AS old_buy_price,
            pr.purchase_date AS current_purchase_date,
            pr.supplier_id AS current_supplier_id,
            pv.product_id AS current_product_id,
            pv.variant_id AS current_variant_id
        FROM Purchase_Items pi
        JOIN Purchases pr ON pi.purchase_id = pr.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        WHERE pi.purchase_id = ? AND pi.variant_id = ?
        FOR UPDATE
    ');
    $currentStmt->bind_param('ii', $purchaseId, $currentVariantId);
    $currentStmt->execute();
    $currentResult = $currentStmt->get_result();
    $currentItem = $currentResult->fetch_assoc();
    $currentStmt->close();

    if (!$currentItem) {
        throw new RuntimeException('آیتم خرید موردنظر یافت نشد.');
    }

    $oldQuantity = (int) $currentItem['old_quantity'];
    $existingSupplierId = (int) $currentItem['current_supplier_id'];
    $existingPurchaseDate = (string) $currentItem['current_purchase_date'];

    // یافتن یا ایجاد محصول با نام جدید
    $productStmt = $conn->prepare('SELECT product_id FROM Products WHERE model_name = ?');
    $productStmt->bind_param('s', $productName);
    $productStmt->execute();
    $productResult = $productStmt->get_result();
    $productRow = $productResult->fetch_assoc();
    $productStmt->close();

    if ($productRow) {
        $targetProductId = (int) $productRow['product_id'];
    } else {
        $emptyCategory = '';
        $insertProductStmt = $conn->prepare('INSERT INTO Products (model_name, category) VALUES (?, ?)');
        $insertProductStmt->bind_param('ss', $productName, $emptyCategory);
        $insertProductStmt->execute();
        $targetProductId = $conn->insert_id;
        $insertProductStmt->close();
    }

    // یافتن یا ایجاد واریانت با ترکیب جدید
    $variantStmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE product_id = ? AND color = ? AND size = ?');
    $variantStmt->bind_param('iss', $targetProductId, $color, $size);
    $variantStmt->execute();
    $variantResult = $variantStmt->get_result();
    $variantRow = $variantResult->fetch_assoc();
    $variantStmt->close();

    if ($variantRow) {
        $targetVariantId = (int) $variantRow['variant_id'];
    } else {
        $initialStock = 0;
        $insertVariantStmt = $conn->prepare('INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES (?, ?, ?, ?, ?)');
        $insertVariantStmt->bind_param('issdi', $targetProductId, $color, $size, $newBuyPrice, $initialStock);
        $insertVariantStmt->execute();
        $targetVariantId = $conn->insert_id;
        $insertVariantStmt->close();
    }

    // به‌روزرسانی موجودی انبار
    if ($targetVariantId === $currentVariantId) {
        $quantityDiff = $newQuantity - $oldQuantity;
        if ($quantityDiff !== 0) {
            $stockUpdateStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
            $stockUpdateStmt->bind_param('ii', $quantityDiff, $currentVariantId);
            $stockUpdateStmt->execute();
            $stockUpdateStmt->close();
        }
    } else {
        $decreaseOldStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
        $decreaseOldStockStmt->bind_param('ii', $oldQuantity, $currentVariantId);
        $decreaseOldStockStmt->execute();
        $decreaseOldStockStmt->close();

        $increaseNewStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
        $increaseNewStockStmt->bind_param('ii', $newQuantity, $targetVariantId);
        $increaseNewStockStmt->execute();
        $increaseNewStockStmt->close();
    }

    // به‌روزرسانی اطلاعات آیتم خرید
    if ($targetVariantId === $currentVariantId) {
        $updateItemStmt = $conn->prepare('UPDATE Purchase_Items SET quantity = ?, buy_price = ? WHERE purchase_id = ? AND variant_id = ?');
        $updateItemStmt->bind_param('idii', $newQuantity, $newBuyPrice, $purchaseId, $currentVariantId);
        $updateItemStmt->execute();
        $updateItemStmt->close();
    } else {
        $updateItemStmt = $conn->prepare('UPDATE Purchase_Items SET variant_id = ?, quantity = ?, buy_price = ? WHERE purchase_id = ? AND variant_id = ?');
        $updateItemStmt->bind_param('iidii', $targetVariantId, $newQuantity, $newBuyPrice, $purchaseId, $currentVariantId);
        $updateItemStmt->execute();
        $updateItemStmt->close();
    }

    // به‌روزرسانی تامین‌کننده یا تاریخ خرید در صورت نیاز
    if ($existingSupplierId !== $supplierId || $existingPurchaseDate !== $purchaseDate) {
        $updatePurchaseStmt = $conn->prepare('UPDATE Purchases SET supplier_id = ?, purchase_date = ? WHERE purchase_id = ?');
        $updatePurchaseStmt->bind_param('isi', $supplierId, $purchaseDate, $purchaseId);
        $updatePurchaseStmt->execute();
        $updatePurchaseStmt->close();
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'آیتم خرید با موفقیت بروزرسانی شد.'
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    error_log('Error updating purchase item: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'خطا در بروزرسانی آیتم خرید.']);
}
