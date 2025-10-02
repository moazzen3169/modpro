<?php
include 'env/db.php';

if (isset($_GET['sale_id'])) {
    $sale_id = $_GET['sale_id'];

    // Get sale items with product details
    $items = $conn->query("
        SELECT si.*, p.model_name, pv.color, pv.size
        FROM Sale_Items si
        JOIN Product_Variants pv ON si.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        WHERE si.sale_id = $sale_id
    ");

    echo '<div class="space-y-4">';
    while($item = $items->fetch_assoc()){
        echo '<div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                <div class="flex items-center justify-between">
                    <div class="flex items-center space-x-4">
                        <div>
                            <h4 class="font-medium text-gray-800">' . $item['model_name'] . '</h4>
                            <p class="text-sm text-gray-500">' . $item['color'] . ' / ' . $item['size'] . '</p>
                        </div>
                        <div class="text-left">
                            <p class="text-sm text-gray-600">تعداد: ' . $item['quantity'] . '</p>
                            <p class="text-sm text-gray-600">قیمت فروش: ' . number_format($item['sell_price'], 0) . ' تومان</p>
                            <p class="font-medium text-gray-800">مجموع: ' . number_format($item['quantity'] * $item['sell_price'], 0) . ' تومان</p>
                        </div>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="openEditSaleItemModal(' . $item['sale_item_id'] . ', ' . $item['variant_id'] . ', ' . $item['quantity'] . ', ' . $item['sell_price'] . ')" class="p-2 bg-yellow-100 rounded-lg text-yellow-600 hover:bg-yellow-200">
                            <i data-feather="edit" class="w-4 h-4"></i>
                        </button>
                        <a href="?delete_sale_item=' . $item['sale_item_id'] . '" onclick="return confirm(\'آیا مطمئن هستید که می‌خواهید این آیتم را حذف کنید؟\')" class="p-2 bg-red-100 rounded-lg text-red-600 hover:bg-red-200">
                            <i data-feather="trash-2" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            </div>';
    }
    echo '</div>';
} else {
    echo '<p class="text-gray-500">فروش انتخاب شده یافت نشد.</p>';
}
?>
