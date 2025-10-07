<?php
require_once __DIR__ . '/env/bootstrap.php';

try {
    $return_id = validate_int($_GET['return_id'] ?? null, 1);
} catch (Throwable $e) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>شناسه مرجوعی نامعتبر</p>
          </div>';
    exit();
}

$returnStmt = $conn->prepare('SELECT pr.purchase_return_id, pr.return_date, pr.note, pr.total_amount, COALESCE(s.name, "") AS supplier_name FROM Purchase_Returns pr LEFT JOIN Suppliers s ON pr.supplier_id = s.supplier_id WHERE pr.purchase_return_id = ?');
$returnStmt->bind_param('i', $return_id);
$returnStmt->execute();
$return = $returnStmt->get_result()->fetch_assoc();

if (!$return) {
    echo '<div class="text-center py-8 text-red-500">
            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
            <p>مرجوعی یافت نشد</p>
          </div>';
    exit();
}

$itemsStmt = $conn->prepare('SELECT prit.quantity, prit.return_price, p.model_name, pv.color, pv.size FROM Purchase_Return_Items prit JOIN Product_Variants pv ON prit.variant_id = pv.variant_id JOIN Products p ON pv.product_id = p.product_id WHERE prit.purchase_return_id = ? ORDER BY prit.purchase_return_item_id');
$itemsStmt->bind_param('i', $return_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$supplier_name = htmlspecialchars($return['supplier_name'] ?: 'تامین‌کننده نامشخص', ENT_QUOTES, 'UTF-8');
$return_date = htmlspecialchars(convert_gregorian_to_jalali_for_display((string) $return['return_date']), ENT_QUOTES, 'UTF-8');
$note = htmlspecialchars((string) ($return['note'] ?? ''), ENT_QUOTES, 'UTF-8');

echo '<div class="space-y-6">';

echo '<div class="bg-gray-50 p-4 rounded-lg">
        <div class="grid grid-cols-2 gap-4">
            <div>
                <h4 class="font-medium text-gray-800">شماره مرجوعی</h4>
                <p class="text-gray-600">#مرجوعی-' . $return_id . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">تامین‌کننده</h4>
                <p class="text-gray-600">' . $supplier_name . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">تاریخ</h4>
                <p class="text-gray-600">' . $return_date . '</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800">یادداشت</h4>
                <p class="text-gray-600">' . ($note !== '' ? $note : '—') . '</p>
            </div>
        </div>
      </div>';

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
if ($itemsResult->num_rows > 0) {
    while ($item = $itemsResult->fetch_assoc()) {
        $quantity = (int) $item['quantity'];
        $return_price = (float) $item['return_price'];
        $item_total = $quantity * $return_price;
        $total += $item_total;

        $model_name = htmlspecialchars((string) $item['model_name'], ENT_QUOTES, 'UTF-8');
        $color = htmlspecialchars((string) $item['color'], ENT_QUOTES, 'UTF-8');
        $size = htmlspecialchars((string) $item['size'], ENT_QUOTES, 'UTF-8');

        echo '<tr class="hover:bg-gray-50">
                <td class="px-4 py-3 text-gray-800">' . $model_name . '</td>
                <td class="px-4 py-3 text-gray-800">' . $color . '</td>
                <td class="px-4 py-3 text-gray-800">' . $size . '</td>
                <td class="px-4 py-3 text-gray-800">' . $quantity . '</td>
                <td class="px-4 py-3 text-gray-800">' . number_format($return_price, 0) . ' تومان</td>
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
