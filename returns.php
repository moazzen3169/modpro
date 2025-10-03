<?php
require_once __DIR__ . '/env/bootstrap.php';

function handle_create_return(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $customer_id = validate_int($_POST['customer_id'] ?? 0, 0);
        $return_date = validate_date((string)($_POST['return_date'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));

        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || $raw_items === []) {
            throw new InvalidArgumentException('برای ثبت مرجوعی حداقل یک آیتم لازم است.');
        }

        $items = [];
        foreach ($raw_items as $item) {
            $variant_id = validate_int($item['variant_id'] ?? null, 1);
            $quantity = validate_int($item['quantity'] ?? null, 1);
            $items[] = ['variant_id' => $variant_id, 'quantity' => $quantity];
        }

        $insertReturnStmt = $conn->prepare('INSERT INTO Returns (customer_id, return_date, reason) VALUES (?, ?, ?)');
        $insertReturnStmt->bind_param('iss', $customer_id, $return_date, $reason);
        $insertReturnStmt->execute();
        $return_id = (int) $conn->insert_id;

        $variantStmt = $conn->prepare('SELECT price FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $insertItemStmt = $conn->prepare('INSERT INTO Return_Items (return_id, variant_id, quantity, return_price) VALUES (?, ?, ?, ?)');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');

        foreach ($items as $item) {
            $variant_id = $item['variant_id'];
            $quantity = $item['quantity'];

            $variantStmt->bind_param('i', $variant_id);
            $variantStmt->execute();
            $variant = $variantStmt->get_result()->fetch_assoc();
            if (!$variant) {
                throw new RuntimeException('تنوع انتخاب‌شده وجود ندارد.');
            }

            $price = (float) $variant['price'];

            $insertItemStmt->bind_param('iiid', $return_id, $variant_id, $quantity, $price);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('ii', $quantity, $variant_id);
            $updateStockStmt->execute();
        }

        $conn->commit();
        redirect_with_message('returns.php', 'success', 'مرجوعی جدید با موفقیت ثبت شد و موجودی به‌روزرسانی گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('returns.php', 'error', normalize_error_message($e));
    }
}

function handle_delete_return(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $return_id = validate_int($_GET['delete_return'] ?? null, 1);

        $returnStmt = $conn->prepare('SELECT return_id FROM Returns WHERE return_id = ? FOR UPDATE');
        $returnStmt->bind_param('i', $return_id);
        $returnStmt->execute();
        $returnExists = $returnStmt->get_result()->fetch_assoc();
        if (!$returnExists) {
            throw new RuntimeException('مرجوعی موردنظر یافت نشد.');
        }

        $itemsStmt = $conn->prepare('SELECT variant_id, quantity FROM Return_Items WHERE return_id = ? FOR UPDATE');
        $itemsStmt->bind_param('i', $return_id);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();

        $decreaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
        while ($item = $itemsResult->fetch_assoc()) {
            $quantity = (int) $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $variant_id = (int) $item['variant_id'];
            $decreaseStmt->bind_param('ii', $quantity, $variant_id);
            $decreaseStmt->execute();
        }

        $deleteItemsStmt = $conn->prepare('DELETE FROM Return_Items WHERE return_id = ?');
        $deleteItemsStmt->bind_param('i', $return_id);
        $deleteItemsStmt->execute();

        $deleteReturnStmt = $conn->prepare('DELETE FROM Returns WHERE return_id = ?');
        $deleteReturnStmt->bind_param('i', $return_id);
        $deleteReturnStmt->execute();

        $conn->commit();
        redirect_with_message('returns.php', 'success', 'مرجوعی حذف شد و موجودی اصلاح گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('returns.php', 'error', normalize_error_message($e));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_return'])) {
    handle_create_return($conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['delete_return'])) {
    handle_delete_return($conn);
}

$flash_messages = get_flash_messages();

