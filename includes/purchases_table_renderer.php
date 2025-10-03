<?php
declare(strict_types=1);

/**
 * Render the purchases table markup used on the purchases listing page.
 *
 * @param mysqli_result|false $purchasesResult
 */
function render_purchases_table(mysqli_result|false $purchasesResult): string
{
    ob_start();
    ?>
    <table id="purchasesTable" class="w-full text-sm text-gray-900">
        <thead class="bg-gradient-to-r from-green-50 to-emerald-50 border-b border-gray-300">
            <tr>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">شماره خرید</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">تأمین‌کننده</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">تاریخ</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">وضعیت</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">تعداد آیتم</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">مجموع</th>
                <th class="px-6 py-4 text-right text-xs font-bold text-emerald-700 uppercase tracking-wider">عملیات</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-100">
            <?php if ($purchasesResult === false): ?>
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center text-red-600">
                        خطا در بارگذاری اطلاعات خرید. لطفاً بعداً دوباره تلاش کنید.
                    </td>
                </tr>
            <?php elseif ($purchasesResult->num_rows === 0): ?>
                <tr>
                    <td colspan="7" class="px-6 py-8 text-center">
                        <i data-feather="shopping-bag" class="w-12 h-12 text-gray-400 mx-auto mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-700 mb-2">هنوز خریدی ثبت نشده است</h3>
                        <p class="text-gray-500 mb-4">برای شروع، اولین خرید خود را ثبت کنید</p>
                        <button onclick="openModal('newPurchaseModal')" class="px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-colors">
                            ایجاد اولین خرید
                        </button>
                    </td>
                </tr>
            <?php else: ?>
                <?php while ($purchase = $purchasesResult->fetch_assoc()): ?>
                    <?php
                    $purchase_id = (int) $purchase['purchase_id'];
                    $supplier_name = htmlspecialchars((string) ($purchase['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $purchase_date = htmlspecialchars((string) $purchase['purchase_date'], ENT_QUOTES, 'UTF-8');
                    $status = (string) ($purchase['status'] ?? 'pending');
                    $item_count = (int) ($purchase['item_count'] ?? 0);
                    $total_amount = number_format((float) ($purchase['total_amount'] ?? 0), 0);

                    $status_color = 'bg-yellow-100 text-yellow-800';
                    $status_label = 'در انتظار دریافت';

                    if ($status === 'received') {
                        $status_color = 'bg-green-100 text-green-800';
                        $status_label = 'دریافت شده';
                    } elseif ($status === 'cancelled') {
                        $status_color = 'bg-red-100 text-red-700';
                        $status_label = 'لغو شده';
                    }

                    $purchase_date_json = json_encode((string) $purchase['purchase_date'], JSON_UNESCAPED_UNICODE);
                    $supplier_name_json = json_encode((string) ($purchase['supplier_name'] ?? ''), JSON_UNESCAPED_UNICODE);
                    $status_json = json_encode($status, JSON_UNESCAPED_UNICODE);

                    $edit_callback = sprintf(
                        'openEditPurchaseModal(%d, %s, %s, %s)',
                        $purchase_id,
                        $supplier_name_json,
                        $purchase_date_json,
                        $status_json
                    );
                    $edit_callback_escaped = htmlspecialchars($edit_callback, ENT_QUOTES, 'UTF-8');
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">#خرید-<?php echo $purchase_id; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $supplier_name ?: 'نامشخص'; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $purchase_date; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>"><?php echo $status_label; ?></span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $item_count; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900"><?php echo $total_amount; ?> تومان</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                            <div class="flex space-x-2 space-x-reverse">
                                <button onclick="<?php echo $edit_callback_escaped; ?>" class="p-1 bg-yellow-100 rounded text-yellow-600 hover:bg-yellow-200 transition-colors" title="ویرایش">
                                    <i data-feather="edit" class="w-4 h-4"></i>
                                </button>
                                <a href="?delete_purchase=<?php echo $purchase_id; ?>" onclick="return confirm('آیا مطمئن هستید که می‌خواهید این خرید را حذف کنید؟')" class="p-1 bg-red-100 rounded text-red-600 hover:bg-red-200 transition-colors" title="حذف">
                                    <i data-feather="trash-2" class="w-4 h-4"></i>
                                </a>
                                <button onclick="showPurchaseItems(<?php echo $purchase_id; ?>)" class="p-1 bg-emerald-100 rounded text-emerald-600 hover:bg-emerald-200 transition-colors" title="مشاهده آیتم‌ها">
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

    if ($purchasesResult instanceof mysqli_result) {
        $purchasesResult->free();
    }

    return (string) ob_get_clean();
}
