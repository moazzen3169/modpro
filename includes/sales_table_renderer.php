<?php
declare(strict_types=1);

/**
 * Render the sales table markup used on the sales listing page.
 *
 * @param mysqli_result|false $salesResult
 */
function render_sales_table(mysqli_result|false $salesResult): string
{
    ob_start();
    ?>
    <table id="salesTable" class="w-full text-sm text-gray-900">
        <thead class="bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-gray-300">
            <tr>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">شماره فروش</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">مشتری</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تاریخ</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">روش پرداخت</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">وضعیت</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تعداد آیتم</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">مجموع</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">عملیات</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
            <?php if ($salesResult === false): ?>
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center text-red-600">
                        خطا در بارگذاری اطلاعات فروش. لطفاً بعداً دوباره تلاش کنید.
                    </td>
                </tr>
            <?php elseif ($salesResult->num_rows === 0): ?>
                <tr>
                    <td colspan="8" class="px-6 py-8 text-center">
                        <i data-feather="shopping-cart" class="w-12 h-12 text-gray-400 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-700 mb-2">هنوز فروشی ثبت نشده است</h3>
                        <p class="text-gray-500 mb-4">برای شروع، اولین فروش خود را ثبت کنید</p>
                        <button onclick="openModal('newSaleModal')" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                            ایجاد اولین فروش
                        </button>
                    </td>
                </tr>
            <?php else: ?>
                <?php while ($sale = $salesResult->fetch_assoc()): ?>
                    <?php
                    $sale_id = (int) $sale['sale_id'];
                    $customer_id = isset($sale['customer_id']) ? (int) $sale['customer_id'] : 0;
                    $customer_name = htmlspecialchars($sale['customer_name'] ?: 'مشتری حضوری', ENT_QUOTES, 'UTF-8');
                    $sale_date_value = convert_gregorian_to_jalali_for_display((string) $sale['sale_date']);
                    $sale_date = htmlspecialchars($sale_date_value, ENT_QUOTES, 'UTF-8');
                    $status = (string) ($sale['status'] ?? 'pending');
                    $payment_method = (string) ($sale['payment_method'] ?? 'cash');
                    $item_count = (int) ($sale['item_count'] ?? 0);
                    $total_amount = number_format((float) ($sale['total_amount'] ?? 0), 0);

                    $status_color = $status === 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                    $status_label = $status === 'paid' ? 'پرداخت شده' : 'در انتظار پرداخت';

                    $payment_icon = 'repeat';
                    $payment_text = 'انتقال بانکی';

                    if ($payment_method === 'cash') {
                        $payment_icon = 'dollar-sign';
                        $payment_text = 'نقدی';
                    } elseif ($payment_method === 'credit_card') {
                        $payment_icon = 'credit-card';
                        $payment_text = 'کارت اعتباری';
                    }

                    $sale_date_json = json_encode($sale_date_value, JSON_UNESCAPED_UNICODE);
                    $payment_method_json = json_encode($payment_method, JSON_UNESCAPED_UNICODE);
                    $status_json = json_encode($status, JSON_UNESCAPED_UNICODE);

                    $edit_callback = sprintf(
                        'openEditSaleModal(%d, %d, %s, %s, %s)',
                        $sale_id,
                        $customer_id,
                        $sale_date_json,
                        $payment_method_json,
                        $status_json
                    );
                    $edit_callback_escaped = htmlspecialchars($edit_callback, ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#فروش-<?php echo $sale_id; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $customer_name; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $sale_date; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <div class="flex items-center">
                                <i data-feather="<?php echo htmlspecialchars($payment_icon, ENT_QUOTES, 'UTF-8'); ?>" class="w-4 h-4 ml-1"></i>
                                <?php echo htmlspecialchars($payment_text, ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>"><?php echo $status_label; ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $item_count; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900"><?php echo $total_amount; ?> تومان</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2 space-x-reverse">
                                <button onclick="printReceipt(event, <?php echo $sale_id; ?>)" class="p-1 bg-green-100 rounded text-green-600 hover:bg-green-200 transition-colors" title="چاپ رسید">
                                    <i data-feather="printer" class="w-4 h-4"></i>
                                </button>
                                <button onclick="<?php echo $edit_callback_escaped; ?>" class="p-1 bg-yellow-100 rounded text-yellow-600 hover:bg-yellow-200 transition-colors" title="ویرایش">
                                    <i data-feather="edit" class="w-4 h-4"></i>
                                </button>
                                <a href="?delete_sale=<?php echo $sale_id; ?>" onclick="return confirm('آیا مطمئن هستید که می‌خواهید این فروش را حذف کنید؟')" class="p-1 bg-red-100 rounded text-red-600 hover:bg-red-200 transition-colors" title="حذف">
                                    <i data-feather="trash-2" class="w-4 h-4"></i>
                                </a>
                                <button onclick="showSaleItems(<?php echo $sale_id; ?>)" class="p-1 bg-blue-100 rounded text-blue-600 hover:bg-blue-200 transition-colors" title="مشاهده آیتم‌ها">
                                    <i data-feather="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endwhile; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php

    if ($salesResult instanceof mysqli_result) {
        $salesResult->free();
    }

    return (string) ob_get_clean();
}