$products = $conn->query('SELECT * FROM Products ORDER BY model_name');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت مرجوعی‌ها - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        .status-badge {
            transition: all 0.2s;
        }
        .status-badge:hover {
            opacity: 0.9;
        }
        .return-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Animation for modals */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-fade {
            animation: fadeIn 0.2s ease-out;
        }

        .modal-slide {
            animation: slideIn 0.3s ease-out;
        }

        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Color selection styles */
        .color-option {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            background-color: white;
            color: #374151;
        }

        .color-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            color: #3b82f6;
            font-weight: 600;
        }

        .color-option:hover {
            border-color: #3b82f6;
        }

        .size-option {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }

        .size-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            color: #3b82f6;
            font-weight: 600;
        }

        .size-option:hover {
            border-color: #3b82f6;
        }

        .size-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #f9fafb;
        }

        .size-option.disabled:hover {
            border-color: #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-l border-gray-200 flex flex-col shadow-sm">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 ml-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    SuitStore Pro
                </h1>
            </div>
            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="index.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="home" class="ml-2 w-5 h-5"></i>
                            <span>داشبورد</span>
                        </a>
                    </li>
                    <li>
                        <a href="products.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="package" class="ml-2 w-5 h-5"></i>
                            <span>محصولات</span>
                        </a>
                    </li>
                    <li>
                        <a href="sales.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="shopping-cart" class="ml-2 w-5 h-5"></i>
                            <span>فروش‌ها</span>
                        </a>
                    </li>
                    <li>
                        <a href="purchases.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="shopping-bag" class="ml-2 w-5 h-5"></i>
                            <span>خریدها</span>
                        </a>
                    </li>
                    <li>
                        <a href="returns.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-700 rounded-lg border-r-2 border-blue-500">
                            <i data-feather="refresh-ccw" class="ml-2 w-5 h-5"></i>
                            <span>مرجوعی‌ها</span>
                        </a>
                    </li>
                </ul>
            </nav>
            <div class="p-4 border-t border-gray-200">
                <a href="logout.php" class="flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition">
                    <i data-feather="log-out" class="ml-2 w-4 h-4"></i>
                    خروج از حساب
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center shadow-sm">
                <h2 class="text-xl font-semibold text-gray-800">مدیریت مرجوعی‌ها</h2>
                <div class="flex items-center space-x-4 space-x-reverse">
                    <button onclick="openModal('newReturnModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-md">
                        <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                        <span>مرجوعی جدید</span>
                    </button>
                </div>
            </header>

            <!-- Returns Content -->
            <main class="p-6">
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

                <!-- Returns List -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden">
                    <table id="returnsTable" class="w-full text-sm text-gray-900">
                        <thead class="bg-gradient-to-r from-blue-50 to-indigo-50 border-b border-gray-300">
                            <tr>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">شماره مرجوعی</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">مشتری</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تاریخ</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">دلیل</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تعداد آیتم</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">مجموع</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php
$returns = $conn->query("SELECT r.*, 'مشتری حضوری' as customer_name, COUNT(ri.return_item_id) as item_count, SUM(ri.quantity * ri.return_price) as total_amount FROM Returns r LEFT JOIN Return_Items ri ON r.return_id = ri.return_id GROUP BY r.return_id ORDER BY r.return_date DESC, r.return_id DESC");

                            if ($returns->num_rows > 0) {
                                while($return = $returns->fetch_assoc()){
                                    $return_id = (int) $return['return_id'];
                                    $customer_name = htmlspecialchars($return['customer_name'] ?: 'مشتری حضوری', ENT_QUOTES, 'UTF-8');
                                    $return_date = htmlspecialchars((string) $return['return_date'], ENT_QUOTES, 'UTF-8');
                                    $reason = htmlspecialchars((string) ($return['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $item_count = (int) $return['item_count'];
                                    $total_amount = number_format((float) ($return['total_amount'] ?? 0), 0);

                                    echo "<tr class='hover:bg-gray-50'>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>#مرجوعی-{$return_id}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>{$customer_name}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>{$return_date}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>{$reason}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>{$item_count}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900'>{$total_amount} تومان</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm font-medium'>
                                                <div class='flex space-x-2 space-x-reverse'>
                                                    <button onclick='showReturnItems({$return_id})' class='p-1 bg-blue-100 rounded text-blue-600 hover:bg-blue-200 transition-colors' title='مشاهده آیتم‌ها'>
                                                        <i data-feather='eye' class='w-4 h-4'></i>
                                                    </button>
                                                    <a href='?delete_return={$return_id}' onclick='return confirm(\"آیا مطمئن هستید که می‌خواهید این مرجوعی را حذف کنید؟\")' class='p-1 bg-red-100 rounded text-red-600 hover:bg-red-200 transition-colors' title='حذف'>
                                                        <i data-feather='trash-2' class='w-4 h-4'></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>";
                                }
                            } else {
                                echo "<tr><td colspan='7' class='px-6 py-8 text-center'>
                                        <i data-feather='refresh-ccw' class='w-12 h-12 text-gray-400 mx-auto mb-4'></i>
                                        <h3 class='text-lg font-medium text-gray-700 mb-2'>هنوز مرجوعی‌ای ثبت نشده است</h3>
                                        <p class='text-gray-500 mb-4'>برای شروع، اولین مرجوعی خود را ثبت کنید</p>
                                        <button onclick='openModal(\"newReturnModal\")' class='px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors'>
                                            ایجاد اولین مرجوعی
                                        </button>
                                    </td></tr>";
                            }
                            ?>
                        </tbody>
                    </table>
                </div>

                <!-- New Return Modal -->
                <div id="newReturnModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">مرجوعی جدید</h3>
                                <button onclick="closeModal('newReturnModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>

                            <form method="POST" id="newReturnForm">
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                    <div class="lg:col-span-2">
                                        <h4 class="font-medium text-gray-700 mb-3">انتخاب محصولات</h4>

                                        <!-- Selected Items -->
                                        <div id="selectedReturnItems" class="space-y-3 mb-4 max-h-60 overflow-y-auto p-2">
                                            <!-- Items will be added here dynamically -->
                                        </div>

                                        <!-- Add Product Form -->
                                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                            <div class="space-y-4">
                                                <!-- Product Selection -->
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب محصول</label>
                                                    <select id="returnProductSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="">انتخاب محصول...</option>
                                                        <?php
                                                        while($product = $products->fetch_assoc()){
                                                            $product_id = (int) $product['product_id'];
                                                            $product_name = htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8');
                                                            echo "<option value='{$product_id}'>{$product_name}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <!-- Color Selection -->
                                                <div id="returnColorSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب رنگ</label>
                                                    <div id="returnColorOptions" class="flex flex-wrap gap-3">
                                                        <!-- Color options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Size Selection -->
                                                <div id="returnSizeSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب سایز</label>
                                                    <div id="returnSizeOptions" class="grid grid-cols-6 gap-2">
                                                        <!-- Size options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Quantity and Add Button -->
                                                <div id="returnQuantitySelection" class="hidden">
                                                    <div class="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                                            <input type="number" id="returnQuantityInput" min="1" value="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        </div>
                                                        <div class="flex items-end">
                                                            <button type="button" onclick="addItemToReturn()" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                                                                افزودن به مرجوعی
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div id="returnStockInfo" class="mt-2 text-sm text-gray-500">
                                                        <!-- Stock info will be shown here -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium text-gray-700 mb-3">جزئیات مرجوعی</h4>
                                        <div class="bg-gray-50 p-4 rounded-lg">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">مشتری</label>
                                                    <select name="customer_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="0">مشتری حضوری</option>
                                                        <?php
                                                        $customers = $conn->query("SELECT * FROM Customers ORDER BY name");
                                                        while($customer = $customers->fetch_assoc()){
                                                            echo "<option value='{$customer['customer_id']}'>{$customer['name']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ مرجوعی</label>
                                                    <input type="date" name="return_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">دلیل مرجوعی</label>
                                                    <textarea name="reason" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="دلیل مرجوعی را وارد کنید..."></textarea>
                                                </div>

                                                <div class="pt-4 border-t border-gray-200">
                                                    <div class="flex justify-between mb-2">
                                                        <span class="text-gray-600">مجموع</span>
                                                        <span id="returnSubtotal" class="font-medium">0 تومان</span>
                                                    </div>
                                                    <div class="flex justify-between font-bold text-lg mt-3 pt-3 border-t border-gray-200">
                                                        <span>مجموع کل</span>
                                                        <span id="returnTotal" class="text-blue-600">0 تومان</span>
                                                    </div>
                                                </div>

                                                <button type="submit" name="create_return" onclick="return validateReturnForm()" class="w-full bg-blue-500 text-white py-3 rounded-lg hover:bg-blue-600 transition-colors mt-4 flex items-center justify-center">
                                                    <i data-feather="check" class="ml-2 w-5 h-5"></i>
                                                    تکمیل مرجوعی
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden inputs for return items -->
                                <div id="returnItemsInputs">
                                    <!-- Return item inputs will be added here -->
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Return Items Modal -->
                <div id="returnItemsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">آیتم‌های مرجوعی</h3>
                                <button onclick="closeModal('returnItemsModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <div id="returnItemsContent">
                                <!-- Return items will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        feather.replace();

        let returnItems = [];
        let returnItemCounter = 0;
        let selectedReturnProductId = null;
        let selectedReturnColor = null;
        let selectedReturnSize = null;
        let currentReturnVariants = [];

        function openModal(modalId) {
            if (modalId === 'newReturnModal') {
                resetNewReturnModal();
            }
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function resetNewReturnModal() {
            returnItems = [];
            returnItemCounter = 0;
            selectedReturnProductId = null;
            selectedReturnColor = null;
            selectedReturnSize = null;
            currentReturnVariants = [];

            document.getElementById('selectedReturnItems').innerHTML = '';
            document.getElementById('returnItemsInputs').innerHTML = '';
            document.getElementById('returnSubtotal').textContent = '0 تومان';
            document.getElementById('returnTotal').textContent = '0 تومان';
            document.getElementById('returnProductSelect').value = '';
            document.getElementById('returnQuantityInput').value = '1';

            // Hide selection sections
            document.getElementById('returnColorSelection').classList.add('hidden');
            document.getElementById('returnSizeSelection').classList.add('hidden');
            document.getElementById('returnQuantitySelection').classList.add('hidden');
        }

        // When product is selected
        document.getElementById('returnProductSelect').addEventListener('change', function() {
            const productId = this.value;

            if (!productId) {
                document.getElementById('returnColorSelection').classList.add('hidden');
                document.getElementById('returnSizeSelection').classList.add('hidden');
                document.getElementById('returnQuantitySelection').classList.add('hidden');
                return;
            }

            selectedReturnProductId = productId;
            selectedReturnColor = null;
            selectedReturnSize = null;

            // Load colors for this product
            loadReturnColors(productId);
        });

        function loadReturnColors(productId) {
            // Show loading state
            document.getElementById('returnColorOptions').innerHTML = '<div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span>';
            document.getElementById('returnColorSelection').classList.remove('hidden');
            document.getElementById('returnSizeSelection').classList.add('hidden');
            document.getElementById('returnQuantitySelection').classList.add('hidden');

            // Fetch colors from server
            fetch('get_product_colors.php?product_id=' + productId)
                .then(response => response.json())
                .then(data => {
                    currentReturnVariants = data.variants;
                    const colorOptions = document.getElementById('returnColorOptions');
                    colorOptions.innerHTML = '';

                    if (data.colors.length === 0) {
                        colorOptions.innerHTML = '<p class="text-gray-500">هیچ رنگی برای این محصول موجود نیست</p>';
                        return;
                    }

                    data.colors.forEach(color => {
                        const colorOption = document.createElement('div');
                        colorOption.className = 'color-option';
                        colorOption.textContent = color.color_name || color.color;
                        colorOption.setAttribute('data-color', color.color);

                        colorOption.addEventListener('click', function() {
                            selectReturnColor(color.color);
                        });

                        colorOptions.appendChild(colorOption);
                    });
                })
                .catch(error => {
                    console.error('Error loading colors:', error);
                    document.getElementById('returnColorOptions').innerHTML = '<p class="text-red-500">خطا در بارگذاری رنگ‌ها</p>';
                });
        }

        function selectReturnColor(color) {
            selectedReturnColor = color;
            selectedReturnSize = null;

            // Update UI - mark selected color
            document.querySelectorAll('#returnColorOptions .color-option').forEach(option => {
                if (option.getAttribute('data-color') === color) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });

            // Load sizes for selected color
            loadReturnSizes(selectedReturnProductId, color);
        }

        function loadReturnSizes(productId, color) {
            // Show loading state
            document.getElementById('returnSizeOptions').innerHTML = '<div class="col-span-6 flex justify-center"><div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span></div>';
            document.getElementById('returnSizeSelection').classList.remove('hidden');
            document.getElementById('returnQuantitySelection').classList.add('hidden');

            // Filter variants by product and color
            const availableSizes = [];
            const sizeMap = {};

            currentReturnVariants.forEach(variant => {
                if (variant.product_id == productId && variant.color === color) {
                    if (!sizeMap[variant.size]) {
                        sizeMap[variant.size] = {
                            size: variant.size,
                            stock: variant.stock,
                            price: variant.price,
                            variant_id: variant.variant_id
                        };
                    }
                }
            });

            // Convert to array and sort by size order
            Object.values(sizeMap).forEach(sizeInfo => {
                availableSizes.push(sizeInfo);
            });

            // Sort sizes in logical order
            const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
            availableSizes.sort((a, b) => sizeOrder.indexOf(a.size) - sizeOrder.indexOf(b.size));

            // Display sizes
            const sizeOptions = document.getElementById('returnSizeOptions');
            sizeOptions.innerHTML = '';

            if (availableSizes.length === 0) {
                sizeOptions.innerHTML = '<p class="col-span-6 text-gray-500 text-center py-4">هیچ سایزی برای این رنگ موجود نیست</p>';
                return;
            }

            // Only show available sizes
            availableSizes.forEach(sizeInfo => {
                const sizeOption = document.createElement('div');
                sizeOption.className = 'size-option';
                sizeOption.textContent = sizeInfo.size;
                sizeOption.setAttribute('data-size', sizeInfo.size);
                sizeOption.setAttribute('data-stock', sizeInfo.stock);
                sizeOption.setAttribute('data-price', sizeInfo.price);
                sizeOption.setAttribute('data-variant-id', sizeInfo.variant_id);

                // Add stock indicator
                const stockIndicator = document.createElement('div');
                stockIndicator.className = 'text-xs text-gray-500 mt-1';
                stockIndicator.textContent = `موجودی: ${sizeInfo.stock}`;
                sizeOption.appendChild(stockIndicator);

                sizeOption.addEventListener('click', function() {
                    selectReturnSize(sizeInfo.size, sizeInfo.stock, sizeInfo.price, sizeInfo.variant_id);
                });

                sizeOptions.appendChild(sizeOption);
            });
        }

        function selectReturnSize(size, stock, price, variantId) {
            selectedReturnSize = size;

            // Update UI - mark selected size
            document.querySelectorAll('#returnSizeOptions .size-option').forEach(option => {
                if (option.getAttribute('data-size') === size) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });

            // Show quantity selection
            document.getElementById('returnQuantitySelection').classList.remove('hidden');
            document.getElementById('returnQuantityInput').max = stock;
            document.getElementById('returnQuantityInput').value = '1';

            // Update stock info
            document.getElementById('returnStockInfo').innerHTML = `
                <div class="flex justify-between">
                    <span>موجودی:</span>
                    <span class="font-medium">${stock} عدد</span>
                </div>
                <div class="flex justify-between">
                    <span>قیمت واحد:</span>
                    <span class="font-medium">${price.toLocaleString()} تومان</span>
                </div>
            `;

            // Store current variant info
            currentReturnVariantInfo = {
                variantId: variantId,
                price: price,
                stock: stock
            };
        }

        function addItemToReturn() {
            const quantityInput = document.getElementById('returnQuantityInput');
            const quantity = parseInt(quantityInput.value);

            if (!selectedReturnProductId || !selectedReturnColor || !selectedReturnSize) {
                alert('لطفا محصول، رنگ و سایز را انتخاب کنید.');
                return;
            }

            if (!quantity || quantity < 1) {
                alert('لطفا تعداد معتبر وارد کنید.');
                return;
            }

            if (quantity > currentReturnVariantInfo.stock) {
                alert('تعداد انتخاب شده بیشتر از موجودی است.');
                return;
            }

            // Get product name
            const productSelect = document.getElementById('returnProductSelect');
            const productName = productSelect.options[productSelect.selectedIndex].text;

            // Check if item already exists
            const existingItem = returnItems.find(item => item.variantId === currentReturnVariantInfo.variantId);
            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.total = existingItem.quantity * existingItem.price;
                updateSelectedReturnItemsDisplay();
                updateReturnTotals();
            } else {
                const item = {
                    id: returnItemCounter++,
                    variantId: currentReturnVariantInfo.variantId,
                    productName: `${productName} - ${selectedReturnColor} - ${selectedReturnSize}`,
                    quantity: quantity,
                    price: currentReturnVariantInfo.price,
                    total: quantity * currentReturnVariantInfo.price
                };
                returnItems.push(item);
                addReturnItemToDisplay(item);
            }

            updateReturnTotals();
            updateReturnHiddenInputs();

            // Reset selection
            selectedReturnColor = null;
            selectedReturnSize = null;
            document.getElementById('returnColorSelection').classList.add('hidden');
            document.getElementById('returnSizeSelection').classList.add('hidden');
            document.getElementById('returnQuantitySelection').classList.add('hidden');
            document.querySelectorAll('#returnColorOptions .color-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelectorAll('#returnSizeOptions .size-option').forEach(opt => opt.classList.remove('selected'));
        }

        function addReturnItemToDisplay(item) {
            const selectedItems = document.getElementById('selectedReturnItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-white p-4 rounded-lg border border-gray-200 flex items-center justify-between';
            itemDiv.id = `return-item-${item.id}`;

            itemDiv.innerHTML = `
                <div class="flex-1">
                    <h5 class="font-medium text-gray-800">${item.productName}</h5>
                    <div class="text-sm text-gray-500">تعداد: ${Math.max(0, item.quantity)} × ${item.price.toLocaleString()} تومان</div>
                </div>
                <div class="text-left">
                    <div class="font-bold text-gray-800">${item.total.toLocaleString()} تومان</div>
                </div>
                <button onclick="removeReturnItem(${item.id})" class="text-red-500 hover:text-red-700 mr-2 transition-colors">
                    <i data-feather="trash-2" class="w-4 h-4"></i>
                </button>
            `;

            selectedItems.appendChild(itemDiv);
            feather.replace();
        }

        function removeReturnItem(itemId) {
            returnItems = returnItems.filter(item => item.id !== itemId);
            document.getElementById(`return-item-${itemId}`).remove();
            updateReturnTotals();
            updateReturnHiddenInputs();
        }

        function updateSelectedReturnItemsDisplay() {
            const selectedItems = document.getElementById('selectedReturnItems');
            selectedItems.innerHTML = '';
            returnItems.forEach(item => addReturnItemToDisplay(item));
        }

        function updateReturnTotals() {
            const subtotal = returnItems.reduce((sum, item) => sum + item.total, 0);
            document.getElementById('returnSubtotal').textContent = subtotal.toLocaleString() + ' تومان';
            document.getElementById('returnTotal').textContent = subtotal.toLocaleString() + ' تومان';
        }

        function updateReturnHiddenInputs() {
            const inputsContainer = document.getElementById('returnItemsInputs');
            inputsContainer.innerHTML = '';

            returnItems.forEach((item, index) => {
                inputsContainer.innerHTML += `
                    <input type="hidden" name="items[${index}][variant_id]" value="${item.variantId}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                    <input type="hidden" name="items[${index}][return_price]" value="${item.price}">
                `;
            });
        }

        function validateReturnForm() {
            if (returnItems.length === 0) {
                alert('لطفا حداقل یک محصول به مرجوعی اضافه کنید.');
                return false;
            }
            return true;
        }

        function showReturnItems(returnId) {
            // Show loading state
            document.getElementById('returnItemsContent').innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="spinner"></div>
                    <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                </div>
            `;

            document.getElementById('returnItemsModal').classList.remove('hidden');

            // Load return items via AJAX - need to create get_return_items.php
            fetch('get_return_items.php?return_id=' + returnId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('returnItemsContent').innerHTML = data;
                    feather.replace();
                })
                .catch(error => {
                    console.error('Error loading return items:', error);
                    document.getElementById('returnItemsContent').innerHTML = `
                        <div class="text-center py-8 text-red-500">
                            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                            <p>خطا در بارگذاری آیتم‌های مرجوعی</p>
                        </div>
                    `;
                    feather.replace();
                });
        }
    </script>
</body>
</html>
