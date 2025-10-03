<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        if ($action === 'create_purchase') {
            $supplierId = 1; // default supplier
            $purchaseDate = $_POST['purchase_date'] ?? '';
            $paymentMethod = 'cash';
            $status = 'completed';
            $items = $_POST['items'] ?? [];

            $errors = [];

            if (empty($purchaseDate) || !strtotime($purchaseDate)) {
                $errors[] = 'تاریخ خرید معتبر وارد کنید.';
            }

            if (empty($items) || !is_array($items)) {
                $errors[] = 'حداقل یک آیتم اضافه کنید.';
            } else {
                foreach ($items as $item) {
                    $product_name = trim($item['product_name'] ?? '');
                    $color_sizes = $item['color_sizes'] ?? [];
                    $buy_price = (float) ($item['buy_price'] ?? 0);
                    if (empty($product_name) || empty($color_sizes) || $buy_price <= 0) {
                        $errors[] = 'اطلاعات آیتم نامعتبر.';
                        break;
                    }
                    foreach ($color_sizes as $cs) {
                        if (empty($cs['color']) || empty($cs['sizes'])) {
                            $errors[] = 'اطلاعات رنگ و سایز نامعتبر.';
                            break 2;
                        }
                        foreach ($cs['sizes'] as $size) {
                            if (empty($size['size']) || (int)($size['quantity'] ?? 0) <= 0) {
                                $errors[] = 'اطلاعات سایز نامعتبر.';
                                break 3;
                            }
                        }
                    }
                }
            }

            if (empty($errors)) {
                $conn->begin_transaction();

                try {
                    // Insert purchase
                    $insertPurchaseStmt = $conn->prepare(
                        'INSERT INTO Purchases (supplier_id, purchase_date, payment_method, status) VALUES (?, ?, ?, ?)'
                    );
                    $insertPurchaseStmt->bind_param('isss', $supplierId, $purchaseDate, $paymentMethod, $status);
                    $insertPurchaseStmt->execute();
                    $purchaseId = $conn->insert_id;
                    $insertPurchaseStmt->close();

                    // Process items
                    foreach ($items as $item) {
                        $product_name = trim($item['product_name']);
                        $color_sizes = $item['color_sizes'];
                        $buy_price = (float) $item['buy_price'];

                        // Find or create product
                        $stmt = $conn->prepare('SELECT product_id FROM Products WHERE model_name = ?');
                        $stmt->bind_param('s', $product_name);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($row = $result->fetch_assoc()) {
                            $product_id = $row['product_id'];
                        } else {
                            $empty_category = '';
                            $stmt = $conn->prepare('INSERT INTO Products (model_name, category) VALUES (?, ?)');
                            $stmt->bind_param('ss', $product_name, $empty_category);
                            $stmt->execute();
                            $product_id = $conn->insert_id;
                        }
                        $stmt->close();

                        foreach ($color_sizes as $cs) {
                            $color = trim($cs['color']);
                            $sizes = $cs['sizes'];
                            foreach ($sizes as $size) {
                                $size_value = trim($size['size']);
                                $quantity = (int) $size['quantity'];

                                // Find or create variant
                                $stmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE product_id = ? AND color = ? AND size = ?');
                                $stmt->bind_param('iss', $product_id, $color, $size_value);
                                $stmt->execute();
                                $result = $stmt->get_result();
                                if ($row = $result->fetch_assoc()) {
                                    $variant_id = $row['variant_id'];
                                } else {
                                    $initial_stock = 0;
                                    $stmt = $conn->prepare('INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES (?, ?, ?, ?, ?)');
                                    $stmt->bind_param('issdi', $product_id, $color, $size_value, $buy_price, $initial_stock);
                                    $stmt->execute();
                                    $variant_id = $conn->insert_id;
                                }
                                $stmt->close();

                                // Insert purchase_item
                                $stmt = $conn->prepare('INSERT INTO Purchase_Items (purchase_id, variant_id, quantity, buy_price) VALUES (?, ?, ?, ?)');
                                $stmt->bind_param('iiid', $purchaseId, $variant_id, $quantity, $buy_price);
                                $stmt->execute();
                                $stmt->close();

                                // Update stock
                                $stmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
                                $stmt->bind_param('ii', $quantity, $variant_id);
                                $stmt->execute();
                                $stmt->close();
                            }
                        }
                    }

                    $conn->commit();
                    add_flash_message('success', 'خرید با موفقیت ثبت شد.');
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = 'خطا در ثبت خرید: ' . $e->getMessage();
                }
            }

            if (!empty($errors)) {
                add_flash_message('error', implode('<br>', $errors));
            }

            header('Location: purchases.php');
            exit;
        }
    }
}

