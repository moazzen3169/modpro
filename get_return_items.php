<?php
include 'env/db.php';

$return_id = $_GET['return_id'] ?? 0;

if (!$return_id) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>شناسه مرجوعی نامعتبر</p>
          </div>';
    exit();
}

// Get return details
$return = $conn->query("SELECT r.*, c.name as customer_name FROM Returns r LEFT JOIN Customers c ON r.customer_id = c.customer_id WHERE r.return_id = $return_id")->fetch_assoc();

if (!$return) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>مرجوعی یافت نشد</p>
          </div>';
    exit();
}

// Get return items
$items = $conn->query("SELECT ri.*, p.model_name, pv.color, pv.size FROM Return_Items ri JOIN Product_Variants pv ON ri.variant_id = pv.variant_id JOIN Products p ON pv.product_id = p.product_id WHERE ri.return_id = $return_id ORDER BY ri.return_item_id");

echo '<div class="space-y-6">';

// Return header
echo '<div class="bg-gray-50 p-4 rounded-lg">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <h4 class="font-medium text-gray-800">شماره مرجوعی</h4>
                <p class="text-gray-600">#مرجوعی-' . $return['return_id'] . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">مشتری</h4>
                <p class="text-gray-600">' . ($return['customer_name'] ?: 'مشتری حضوری') . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">تاریخ</h4>
                <p class="text-gray-600">' . $return['return_date'] . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">دلیل</h4>
                <p class="text-gray-600">' . $return['reason'] . '</p>
            </div>
        </div>
      </div>';

// Return items table
echo '<div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">محصول</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">رنگ</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">سایز</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">تعداد</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت واحد</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">مجموع</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">';

$total = 0;
if ($items->num_rows > 0) {
    while($item = $items->fetch_assoc()){
        $item_total = $item['quantity'] * $item['return_price'];
        $total += $item_total;

        echo '<tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-800">' . $item['model_name'] . '</td>
                <td class="px-4 py-3 text-gray-800">' . $item['color'] . '</td>
                <td class="px-4 py-3 text-gray-800">' . $item['size'] . '</td>
                <td class="px-4 py-3 text-gray-800">' . $item['quantity'] . '</td>
                <td class="px-4 py-3 text-gray-800">' . number_format($item['return_price'], 0) . ' تومان</td>
                <td class="px-4 py-3 text-gray-800 font-medium">' . number_format($item_total, 0) . ' تومان</td>
              </tr>';
    }
} else {
    echo '<tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">هیچ آیتمی یافت نشد</td></tr>';
}

echo '        </tbody>
            <tfoot class="bg-gray-50">
                <tr>
                    <td colspan="5" class="px-4 py-3 text-right font-bold text-gray-800">مجموع کل:</td>
                    <td class="px-4 py-3 font-bold text-gray-800">' . number_format($total, 0) . ' تومان</td>
                </tr>
            </tfoot>
        </table>
      </div>';

echo '</div>';
?>
