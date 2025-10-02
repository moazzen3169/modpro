<?php
include 'env/db.php';

if (isset($_GET['sale_id']) && is_numeric($_GET['sale_id'])) {
    $sale_id = intval($_GET['sale_id']);

    // First check if sale exists
    $sale_check = $conn->query("SELECT sale_id FROM Sales WHERE sale_id = $sale_id");
    if ($sale_check->num_rows == 0) {
        echo '<div class="text-center py-8 text-red-500">
                <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                <p>فروش با شماره ' . $sale_id . ' یافت نشد.</p>
              </div>';
        exit;
    }

    // Get sale items with product details
    $items = $conn->query("
        SELECT si.*, p.model_name, pv.color, pv.size
        FROM Sale_Items si
        JOIN Product_Variants pv ON si.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        WHERE si.sale_id = $sale_id
    ");

    if ($items->num_rows == 0) {
        echo '<div class="text-center py-8 text-yellow-500">
                <i data-feather="package" class="w-12 h-12 mx-auto mb-4"></i>
                <p>هیچ آیتمی برای این فروش یافت نشد.</p>
                <p class="text-sm text-gray-500 mt-2">شماره فروش: ' . $sale_id . '</p>
              </div>';
    } else {
        echo '<div class="space-y-4">';
        while($item = $items->fetch_assoc()){
            echo '<div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-4">
                            <div>
                                <h4 class="font-medium text-gray-800">' . htmlspecialchars($item['model_name']) . '</h4>
                                <p class="text-sm text-gray-500">' . htmlspecialchars($item['color']) . ' / ' . htmlspecialchars($item['size']) . '</p>
                            </div>
                            <div class="text-left">
                                <p class="text-sm text-gray-600">تعداد: ' . max(0, intval($item['quantity'])) . '</p>
                                <p class="text-sm text-gray-600">قیمت فروش: ' . number_format(floatval($item['sell_price']), 0) . ' تومان</p>
                                <p class="font-medium text-gray-800">مجموع: ' . number_format(intval($item['quantity']) * floatval($item['sell_price']), 0) . ' تومان</p>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="openEditSaleItemModal(' . intval($item['sale_item_id']) . ', ' . intval($item['variant_id']) . ', ' . intval($item['quantity']) . ', ' . floatval($item['sell_price']) . ')" class="p-2 bg-yellow-100 rounded-lg text-yellow-600 hover:bg-yellow-200">
                                <i data-feather="edit" class="w-4 h-4"></i>
                            </button>
                            <a href="?delete_sale_item=' . intval($item['sale_item_id']) . '" onclick="return confirm(\'آیا مطمئن هستید که می‌خواهید این آیتم را حذف کنید؟\')" class="p-2 bg-red-100 rounded-lg text-red-600 hover:bg-red-200">
                                <i data-feather="trash-2" class="w-4 h-4"></i>
                            </a>
                        </div>
                    </div>
                </div>';
        }
        echo '</div>';
    }
} else {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>شناسه فروش نامعتبر است.</p>
          </div>';
}
?>