// Get suppliers for modal
$suppliersStmt = $conn->prepare('SELECT supplier_id, name FROM Suppliers ORDER BY name');
$suppliersStmt->execute();
$suppliersResult = $suppliersStmt->get_result();
$suppliers = [];
while ($row = $suppliersResult->fetch_assoc()) {
    $suppliers[] = $row;
}
$suppliersStmt->close();

$allPurchaseItems = $conn->query("
SELECT pr.purchase_date, p.model_name, pv.color, GROUP_CONCAT(DISTINCT pv.size ORDER BY pv.size SEPARATOR ', ') as sizes, SUM(pi.quantity) as total_quantity, AVG(pi.buy_price) as avg_buy_price, SUM(pi.quantity * pi.buy_price) as total_amount
FROM Purchases pr
JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
JOIN Products p ON pv.product_id = p.product_id
GROUP BY pr.purchase_date, p.model_name, pv.color
ORDER BY MAX(pr.created_at) DESC
");

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>مشاهده خریدها - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }
        .summary-table {
            overflow-x: auto;
        }
        .summary-table table,
        .details-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table th, .summary-table td,
        .details-table th, .details-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: right;
        }
        .summary-table th,
        .details-table th {
            background-color: #f3f4f6;
            font-weight: 600;
        }
        .details-table {
            max-height: 300px;
            overflow-y: auto;
        }
        .month-summary {
            margin-bottom: 1.5rem;
            border: 1px solid #e5e7eb;
            border-radius: 0.5rem;
            background-color: #fff;
            box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
        }
        .month-header {
            padding: 1rem;
            cursor: pointer;
            user-select: none;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: 600;
            font-size: 1.125rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .month-header:hover {
            background-color: #f9fafb;
        }
        .toggle-icon {
            transition: transform 0.3s ease;
        }
        .toggle-icon.open {
            transform: rotate(90deg);
        }
        
        /* استایل‌های جدید برای بخش سایزها */
        .size-selector-btn {
            transition: all 0.2s ease;
        }
        .size-selector-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .size-selector-btn:active {
            transform: translateY(0);
        }
        .quantity-input {
            transition: all 0.2s ease;
        }
        .quantity-input:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
        }
        .quick-action-btn {
            transition: all 0.2s ease;
        }
        .quick-action-btn:hover {
            transform: scale(1.05);
        }
        /* انیمیشن برای سایزهای انتخاب شده */
        @keyframes sizeAdded {
            0% {
                background-color: rgba(34, 197, 94, 0.3);
            }
            100% {
                background-color: transparent;
            }
        }
        .size-added {
            animation: sizeAdded 1s ease-out;
        }
        /* استایل برای حالت‌های مختلف دکمه‌ها */
        .btn-success {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        .btn-danger {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }
        /* انیمیشن برای افزودن آیتم‌های جدید */
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .purchase-item {
            animation: slideIn 0.3s ease-out;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 overflow-auto p-6">
            <!-- Header -->
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-semibold">خریدها</h2>
                <button onclick="openModal('purchaseModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-sm hover:shadow-md">
                    <i data-feather="plus" class="ml-2"></i>
                    خرید جدید
                </button>
            </div>

            <?php if (!empty($flash_messages['success']) || !empty($flash_messages['error'])): ?>
                <div class="space-y-3 mb-6">
                    <?php foreach ($flash_messages['success'] as $message): ?>
                        <div class="flex items-center justify-between bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg">
                            <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($flash_messages['error'] as $message): ?>
                        <div class="flex items-center justify-between bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg">
                            <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
                <div class="p-6 border-b border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-800">همه ردیف های محصولات خرید</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">نام محصول</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">رنگ</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">سایز ها</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">تعداد کل</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت خرید</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت کل</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">تاریخ</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php while ($item = $allPurchaseItems->fetch_assoc()): ?>
                                <tr>
                                    <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($item['model_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($item['sizes'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($item['total_quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo number_format($item['avg_buy_price'], 0); ?> تومان</td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo number_format($item['total_amount'], 0); ?> تومان</td>
                                    <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($item['purchase_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-4 py-3 text-gray-800">
                                        <button onclick="showDetailedPurchases('<?php echo htmlspecialchars($item['purchase_date'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($item['model_name'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8'); ?>')" class="px-3 py-1 bg-blue-500 text-white rounded-md hover:bg-blue-600 text-sm">
                                            نمایش جزئیات
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Purchase Modal -->
    <div id="purchaseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-4 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white max-h-[90vh] overflow-y-auto">
            <div class="sticky top-0 bg-white py-4 border-b border-gray-200 z-10">
                <div class="flex items-center justify-between">
                    <h3 class="text-xl font-bold text-gray-900">ثبت خرید جدید</h3>
                    <button onclick="closeModal('purchaseModal')" class="text-gray-500 hover:text-gray-700 p-1 rounded-full hover:bg-gray-100 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            </div>

            <form id="purchaseForm" method="POST" action="purchases.php" class="py-4">
                <input type="hidden" name="action" value="create_purchase">
                
                <!-- بخش اطلاعات اصلی -->
                <div class="bg-blue-50 p-4 rounded-lg mb-6 border border-blue-200">
                    <h4 class="text-lg font-semibold text-blue-800 mb-3 flex items-center">
                        <i data-feather="info" class="ml-2"></i>
                        اطلاعات اصلی خرید
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                                <i data-feather="calendar" class="ml-1 w-4 h-4"></i>
                                تاریخ خرید
                                <span class="text-red-500 mr-1">*</span>
                            </label>
                            <input type="date" name="purchase_date" required 
                                   class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                                   value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                تامین کننده
                            </label>
                            <select class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 bg-gray-50 text-gray-500" disabled>
                                <option>تامین کننده پیش فرض</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">در حال حاضر فقط یک تامین کننده پشتیبانی می‌شود</p>
                        </div>
                    </div>
                </div>

                <!-- بخش آیتم‌های خرید -->
                <div class="mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h4 class="text-lg font-semibold text-gray-800 flex items-center">
                            <i data-feather="shopping-cart" class="ml-2"></i>
                            آیتم‌های خرید
                        </h4>
                        <button type="button" onclick="addPurchaseItem()" 
                                class="flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-all duration-200 shadow-sm hover:shadow-md">
                            <i data-feather="plus" class="ml-2"></i>
                            افزودن آیتم
                        </button>
                    </div>
                    
                    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4">
                        <div class="flex items-start">
                            <i data-feather="info" class="text-yellow-600 ml-2 mt-0.5"></i>
                            <div class="text-sm text-yellow-700">
                                <p class="font-medium">راهنمای ثبت آیتم:</p>
                                <p class="mt-1">• برای هر محصول، نام محصول و قیمت خرید را وارد کنید</p>
                                <p>• سپس رنگ‌ها و سایزهای مربوطه را اضافه کنید</p>
                                <p>• می‌توانید چندین رنگ و سایز برای یک محصول تعریف کنید</p>
                            </div>
                        </div>
                    </div>

                    <div id="purchaseItems" class="space-y-4">
                        <!-- آیتم‌ها اینجا اضافه می‌شوند -->
                    </div>
                </div>

                <!-- خلاصه خرید -->
                <div id="purchaseSummary" class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6 hidden">
                    <h4 class="text-lg font-semibold text-green-800 mb-3 flex items-center">
                        <i data-feather="file-text" class="ml-2"></i>
                        خلاصه خرید
                    </h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
                        <div class="text-center">
                            <p class="text-gray-600">تعداد آیتم‌ها</p>
                            <p id="summaryItemCount" class="text-xl font-bold text-gray-800">0</p>
                        </div>
                        <div class="text-center">
                            <p class="text-gray-600">تعداد کل قطعات</p>
                            <p id="summaryTotalQty" class="text-xl font-bold text-gray-800">0</p>
                        </div>
                        <div class="text-center">
                            <p class="text-gray-600">مبلغ کل خرید</p>
                            <p id="summaryTotalCost" class="text-xl font-bold text-green-600">0 تومان</p>
                        </div>
                    </div>
                </div>

                <!-- دکمه‌های اقدام -->
                <div class="flex justify-end space-x-3 space-x-reverse pt-4 border-t border-gray-200">
                    <button type="button" onclick="closeModal('purchaseModal')" 
                            class="px-6 py-3 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-all duration-200 font-medium flex items-center">
                        <i data-feather="x" class="ml-2"></i>
                        انصراف
                    </button>
                    <button type="submit" id="submitBtn"
                            class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 font-medium flex items-center shadow-sm hover:shadow-md disabled:opacity-50 disabled:cursor-not-allowed">
                        <i data-feather="check" class="ml-2"></i>
                        ثبت خرید
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Detailed Purchases Modal -->
    <div id="detailedPurchasesModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-6xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">جزییات خریدها</h3>
                    <button onclick="closeModal('detailedPurchasesModal')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm" id="detailedPurchasesTable">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">نام محصول</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">رنگ</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">سایز</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">تعداد</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت خرید</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت کل</th>
                                <th class="px-4 py-3 text-right font-medium text-gray-700">تاریخ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <!-- Detailed purchases will be loaded here -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        function toggleDetails(id) {
            const summary = document.getElementById('month-summary-' + id);
            if (!summary) return;
            const details = summary.querySelector('.month-details');
            const icon = summary.querySelector('.toggle-icon');
            if (details.classList.contains('hidden')) {
                details.classList.remove('hidden');
                icon.classList.add('open');
            } else {
                details.classList.add('hidden');
                icon.classList.remove('open');
            }
        }

        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        let itemIndex = 0;

        // تابع بهبود یافته برای افزودن آیتم
        function addPurchaseItem() {
            const itemsContainer = document.getElementById('purchaseItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'purchase-item bg-white p-6 rounded-xl border-2 border-gray-200 hover:border-blue-300 transition-all duration-200 shadow-sm';
            itemDiv.innerHTML = `
                <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                    <h5 class="font-semibold text-gray-700 flex items-center">
                        <i data-feather="package" class="ml-2 text-blue-500"></i>
                        آیتم محصول
                    </h5>
                    <button type="button" onclick="removePurchaseItem(this)" 
                            class="p-2 text-red-500 hover:bg-red-50 rounded-lg transition-colors duration-200"
                            title="حذف آیتم">
                        <i data-feather="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                            نام محصول
                            <span class="text-red-500 mr-1">*</span>
                        </label>
                        <input type="text" name="items[${itemIndex}][product_name]" list="productNames" required 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                               placeholder="مثال: پیراهن مردانه مدل A"
                               onchange="updateItemSummary(${itemIndex})">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 flex items-center">
                            قیمت خرید (تومان)
                            <span class="text-red-500 mr-1">*</span>
                        </label>
                        <input type="number" name="items[${itemIndex}][buy_price]" min="1000" step="1000" required 
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200"
                               placeholder="10000"
                               oninput="updateItemSummary(${itemIndex})">
                    </div>
                </div>

                <div class="mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <label class="block text-sm font-medium text-gray-700 flex items-center">
                            <i data-feather="palette" class="ml-2 text-purple-500"></i>
                            رنگ‌ها و سایزها
                        </label>
                        <button type="button" onclick="addColorSize(${itemIndex})" 
                                class="flex items-center px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600 transition-all duration-200 text-sm font-medium">
                            <i data-feather="plus" class="ml-2"></i>
                            افزودن رنگ
                        </button>
                    </div>
                    
                    <div id="colorSizesContainer-${itemIndex}" class="space-y-4">
                        <!-- رنگ‌ها اینجا اضافه می‌شوند -->
                    </div>
                </div>

                <div id="itemSummary-${itemIndex}" class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                    <div class="flex justify-between items-center">
                        <span class="font-medium text-blue-800">خلاصه این آیتم:</span>
                        <div class="flex space-x-6 space-x-reverse text-sm">
                            <span>تعداد کل: <strong id="totalQty-${itemIndex}" class="text-blue-600">0</strong></span>
                            <span>قیمت کل: <strong id="totalCost-${itemIndex}" class="text-green-600">0</strong> تومان</span>
                        </div>
                    </div>
                </div>
            `;
            itemsContainer.appendChild(itemDiv);
            feather.replace();
            updatePurchaseSummary();
            itemIndex++;
        }

        // تابع بهبود یافته برای خلاصه خرید
        function updatePurchaseSummary() {
            const items = document.querySelectorAll('#purchaseItems > div');
            let totalItems = items.length;
            let totalQty = 0;
            let totalCost = 0;

            items.forEach((item, index) => {
                const qtySpan = document.getElementById(`totalQty-${index}`);
                const costSpan = document.getElementById(`totalCost-${index}`);
                
                if (qtySpan && costSpan) {
                    totalQty += parseInt(qtySpan.textContent) || 0;
                    totalCost += parseInt(costSpan.textContent.replace(/,/g, '')) || 0;
                }
            });

            const summaryDiv = document.getElementById('purchaseSummary');
            const submitBtn = document.getElementById('submitBtn');

            if (totalQty > 0) {
                summaryDiv.classList.remove('hidden');
                document.getElementById('summaryItemCount').textContent = totalItems;
                document.getElementById('summaryTotalQty').textContent = totalQty.toLocaleString();
                document.getElementById('summaryTotalCost').textContent = totalCost.toLocaleString() + ' تومان';
                submitBtn.disabled = false;
            } else {
                summaryDiv.classList.add('hidden');
                submitBtn.disabled = true;
            }
        }

        // تابع بهبود یافته برای افزودن رنگ
        function addColorSize(itemIndex) {
            const container = document.getElementById(`colorSizesContainer-${itemIndex}`);
            const colorIndex = container.children.length;
            const colorDiv = document.createElement('div');
            colorDiv.className = 'bg-gray-50 p-4 rounded-lg border border-gray-200';
            colorDiv.innerHTML = `
                <div class="flex justify-between items-center mb-4">
                    <h6 class="font-medium text-gray-700 flex items-center">
                        <i data-feather="droplet" class="ml-2 text-pink-500"></i>
                        رنگ جدید
                    </h6>
                    <button type="button" onclick="removeColorSize(this, ${itemIndex})" 
                            class="p-2 text-red-500 hover:bg-red-50 rounded-lg transition-colors duration-200"
                            title="حذف رنگ">
                        <i data-feather="x" class="w-4 h-4"></i>
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">رنگ</label>
                        <input type="text" name="items[${itemIndex}][color_sizes][${colorIndex}][color]" 
                               list="colorNames" required 
                               class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                               placeholder="مثال: آبی">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">عملیات سایزها</label>
                        <button type="button" onclick="addSize(${itemIndex}, ${colorIndex})" 
                                class="w-full px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 transition-all duration-200 text-sm font-medium">
                            <i data-feather="plus" class="ml-2"></i>
                            افزودن سایز
                        </button>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table id="sizesTable-${itemIndex}-${colorIndex}" class="w-full text-sm border-collapse border border-gray-300 rounded-lg overflow-hidden">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="border border-gray-300 px-3 py-2 text-right font-medium">سایز</th>
                                <th class="border border-gray-300 px-3 py-2 text-right font-medium">تعداد</th>
                                <th class="border border-gray-300 px-3 py-2 text-center font-medium">عملیات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- سایزها اینجا اضافه می‌شوند -->
                        </tbody>
                    </table>
                </div>
            `;
            container.appendChild(colorDiv);
            feather.replace();
            updateItemSummary(itemIndex);
        }

        // تابع کاملاً جدید برای افزودن سایز با قابلیت انتخاب چندگانه
        function addSize(itemIndex, colorIndex) {
            const tbody = document.querySelector(`#sizesTable-${itemIndex}-${colorIndex} tbody`);
            
            // ایجاد ردیف برای نمایش سایزهای قابل انتخاب
            const selectorRow = document.createElement('tr');
            selectorRow.className = 'bg-blue-50';
            selectorRow.innerHTML = `
                <td colspan="3" class="border border-gray-300 px-3 py-3">
                    <div class="flex flex-wrap gap-2 items-center">
                        <span class="text-sm font-medium text-gray-700">انتخاب سایز:</span>
                        ${['S', 'M', 'L', 'XL', 'XXL', 'XXXL'].map(size => `
                            <button type="button" 
                                    onclick="selectSize('${size}', ${itemIndex}, ${colorIndex})"
                                    class="size-selector-btn px-3 py-1 bg-white border border-gray-300 rounded-lg hover:bg-blue-50 hover:border-blue-300 transition-all duration-200 text-sm">
                                ${size}
                            </button>
                        `).join('')}
                    </div>
                </td>
            `;
            
            // اگر اولین سایز است، ردیف انتخابگر را اضافه کن
            if (tbody.children.length === 0) {
                tbody.appendChild(selectorRow);
            }
        }

        // تابع جدید برای انتخاب سایز
        function selectSize(size, itemIndex, colorIndex) {
            const tbody = document.querySelector(`#sizesTable-${itemIndex}-${colorIndex} tbody`);
            
            // بررسی آیا این سایز قبلاً اضافه شده است
            const existingSizeRow = Array.from(tbody.querySelectorAll('tr[size-data]')).find(tr => 
                tr.querySelector('input[name*="size"]').value === size
            );
            
            if (existingSizeRow) {
                // اگر سایز موجود است، تعداد آن را افزایش بده
                increaseExistingSize(existingSizeRow, itemIndex);
                return;
            }
            
            // ایجاد ردیف جدید برای سایز انتخاب شده
            const sizeIndex = Array.from(tbody.querySelectorAll('tr[size-data]')).length;
            const sizeRow = document.createElement('tr');
            sizeRow.setAttribute('size-data', 'true');
            sizeRow.className = 'hover:bg-gray-50 transition-colors duration-150';
            sizeRow.innerHTML = `
                <td class="border border-gray-300 px-3 py-2">
                    <input type="hidden" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][${sizeIndex}][size]" value="${size}">
                    <div class="flex items-center justify-between">
                        <span class="font-medium text-gray-700">${size}</span>
                        <button type="button" onclick="removeSize(this, ${itemIndex})" 
                                class="p-1 text-red-500 hover:bg-red-50 rounded transition-colors duration-200"
                                title="حذف این سایز">
                            <i data-feather="x" class="w-3 h-3"></i>
                        </button>
                    </div>
                </td>
                <td class="border border-gray-300 px-3 py-2">
                    <div class="flex items-center justify-center gap-3">
                        <button type="button" onclick="decreaseQty(this, ${itemIndex})" 
                                class="w-8 h-8 flex items-center justify-center bg-red-100 text-red-600 rounded-lg hover:bg-red-200 transition-colors duration-200">
                            <i data-feather="minus" class="w-3 h-3"></i>
                        </button>
                        
                        <div class="relative">
                            <input type="number" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][${sizeIndex}][quantity]" 
                                   value="1" min="1" 
                                   class="quantity-input w-20 text-center border border-gray-300 rounded-lg py-2 font-medium bg-white"
                                   onchange="updateItemSummary(${itemIndex})"
                                   ondblclick="this.select()">
                            <div class="absolute inset-y-0 left-0 flex items-center">
                                <button type="button" onclick="quickIncrease(this, ${itemIndex})" 
                                        class="h-full px-2 text-blue-600 hover:bg-blue-50 rounded-l-lg transition-colors">
                                    <i data-feather="plus" class="w-3 h-3"></i>
                                </button>
                            </div>
                        </div>
                        
                        <button type="button" onclick="increaseQty(this, ${itemIndex})" 
                                class="w-8 h-8 flex items-center justify-center bg-green-100 text-green-600 rounded-lg hover:bg-green-200 transition-colors duration-200">
                            <i data-feather="plus" class="w-3 h-3"></i>
                        </button>
                    </div>
                </td>
                <td class="border border-gray-300 px-3 py-2 text-center">
                    <div class="flex justify-center space-x-1 space-x-reverse">
                        <button type="button" onclick="addMultiple(this, ${itemIndex}, 5)" 
                                class="quick-action-btn p-1 text-blue-500 hover:bg-blue-50 rounded transition-colors duration-200 text-xs"
                                title="افزودن 5 عدد">
                            +5
                        </button>
                        <button type="button" onclick="addMultiple(this, ${itemIndex}, 10)" 
                                class="quick-action-btn p-1 text-green-500 hover:bg-green-50 rounded transition-colors duration-200 text-xs"
                                title="افزودن 10 عدد">
                            +10
                        </button>
                    </div>
                </td>
            `;
            
            // اضافه کردن ردیف سایز قبل از ردیف انتخابگر
            const selectorRow = tbody.querySelector('tr:not([size-data])');
            tbody.insertBefore(sizeRow, selectorRow);
            
            feather.replace();
            updateItemSummary(itemIndex);
            
            // انیمیشن برای تأیید انتخاب
            sizeRow.animate([
                { backgroundColor: 'rgba(34, 197, 94, 0.2)' },
                { backgroundColor: 'transparent' }
            ], {
                duration: 1000,
                easing: 'ease-out'
            });
        }

        // تابع برای افزایش سایز موجود
        function increaseExistingSize(sizeRow, itemIndex) {
            const input = sizeRow.querySelector('input[type="number"]');
            input.value = parseInt(input.value) + 1;
            
            // انیمیشن برای تأیید افزایش
            input.animate([
                { transform: 'scale(1.1)' },
                { transform: 'scale(1)' }
            ], {
                duration: 300,
                easing: 'ease-out'
            });
            
            updateItemSummary(itemIndex);
        }

        // توابع جدید برای مدیریت سریع تعداد
        function quickIncrease(button, itemIndex) {
            const input = button.closest('td').querySelector('input[type="number"]');
            input.value = parseInt(input.value) + 1;
            updateItemSummary(itemIndex);
        }

        function addMultiple(button, itemIndex, amount) {
            const input = button.closest('tr').querySelector('input[type="number"]');
            input.value = parseInt(input.value) + amount;
            
            // انیمیشن برای تأیید افزودن سریع
            input.animate([
                { backgroundColor: 'rgba(34, 197, 94, 0.3)' },
                { backgroundColor: 'white' }
            ], {
                duration: 500,
                easing: 'ease-out'
            });
            
            updateItemSummary(itemIndex);
        }

        // تابع بهبود یافته برای افزایش تعداد
        function increaseQty(button, itemIndex) {
            const input = button.closest('td').querySelector('input[type="number"]');
            input.value = parseInt(input.value) + 1;
            
            // انیمیشن تأیید
            button.animate([
                { transform: 'scale(1.1)' },
                { transform: 'scale(1)' }
            ], {
                duration: 200,
                easing: 'ease-out'
            });
            
            updateItemSummary(itemIndex);
        }

        // تابع بهبود یافته برای کاهش تعداد
        function decreaseQty(button, itemIndex) {
            const input = button.closest('td').querySelector('input[type="number"]');
            const currentValue = parseInt(input.value);
            
            if (currentValue > 1) {
                input.value = currentValue - 1;
                
                // انیمیشن تأیید
                button.animate([
                    { transform: 'scale(1.1)' },
                    { transform: 'scale(1)' }
                ], {
                    duration: 200,
                    easing: 'ease-out'
                });
            } else {
                // اگر تعداد به 1 رسید، سایز را حذف کن
                removeSize(button, itemIndex);
                return;
            }
            
            updateItemSummary(itemIndex);
        }

        // تابع بهبود یافته برای حذف سایز
        function removeSize(button, itemIndex) {
            const sizeRow = button.closest('tr[size-data]');
            if (sizeRow) {
                // انیمیشن حذف
                sizeRow.animate([
                    { opacity: 1, transform: 'translateX(0)' },
                    { opacity: 0, transform: 'translateX(20px)' }
                ], {
                    duration: 300,
                    easing: 'ease-in'
                }).onfinish = () => {
                    sizeRow.remove();
                    updateItemSummary(itemIndex);
                    reindexSizes(itemIndex);
                };
            }
        }

        // تابع برای بازنشانی ایندکس‌های سایزها پس از حذف
        function reindexSizes(itemIndex) {
            const colorContainers = document.querySelectorAll(`[id^="colorSizesContainer-${itemIndex}"] > div`);
            
            colorContainers.forEach((colorDiv, colorIndex) => {
                const sizeRows = colorDiv.querySelectorAll('tr[size-data]');
                sizeRows.forEach((row, sizeIndex) => {
                    // به‌روزرسانی نام فیلدها
                    const sizeInput = row.querySelector('input[name*="size"]');
                    const qtyInput = row.querySelector('input[name*="quantity"]');
                    
                    if (sizeInput && qtyInput) {
                        sizeInput.name = `items[${itemIndex}][color_sizes][${colorIndex}][sizes][${sizeIndex}][size]`;
                        qtyInput.name = `items[${itemIndex}][color_sizes][${colorIndex}][sizes][${sizeIndex}][quantity]`;
                    }
                });
            });
        }

        // تابع بهبود یافته برای محاسبه خلاصه
        function updateItemSummary(itemIndex) {
            const itemDiv = document.querySelector(`#purchaseItems > div:nth-child(${itemIndex + 1})`);
            if (!itemDiv) return;
            
            const buyPriceInput = itemDiv.querySelector(`input[name="items[${itemIndex}][buy_price]"]`);
            const buyPrice = parseFloat(buyPriceInput?.value) || 0;

            let totalQty = 0;
            const sizeInputs = itemDiv.querySelectorAll('input[name*="quantity"]');
            
            sizeInputs.forEach(input => {
                totalQty += parseInt(input.value) || 0;
            });

            const totalCost = totalQty * buyPrice;

            const summaryDiv = document.getElementById(`itemSummary-${itemIndex}`);
            const totalQtySpan = document.getElementById(`totalQty-${itemIndex}`);
            const totalCostSpan = document.getElementById(`totalCost-${itemIndex}`);

            if (totalQty > 0 && summaryDiv && totalQtySpan && totalCostSpan) {
                totalQtySpan.textContent = totalQty.toLocaleString();
                totalCostSpan.textContent = totalCost.toLocaleString();
                summaryDiv.classList.remove('hidden');
                
                // انیمیشن برای تغییرات
                totalQtySpan.animate([
                    { transform: 'scale(1.1)' },
                    { transform: 'scale(1)' }
                ], {
                    duration: 300,
                    easing: 'ease-out'
                });
            } else if (summaryDiv) {
                summaryDiv.classList.add('hidden');
            }
            
            // به‌روزرسانی خلاصه کلی خرید
            updatePurchaseSummary();
        }

        function removeColorSize(button, itemIndex) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این رنگ را حذف کنید؟')) {
                button.parentElement.remove();
                updateItemSummary(itemIndex);
            }
        }

        function removePurchaseItem(button) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این آیتم را حذف کنید؟')) {
                button.parentElement.remove();
                updatePurchaseSummary();
            }
        }

        function showDetailedPurchases(purchaseDate, modelName, color) {
            const url = `get_all_purchase_items.php?purchase_date=${encodeURIComponent(purchaseDate)}&model_name=${encodeURIComponent(modelName)}&color=${encodeURIComponent(color)}`;
            fetch(url)
                .then(response => response.json())
                .then(data => {
                    const tbody = document.getElementById('detailedPurchasesTable').querySelector('tbody');
                    tbody.innerHTML = '';
                    data.forEach(item => {
                        const row = `<tr>
                            <td class="px-4 py-3 text-gray-800">${item.model_name}</td>
                            <td class="px-4 py-3 text-gray-800">${item.color}</td>
                            <td class="px-4 py-3 text-gray-800">${item.size}</td>
                            <td class="px-4 py-3 text-gray-800">${item.quantity}</td>
                            <td class="px-4 py-3 text-gray-800">${item.buy_price} تومان</td>
                            <td class="px-4 py-3 text-gray-800">${item.total_amount} تومان</td>
                            <td class="px-4 py-3 text-gray-800">${item.purchase_date}</td>
                        </tr>`;
                        tbody.innerHTML += row;
                    });
                    openModal('detailedPurchasesModal');
                })
                .catch(error => console.error('Error loading detailed purchases:', error));
        }

        // Initialize with one item and load data
        document.addEventListener('DOMContentLoaded', function() {
            addPurchaseItem();
            // Load product names
            fetch('get_product_names.php')
                .then(response => response.json())
                .then(data => {
                    const datalist = document.getElementById('productNames');
                    data.forEach(name => {
                        const option = document.createElement('option');
                        option.value = name;
                        datalist.appendChild(option);
                    });
                })
                .catch(error => console.error('Error loading product names:', error));
            // Load colors
            fetch('get_product_colors.php')
                .then(response => response.json())
                .then(data => {
                    const datalist = document.getElementById('colorNames');
                    data.forEach(color => {
                        const option = document.createElement('option');
                        option.value = color;
                        datalist.appendChild(option);
                    });
                })
                .catch(error => console.error('Error loading colors:', error));
        });
    </script>
    <datalist id="productNames"></datalist>
    <datalist id="colorNames"></datalist>
</body>
</html>