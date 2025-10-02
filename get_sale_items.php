<?php
require_once __DIR__ . '/env/bootstrap.php';

try {
    $sale_id = validate_int($_GET['sale_id'] ?? null, 1);
} catch (Throwable $e) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>شناسه فروش نامعتبر است.</p>
          </div>';
    exit;
}

$saleStmt = $conn->prepare('SELECT sale_id FROM Sales WHERE sale_id = ?');
$saleStmt->bind_param('i', $sale_id);
$saleStmt->execute();
$saleExists = $saleStmt->get_result()->fetch_assoc();

if (!$saleExists) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>فروش با شماره ' . $sale_id . ' یافت نشد.</p>
          </div>';
    exit;
}

$itemsStmt = $conn->prepare('SELECT si.sale_item_id, si.variant_id, si.quantity, si.sell_price, p.model_name, pv.color, pv.size FROM Sale_Items si JOIN Product_Variants pv ON si.variant_id = pv.variant_id JOIN Products p ON pv.product_id = p.product_id WHERE si.sale_id = ?');
$itemsStmt->bind_param('i', $sale_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

if ($itemsResult->num_rows === 0) {
    echo '<div class="text-center py-8 text-yellow-500">
            <i data-feather="package" class="w-12 h-12 mx-auto mb-4"></i>
            <p>هیچ آیتمی برای این فروش یافت نشد.</p>
            <p class="text-sm text-gray-500 mt-2">شماره فروش: ' . $sale_id . '</p>
          </div>';
    exit;
}

echo '<div class="space-y-4">';
while ($item = $itemsResult->fetch_assoc()) {
    $sale_item_id = (int) $item['sale_item_id'];
    $variant_id = (int) $item['variant_id'];
    $quantity = max(0, (int) $item['quantity']);
    $sell_price = (float) $item['sell_price'];
    $total = $quantity * $sell_price;
    $model_name = htmlspecialchars((string) $item['model_name'], ENT_QUOTES, 'UTF-8');
    $color = htmlspecialchars((string) $item['color'], ENT_QUOTES, 'UTF-8');
    $size = htmlspecialchars((string) $item['size'], ENT_QUOTES, 'UTF-8');

    $editCallback = htmlspecialchars(sprintf('openEditSaleItemModal(%d, %d, %d, %s)', $sale_item_id, $variant_id, $quantity, json_encode($sell_price)), ENT_QUOTES, 'UTF-8');
    $deleteHref = '?delete_sale_item=' . $sale_item_id;

    echo '<div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-4">
                    <div>
                        <h4 class="font-medium text-gray-800">' . $model_name . '</h4>
                        <p class="text-sm text-gray-500">' . $color . ' / ' . $size . '</p>
                    </div>
                    <div class="text-left">
                        <p class="text-sm text-gray-600">تعداد: ' . $quantity . '</p>
                        <p class="text-sm text-gray-600">قیمت فروش: ' . number_format($sell_price, 0) . ' تومان</p>
                        <p class="font-medium text-gray-800">مجموع: ' . number_format($total, 0) . ' تومان</p>
                    </div>
                </div>
                <div class="flex space-x-2">
                    <button onclick="' . $editCallback . '" class="p-2 bg-yellow-100 rounded-lg text-yellow-600 hover:bg-yellow-200">
                        <i data-feather="edit" class="w-4 h-4"></i>
                    </button>
                    <a href="' . htmlspecialchars($deleteHref, ENT_QUOTES, 'UTF-8') . '" onclick="return confirm(\'آیا مطمئن هستید که می‌خواهید این آیتم را حذف کنید؟\')" class="p-2 bg-red-100 rounded-lg text-red-600 hover:bg-red-200">
                        <i data-feather="trash-2" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
        </div>';
}
echo '</div>';
?>
