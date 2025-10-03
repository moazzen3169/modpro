<?php
require_once __DIR__ . '/env/bootstrap.php';
require_once __DIR__ . '/includes/sales_table_renderer.php';

function handle_create_sale(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $customer_id = 0;
        $sale_date = validate_date((string)($_POST['sale_date'] ?? ''));
        $payment_method = validate_enum((string)($_POST['payment_method'] ?? ''), ['cash', 'credit_card', 'bank_transfer']);
        $status = 'paid';

        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || $raw_items === []) {
            throw new InvalidArgumentException('برای ثبت فروش حداقل یک آیتم لازم است.');
        }

        $items = [];
        foreach ($raw_items as $item) {
            $variant_id = validate_int($item['variant_id'] ?? null, 1);
            $quantity = validate_int($item['quantity'] ?? null, 1);
            $items[] = ['variant_id' => $variant_id, 'quantity' => $quantity];
        }

        $insertSaleStmt = $conn->prepare('INSERT INTO Sales (customer_id, sale_date, payment_method, status) VALUES (?, ?, ?, ?)');
        $insertSaleStmt->bind_param('isss', $customer_id, $sale_date, $payment_method, $status);
        $insertSaleStmt->execute();
        $sale_id = (int) $conn->insert_id;

        $variantStmt = $conn->prepare('SELECT price, stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $insertItemStmt = $conn->prepare('INSERT INTO Sale_Items (sale_id, variant_id, quantity, sell_price) VALUES (?, ?, ?, ?)');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');

        foreach ($items as $item) {
            $variant_id = $item['variant_id'];
            $quantity = $item['quantity'];

            $variantStmt->bind_param('i', $variant_id);
            $variantStmt->execute();
            $variantResult = $variantStmt->get_result();
            $variant = $variantResult->fetch_assoc();

            if (!$variant) {
                throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
            }

            if ((int) $variant['stock'] < $quantity) {
                throw new RuntimeException('موجودی کافی برای برخی آیتم‌ها وجود ندارد.');
            }

            $price = (float) $variant['price'];

            $insertItemStmt->bind_param('iiid', $sale_id, $variant_id, $quantity, $price);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('ii', $quantity, $variant_id);
            $updateStockStmt->execute();
        }

        $conn->commit();
        redirect_with_message('sales.php', 'success', 'فروش جدید با موفقیت ثبت شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

function handle_add_sale_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $sale_id = validate_int($_POST['sale_id'] ?? null, 1);
        $variant_id = validate_int($_POST['variant_id'] ?? null, 1);
        $quantity = validate_int($_POST['quantity'] ?? null, 1);

        $saleCheckStmt = $conn->prepare('SELECT sale_id FROM Sales WHERE sale_id = ? FOR UPDATE');
        $saleCheckStmt->bind_param('i', $sale_id);
        $saleCheckStmt->execute();
        $saleExists = $saleCheckStmt->get_result()->fetch_assoc();
        if (!$saleExists) {
            throw new RuntimeException('فروش انتخاب‌شده وجود ندارد.');
        }

        $variantStmt = $conn->prepare('SELECT price, stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $variantStmt->bind_param('i', $variant_id);
        $variantStmt->execute();
        $variant = $variantStmt->get_result()->fetch_assoc();
        if (!$variant) {
            throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
        }

        if ((int) $variant['stock'] < $quantity) {
            throw new RuntimeException('موجودی کافی برای افزودن این آیتم وجود ندارد.');
        }

        $price = (float) $variant['price'];

        $insertItemStmt = $conn->prepare('INSERT INTO Sale_Items (sale_id, variant_id, quantity, sell_price) VALUES (?, ?, ?, ?)');
        $insertItemStmt->bind_param('iiid', $sale_id, $variant_id, $quantity, $price);
        $insertItemStmt->execute();

        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
        $updateStockStmt->bind_param('ii', $quantity, $variant_id);
        $updateStockStmt->execute();

        $conn->commit();
        redirect_with_message('sales.php', 'success', 'آیتم جدید با موفقیت به فروش اضافه شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

function handle_edit_sale(mysqli $conn): void
{
    try {
        $sale_id = validate_int($_POST['sale_id'] ?? null, 1);
        $customer_id = validate_int($_POST['customer_id'] ?? 0, 0);
        $sale_date = validate_date((string)($_POST['sale_date'] ?? ''));
        $payment_method = validate_enum((string)($_POST['payment_method'] ?? ''), ['cash', 'credit_card', 'bank_transfer']);
        $status = validate_enum((string)($_POST['status'] ?? 'pending'), ['pending', 'paid']);

        $updateStmt = $conn->prepare('UPDATE Sales SET customer_id = ?, sale_date = ?, payment_method = ?, status = ? WHERE sale_id = ?');
        $updateStmt->bind_param('isssi', $customer_id, $sale_date, $payment_method, $status, $sale_id);
        $updateStmt->execute();

        redirect_with_message('sales.php', 'success', 'اطلاعات فروش با موفقیت به‌روزرسانی شد.');
    } catch (Throwable $e) {
        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

function handle_edit_sale_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $sale_item_id = validate_int($_POST['sale_item_id'] ?? null, 1);
        $new_variant_id = validate_int($_POST['variant_id'] ?? null, 1);
        $new_quantity = validate_int($_POST['quantity'] ?? null, 1);

        $sell_price_input = $_POST['sell_price'] ?? null;
        $sell_price = filter_var($sell_price_input, FILTER_VALIDATE_FLOAT);
        if ($sell_price === false || $sell_price <= 0) {
            throw new InvalidArgumentException('قیمت فروش نامعتبر است.');
        }

        $sell_price = round($sell_price, 2);

        $currentItemStmt = $conn->prepare('SELECT variant_id, quantity FROM Sale_Items WHERE sale_item_id = ? FOR UPDATE');
        $currentItemStmt->bind_param('i', $sale_item_id);
        $currentItemStmt->execute();
        $currentItem = $currentItemStmt->get_result()->fetch_assoc();

        if (!$currentItem) {
            throw new RuntimeException('آیتم فروش یافت نشد.');
        }

        $old_variant_id = (int) $currentItem['variant_id'];
        $old_quantity = (int) $currentItem['quantity'];

        $variantStmt = $conn->prepare('SELECT price, stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $variantStmt->bind_param('i', $new_variant_id);
        $variantStmt->execute();
        $newVariant = $variantStmt->get_result()->fetch_assoc();
        if (!$newVariant) {
            throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
        }

        if ($old_variant_id === $new_variant_id) {
            $difference = $new_quantity - $old_quantity;

            if ($difference > 0 && (int) $newVariant['stock'] < $difference) {
                throw new RuntimeException('موجودی کافی برای افزایش تعداد وجود ندارد.');
            }

            $updateItemStmt = $conn->prepare('UPDATE Sale_Items SET quantity = ?, sell_price = ? WHERE sale_item_id = ?');
            $updateItemStmt->bind_param('idi', $new_quantity, $sell_price, $sale_item_id);
            $updateItemStmt->execute();

            if ($difference !== 0) {
                if ($difference > 0) {
                    $decreaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
                    $decreaseStmt->bind_param('ii', $difference, $new_variant_id);
                    $decreaseStmt->execute();
                } else {
                    $increase = abs($difference);
                    $increaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
                    $increaseStmt->bind_param('ii', $increase, $new_variant_id);
                    $increaseStmt->execute();
                }
            }
        } else {
            $oldVariantStmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
            $oldVariantStmt->bind_param('i', $old_variant_id);
            $oldVariantStmt->execute();
            $oldVariant = $oldVariantStmt->get_result()->fetch_assoc();
            if (!$oldVariant) {
                throw new RuntimeException('تنوع قبلی دیگر وجود ندارد.');
            }

            if ((int) $newVariant['stock'] < $new_quantity) {
                throw new RuntimeException('موجودی کافی برای تنوع جدید وجود ندارد.');
            }

            $updateItemStmt = $conn->prepare('UPDATE Sale_Items SET variant_id = ?, quantity = ?, sell_price = ? WHERE sale_item_id = ?');
            $updateItemStmt->bind_param('iidi', $new_variant_id, $new_quantity, $sell_price, $sale_item_id);
            $updateItemStmt->execute();

            $restoreStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
            $restoreStmt->bind_param('ii', $old_quantity, $old_variant_id);
            $restoreStmt->execute();

            $decreaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
            $decreaseStmt->bind_param('ii', $new_quantity, $new_variant_id);
            $decreaseStmt->execute();
        }

        $conn->commit();
        redirect_with_message('sales.php', 'success', 'آیتم فروش با موفقیت به‌روزرسانی شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

function handle_delete_sale(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $sale_id = validate_int($_GET['delete_sale'] ?? null, 1);

        $saleStmt = $conn->prepare('SELECT sale_id FROM Sales WHERE sale_id = ? FOR UPDATE');
        $saleStmt->bind_param('i', $sale_id);
        $saleStmt->execute();
        $saleExists = $saleStmt->get_result()->fetch_assoc();
        if (!$saleExists) {
            throw new RuntimeException('فروش موردنظر یافت نشد.');
        }

        $itemsStmt = $conn->prepare('SELECT variant_id, quantity FROM Sale_Items WHERE sale_id = ? FOR UPDATE');
        $itemsStmt->bind_param('i', $sale_id);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();

        $increaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
        while ($item = $itemsResult->fetch_assoc()) {
            $quantity = (int) $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $variant_id = (int) $item['variant_id'];
            $increaseStmt->bind_param('ii', $quantity, $variant_id);
            $increaseStmt->execute();
        }

        $deleteItemsStmt = $conn->prepare('DELETE FROM Sale_Items WHERE sale_id = ?');
        $deleteItemsStmt->bind_param('i', $sale_id);
        $deleteItemsStmt->execute();

        $deleteSaleStmt = $conn->prepare('DELETE FROM Sales WHERE sale_id = ?');
        $deleteSaleStmt->bind_param('i', $sale_id);
        $deleteSaleStmt->execute();

        $conn->commit();
        redirect_with_message('sales.php', 'success', 'فروش حذف شد و موجودی به‌روزرسانی گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

function handle_delete_sale_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $sale_item_id = validate_int($_GET['delete_sale_item'] ?? null, 1);

        $itemStmt = $conn->prepare('SELECT variant_id, quantity FROM Sale_Items WHERE sale_item_id = ? FOR UPDATE');
        $itemStmt->bind_param('i', $sale_item_id);
        $itemStmt->execute();
        $item = $itemStmt->get_result()->fetch_assoc();
        if (!$item) {
            throw new RuntimeException('آیتم فروش یافت نشد.');
        }

        $quantity = (int) $item['quantity'];
        $variant_id = (int) $item['variant_id'];

        $increaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
        $increaseStmt->bind_param('ii', $quantity, $variant_id);
        $increaseStmt->execute();

        $deleteStmt = $conn->prepare('DELETE FROM Sale_Items WHERE sale_item_id = ?');
        $deleteStmt->bind_param('i', $sale_item_id);
        $deleteStmt->execute();

        $conn->commit();
        redirect_with_message('sales.php', 'success', 'آیتم فروش حذف شد و موجودی بازگردانده شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('sales.php', 'error', normalize_error_message($e));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_sale'])) {
        handle_create_sale($conn);
    }

    if (isset($_POST['add_sale_item'])) {
        handle_add_sale_item($conn);
    }

    if (isset($_POST['edit_sale'])) {
        handle_edit_sale($conn);
    }

    if (isset($_POST['edit_sale_item'])) {
        handle_edit_sale_item($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['delete_sale'])) {
        handle_delete_sale($conn);
    }

    if (isset($_GET['delete_sale_item'])) {
        handle_delete_sale_item($conn);
    }
}

$flash_messages = get_flash_messages();

$today_sales = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE DATE(s.sale_date) = CURDATE()")->fetch_assoc();
$month_sales = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc();
$today_sales_total = $today_sales['total'] ?: 0;
$month_sales_total = $month_sales['total'] ?: 0;

$products = $conn->query('SELECT DISTINCT p.* FROM Products p JOIN Product_Variants pv ON p.product_id = pv.product_id WHERE pv.stock > 0 ORDER BY p.model_name');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت فروش‌ها - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>

    <!-- jQuery and DataTables CSS/JS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/plug-ins/1.13.4/i18n/fa.json"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap');
        
        body {
            font-family: 'Vazirmatn', sans-serif;
        }
        
        .status-badge {
            transition: all 0.2s;
        }
        .status-badge:hover {
            opacity: 0.9;
        }
        .sale-card:hover {
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
                        <a href="out_of_stock.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="x-circle" class="ml-2 w-5 h-5"></i>
                            <span>تمام شده</span>
                        </a>
                    </li>
                    <li>
                        <a href="sales.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-700 rounded-lg border-r-2 border-blue-500">
                            <i data-feather="shopping-cart" class="ml-2 w-5 h-5"></i>
                            <span>فروش‌ها</span>
                        </a>
                    </li>
                    <li>
                        <a href="purchases.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="list" class="ml-2 w-5 h-5"></i>
                            <span>مشاهده خریدها</span>
                        </a>
                    </li>
                    <li>
                        <a href="returns.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
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
                <h2 class="text-xl font-semibold text-gray-800">مدیریت فروش‌ها</h2>
                <div class="flex items-center space-x-4 space-x-reverse">
                    <button onclick="openModal('newSaleModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-md">
                        <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                        <span>فروش جدید</span>
                    </button>
                </div>
            </header>

            <!-- Sales Content -->
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

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-50 rounded-lg ml-4">
                                <i data-feather="shopping-bag" class="w-6 h-6 text-blue-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش امروز</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($today_sales_total, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-50 rounded-lg ml-4">
                                <i data-feather="trending-up" class="w-6 h-6 text-green-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش این ماه</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($month_sales_total, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-50 rounded-lg ml-4">
                                <i data-feather="dollar-sign" class="w-6 h-6 text-purple-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">میانگین هر فاکتور</h3>
                                <p class="text-xl font-bold text-gray-800">
                                    <?php 
                                    $avg_sale = $conn->query("SELECT AVG(total) as avg_total FROM (SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id GROUP BY s.sale_id) as t")->fetch_assoc();
                                    echo number_format($avg_sale['avg_total'] ?: 0, 0); 
                                    ?> تومان
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-3 sm:space-y-0 sm:space-x-4 sm:space-x-reverse">
                            <div class="relative">
                                <i data-feather="calendar" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="date" id="dateFilter" class="pr-10 pl-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="relative">
                                <i data-feather="search" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="text" id="searchInput" placeholder="جستجو در فروش‌ها..." class="pr-10 pl-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <button onclick="filterSales()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                اعمال فیلتر
                            </button>
                        </div>
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <!-- Removed Export to Excel button as per user request -->
                            <!--
                            <button id="exportBtn" class="flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                                <i data-feather="download" class="ml-2 w-4 h-4"></i>
                                <span>خروجی Excel</span>
                            </button>
                            -->
                        </div>
                    </div>
                </div>

                <!-- Sales List -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden" id="salesList">
                    <?php
                    $salesQuery = "SELECT s.*, c.name AS customer_name, COUNT(si.sale_item_id) AS item_count, SUM(si.quantity * si.sell_price) AS total_amount"
                        . " FROM Sales s"
                        . " LEFT JOIN Customers c ON s.customer_id = c.customer_id"
                        . " LEFT JOIN Sale_Items si ON s.sale_id = si.sale_id"
                        . " GROUP BY s.sale_id"
                        . " ORDER BY s.sale_date DESC, s.sale_id DESC";

                    $salesResult = $conn->query($salesQuery);

                    echo render_sales_table($salesResult);
                    ?>
                </div>

                <!-- New Sale Modal -->
                <div id="newSaleModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">فروش جدید</h3>
                                <button onclick="closeModal('newSaleModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>

                            <form method="POST" id="newSaleForm">
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                    <div class="lg:col-span-2">
                                        <h4 class="font-medium text-gray-700 mb-3">انتخاب محصولات</h4>

                                        <!-- Selected Items -->
                                        <div id="selectedItems" class="space-y-3 mb-4 max-h-60 overflow-y-auto p-2">
                                            <!-- Items will be added here dynamically -->
                                        </div>

                                        <!-- Add Product Form -->
                                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                            <div class="space-y-4">
                                                <!-- Product Selection -->
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب محصول</label>
                                                    <select id="productSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="">انتخاب محصول...</option>
                                                        <?php
                                                        while($product = $products->fetch_assoc()){
                                                            echo "<option value='{$product['product_id']}'>{$product['model_name']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <!-- Color Selection -->
                                                <div id="colorSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب رنگ</label>
                                                    <div id="colorOptions" class="flex flex-wrap gap-3">
                                                        <!-- Color options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Size Selection -->
                                                <div id="sizeSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب سایز</label>
                                                    <div id="sizeOptions" class="grid grid-cols-6 gap-2">
                                                        <!-- Size options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Quantity and Add Button -->
                                                <div id="quantitySelection" class="hidden">
                                                    <div class="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                                            <input type="number" id="quantityInput" min="1" value="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        </div>
                                                        <div class="flex items-end">
                                                            <button type="button" onclick="addItemToSale()" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                                                                افزودن به فروش
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div id="stockInfo" class="mt-2 text-sm text-gray-500">
                                                        <!-- Stock info will be shown here -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium text-gray-700 mb-3">جزئیات فروش</h4>
                                        <div class="bg-gray-50 p-4 rounded-lg">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">روش پرداخت</label>
                                                    <select name="payment_method" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="cash">نقدی</option>
                                                        <option value="credit_card">کارت اعتباری</option>
                                                        <option value="bank_transfer">انتقال بانکی</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ فروش</label>
                                                    <input type="date" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>

                                                <div class="pt-4 border-t border-gray-200">
                                                    <div class="flex justify-between mb-2">
                                                        <span class="text-gray-600">مجموع</span>
                                                        <span id="subtotal" class="font-medium">0 تومان</span>
                                                    </div>
                                                    <div class="flex justify-between font-bold text-lg mt-3 pt-3 border-t border-gray-200">
                                                        <span>مجموع کل</span>
                                                        <span id="total" class="text-blue-600">0 تومان</span>
                                                    </div>
                                                </div>

                                                <button type="submit" name="create_sale" onclick="return validateSaleForm()" class="w-full bg-blue-500 text-white py-3 rounded-lg hover:bg-blue-600 transition-colors mt-4 flex items-center justify-center">
                                                    <i data-feather="check" class="ml-2 w-5 h-5"></i>
                                                    تکمیل فروش
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden inputs for sale items -->
                                <div id="saleItemsInputs">
                                    <!-- Sale item inputs will be added here -->
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Sale Modal -->
                <div id="editSaleModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش فروش</h3>
                                <button onclick="closeModal('editSaleModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="sale_id" id="edit_sale_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">مشتری</label>
                                        <select name="customer_id" id="edit_customer_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            
                                            <?php
                                            $customers = $conn->query("SELECT * FROM Customers");
                                            while($customer = $customers->fetch_assoc()){
                                                echo "<option value='{$customer['customer_id']}'>{$customer['name']}</option>";
                                            }
                                            ?>

                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ فروش</label>
                                        <input type="date" name="sale_date" id="edit_sale_date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">روش پرداخت</label>
                                        <select name="payment_method" id="edit_payment_method" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="credit_card">کارت اعتباری</option>
                                            <option value="cash">نقدی</option>
                                            <option value="bank_transfer">انتقال بانکی</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                        <select name="status" id="edit_status" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="paid">پرداخت شده</option>
                                            <option value="pending">در انتظار پرداخت</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="edit_sale" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors flex items-center justify-center">
                                        <i data-feather="edit" class="ml-2 w-4 h-4"></i>
                                        ویرایش فروش
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sale Items Modal -->
                <div id="saleItemsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">آیتم‌های فروش</h3>
                                <button onclick="closeModal('saleItemsModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <div id="saleItemsContent">
                                <!-- Sale items will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Sale Item Modal -->
                <div id="editSaleItemModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش آیتم فروش</h3>
                                <button onclick="closeModal('editSaleItemModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="sale_item_id" id="edit_sale_item_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">محصول</label>
                                        <select name="variant_id" id="edit_item_variant_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="">انتخاب محصول...</option>
                                            <?php
                                            $variants = $conn->query("SELECT pv.*, p.model_name FROM Product_Variants pv JOIN Products p ON pv.product_id = p.product_id ORDER BY p.model_name, pv.color, pv.size");
                                            while($variant = $variants->fetch_assoc()){
                                                echo "<option value='{$variant['variant_id']}'>{$variant['model_name']} - {$variant['color']} / {$variant['size']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                        <input type="number" name="quantity" id="edit_item_quantity" min="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">قیمت فروش</label>
                                        <input type="number" name="sell_price" id="edit_item_sell_price" step="0.01" min="0" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <button type="submit" name="edit_sale_item" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors flex items-center justify-center">
                                        <i data-feather="edit" class="ml-2 w-4 h-4"></i>
                                        ویرایش آیتم
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        feather.replace();

        let saleItems = [];
        let itemCounter = 0;
        let selectedProductId = null;
        let selectedColor = null;
        let selectedSize = null;
        let currentVariants = [];

        function openModal(modalId) {
            if (modalId === 'newSaleModal') {
                resetNewSaleModal();
            }
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function resetNewSaleModal() {
            saleItems = [];
            itemCounter = 0;
            selectedProductId = null;
            selectedColor = null;
            selectedSize = null;
            currentVariants = [];
            
            document.getElementById('selectedItems').innerHTML = '';
            document.getElementById('saleItemsInputs').innerHTML = '';
            document.getElementById('subtotal').textContent = '0 تومان';
            document.getElementById('total').textContent = '0 تومان';
            document.getElementById('productSelect').value = '';
            document.getElementById('quantityInput').value = '1';
            
            // Hide selection sections
            document.getElementById('colorSelection').classList.add('hidden');
            document.getElementById('sizeSelection').classList.add('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
        }

        // When product is selected
        document.getElementById('productSelect').addEventListener('change', function() {
            const productId = this.value;
            
            if (!productId) {
                document.getElementById('colorSelection').classList.add('hidden');
                document.getElementById('sizeSelection').classList.add('hidden');
                document.getElementById('quantitySelection').classList.add('hidden');
                return;
            }
            
            selectedProductId = productId;
            selectedColor = null;
            selectedSize = null;
            
            // Load colors for this product
            loadColors(productId);
        });

        function loadColors(productId) {
            // Show loading state
            document.getElementById('colorOptions').innerHTML = '<div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span>';
            document.getElementById('colorSelection').classList.remove('hidden');
            document.getElementById('sizeSelection').classList.add('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
            
            // Fetch colors from server
            fetch('get_product_colors.php?product_id=' + productId)
                .then(response => response.json())
                .then(data => {
                    currentVariants = data.variants;
                    const colorOptions = document.getElementById('colorOptions');
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
                            selectColor(color.color);
                        });

                        colorOptions.appendChild(colorOption);
                    });
                })
                .catch(error => {
                    console.error('Error loading colors:', error);
                    document.getElementById('colorOptions').innerHTML = '<p class="text-red-500">خطا در بارگذاری رنگ‌ها</p>';
                });
        }

        function selectColor(color) {
            selectedColor = color;
            selectedSize = null;
            
            // Update UI - mark selected color
            document.querySelectorAll('.color-option').forEach(option => {
                if (option.getAttribute('data-color') === color) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });
            
            // Load sizes for selected color
            loadSizes(selectedProductId, color);
        }

        function loadSizes(productId, color) {
            // Show loading state
            document.getElementById('sizeOptions').innerHTML = '<div class="col-span-6 flex justify-center"><div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span></div>';
            document.getElementById('sizeSelection').classList.remove('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');

            // Filter variants by product and color
            const availableSizes = [];
            const sizeMap = {};

            currentVariants.forEach(variant => {
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

            // Convert to array and sort by size order, only include sizes with stock > 0
            Object.values(sizeMap).forEach(sizeInfo => {
                if (sizeInfo.stock > 0) {
                    availableSizes.push(sizeInfo);
                }
            });

            // Sort sizes in logical order
            const sizeOrder = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
            availableSizes.sort((a, b) => sizeOrder.indexOf(a.size) - sizeOrder.indexOf(b.size));

            // Display sizes
            const sizeOptions = document.getElementById('sizeOptions');
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
                    selectSize(sizeInfo.size, sizeInfo.stock, sizeInfo.price, sizeInfo.variant_id);
                });

                sizeOptions.appendChild(sizeOption);
            });
        }

        function selectSize(size, stock, price, variantId) {
            selectedSize = size;
            
            // Update UI - mark selected size
            document.querySelectorAll('.size-option').forEach(option => {
                if (option.getAttribute('data-size') === size) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });
            
            // Show quantity selection
            document.getElementById('quantitySelection').classList.remove('hidden');
            document.getElementById('quantityInput').max = stock;
            document.getElementById('quantityInput').value = '1';
            
            // Update stock info
            document.getElementById('stockInfo').innerHTML = `
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
            currentVariantInfo = {
                variantId: variantId,
                price: price,
                stock: stock
            };
        }

        function addItemToSale() {
            const quantityInput = document.getElementById('quantityInput');
            const quantity = parseInt(quantityInput.value);

            if (!selectedProductId || !selectedColor || !selectedSize) {
                alert('لطفا محصول، رنگ و سایز را انتخاب کنید.');
                return;
            }

            if (!quantity || quantity < 1) {
                alert('لطفا تعداد معتبر وارد کنید.');
                return;
            }

            if (quantity > currentVariantInfo.stock) {
                alert('تعداد انتخاب شده بیشتر از موجودی است.');
                return;
            }

            // Get product name
            const productSelect = document.getElementById('productSelect');
            const productName = productSelect.options[productSelect.selectedIndex].text;

            // Check if item already exists
            const existingItem = saleItems.find(item => item.variantId === currentVariantInfo.variantId);
            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.total = existingItem.quantity * existingItem.price;
                updateSelectedItemsDisplay();
                updateTotals();
            } else {
                const item = {
                    id: itemCounter++,
                    variantId: currentVariantInfo.variantId,
                    productName: `${productName} - ${selectedColor} - ${selectedSize}`,
                    quantity: quantity,
                    price: currentVariantInfo.price,
                    total: quantity * currentVariantInfo.price
                };
                saleItems.push(item);
                addItemToDisplay(item);
            }

            updateTotals();
            updateHiddenInputs();

            // Reset quantity selection for next item
            document.getElementById('quantitySelection').classList.add('hidden');
            document.getElementById('quantityInput').value = '1';
        }

        function addItemToDisplay(item) {
            const selectedItems = document.getElementById('selectedItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-white p-4 rounded-lg border border-gray-200 flex items-center justify-between';
            itemDiv.id = `item-${item.id}`;

            itemDiv.innerHTML = `
                <div class="flex-1">
                    <h5 class="font-medium text-gray-800">${item.productName}</h5>
                    <div class="text-sm text-gray-500">تعداد: ${Math.max(0, item.quantity)} × ${item.price.toLocaleString()} تومان</div>
                </div>
                <div class="text-left">
                    <div class="font-bold text-gray-800">${item.total.toLocaleString()} تومان</div>
                </div>
                <button onclick="removeItem(${item.id})" class="text-red-500 hover:text-red-700 mr-2 transition-colors">
                    <i data-feather="trash-2" class="w-4 h-4"></i>
                </button>
            `;

            selectedItems.appendChild(itemDiv);
            feather.replace();
        }

        function removeItem(itemId) {
            saleItems = saleItems.filter(item => item.id !== itemId);
            document.getElementById(`item-${itemId}`).remove();
            updateTotals();
            updateHiddenInputs();
        }

        function updateSelectedItemsDisplay() {
            const selectedItems = document.getElementById('selectedItems');
            selectedItems.innerHTML = '';
            saleItems.forEach(item => addItemToDisplay(item));
        }

        function updateTotals() {
            const subtotal = saleItems.reduce((sum, item) => sum + item.total, 0);
            document.getElementById('subtotal').textContent = subtotal.toLocaleString() + ' تومان';
            document.getElementById('total').textContent = subtotal.toLocaleString() + ' تومان';
        }

        function updateHiddenInputs() {
            const inputsContainer = document.getElementById('saleItemsInputs');
            inputsContainer.innerHTML = '';

            saleItems.forEach((item, index) => {
                inputsContainer.innerHTML += `
                    <input type="hidden" name="items[${index}][variant_id]" value="${item.variantId}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                    <input type="hidden" name="items[${index}][sell_price]" value="${item.price}">
                `;
            });
        }

        function openEditSaleModal(saleId, customerId, saleDate, paymentMethod, status) {
            document.getElementById('edit_sale_id').value = saleId;
            document.getElementById('edit_customer_id').value = customerId;
            document.getElementById('edit_sale_date').value = saleDate;
            document.getElementById('edit_payment_method').value = paymentMethod;
            document.getElementById('edit_status').value = status;
            document.getElementById('editSaleModal').classList.remove('hidden');
        }

        function openEditSaleItemModal(saleItemId, variantId, quantity, sellPrice) {
            document.getElementById('edit_sale_item_id').value = saleItemId;
            document.getElementById('edit_item_variant_id').value = variantId;
            document.getElementById('edit_item_quantity').value = quantity;
            document.getElementById('edit_item_sell_price').value = sellPrice;
            document.getElementById('editSaleItemModal').classList.remove('hidden');
        }

        function validateSaleForm() {
            if (saleItems.length === 0) {
                alert('لطفا حداقل یک محصول به فروش اضافه کنید.');
                return false;
            }
            return true;
        }

        function showSaleItems(saleId) {
            // Show loading state
            document.getElementById('saleItemsContent').innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="spinner"></div>
                    <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                </div>
            `;
            
            document.getElementById('saleItemsModal').classList.remove('hidden');
            
            // Load sale items via AJAX
            fetch('get_sale_items.php?sale_id=' + saleId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('saleItemsContent').innerHTML = data;
                    feather.replace();
                })
                .catch(error => {
                    console.error('Error loading sale items:', error);
                    document.getElementById('saleItemsContent').innerHTML = `
                        <div class="text-center py-8 text-red-500">
                            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                            <p>خطا در بارگذاری آیتم‌های فروش</p>
                        </div>
                    `;
                    feather.replace();
                });
        }



        function filterSales() {
            const dateFilter = document.getElementById('dateFilter').value;
            const searchInput = document.getElementById('searchInput').value;
            const salesListContainer = document.getElementById('salesList');

            if (!salesListContainer) {
                return;
            }

            salesListContainer.innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="spinner"></div>
                    <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                </div>
            `;

            const formData = new FormData();
            formData.append('date', dateFilter);
            formData.append('search', searchInput);

            fetch('filter_sales.php', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(response => response.text().then(text => ({ ok: response.ok, body: text })))
                .then(({ ok, body }) => {
                    salesListContainer.innerHTML = body;
                    feather.replace();

                    if (ok) {
                        initializeSalesTable();
                    } else {
                        console.error('Error filtering sales:', body);
                    }
                })
                .catch(error => {
                    console.error('Error filtering sales:', error);
                    salesListContainer.innerHTML = `
                        <div class="text-center py-8 text-red-500">
                            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                            <p>خطا در فیلتر کردن فروش‌ها</p>
                        </div>
                    `;
                    feather.replace();
                });
        }

        function printReceipt(event, saleId) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            let printBtn = null;
            if (event && event.currentTarget instanceof HTMLElement) {
                printBtn = event.currentTarget;
            } else if (event && event.target instanceof HTMLElement) {
                printBtn = event.target.closest('button');
            }

            if (!printBtn) {
                console.error('دکمه چاپ یافت نشد.');
                return;
            }

            const originalHTML = printBtn.innerHTML;
            printBtn.innerHTML = '<div class="spinner"></div>';
            printBtn.disabled = true;

            const requestUrl = new URL('get_sale_receipt.php', window.location.href);
            requestUrl.searchParams.set('sale_id', saleId);

            fetch(requestUrl.toString())
                .then(response => response.text())
                .then(data => {
                    const printWindow = window.open('', '_blank', 'width=800,height=600');

                    if (!printWindow) {
                        throw new Error('پنجره چاپ باز نشد.');
                    }

                    printWindow.document.write(data);
                    printWindow.document.close();

                    printWindow.onload = function() {
                        printWindow.print();
                        printWindow.close();
                    };

                    printBtn.innerHTML = originalHTML;
                    printBtn.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading receipt:', error);
                    alert('خطا در بارگذاری رسید');

                    printBtn.innerHTML = originalHTML;
                    printBtn.disabled = false;
                });
        }

        function initializeSalesTable() {
            if (!window.jQuery || !$.fn.DataTable) {
                return;
            }

            const tableSelector = '#salesTable';

            if ($.fn.DataTable.isDataTable(tableSelector)) {
                $(tableSelector).DataTable().destroy();
            }

            $(tableSelector).DataTable({
                "language": {
                    "url": "https://cdn.datatables.net/plug-ins/1.13.4/i18n/fa.json"
                },
                "pageLength": 25,
                "responsive": true,
                "order": [[ 2, "desc" ]]
            });
        }

        // Export to Excel functionality
        const exportButton = document.getElementById('exportBtn');
        if (exportButton) {
            exportButton.addEventListener('click', function() {
                const originalText = this.innerHTML;
                this.innerHTML = '<div class="spinner"></div><span class="mr-2">در حال تولید...</span>';
                this.disabled = true;

                setTimeout(() => {
                    alert('فایل اکسل با موفقیت تولید شد!');
                    this.innerHTML = originalText;
                    this.disabled = false;
                }, 1500);
            });
        }

        // Initialize DataTables
        $(document).ready(function() {
            initializeSalesTable();
        });

        // Out of stock functionality
        document.getElementById('showOutOfStockBtn').addEventListener('click', function() {
            const detailsDiv = document.getElementById('outOfStockDetails');
            const btn = this;

            if (detailsDiv.classList.contains('hidden')) {
                // Show loading state
                detailsDiv.innerHTML = `
                    <div class="flex justify-center items-center py-8">
                        <div class="spinner"></div>
                        <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                    </div>
                `;
                detailsDiv.classList.remove('hidden');

                // Load out of stock data
                fetch('get_out_of_stock.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.products && data.products.length > 0) {
                            let html = '<div class="space-y-4">';

                            data.products.forEach(product => {
                                html += `
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <h4 class="font-semibold text-gray-800">${product.model_name}</h4>
                                                <p class="text-sm text-gray-600">برند: ${product.brand} | دسته‌بندی: ${product.category}</p>
                                            </div>
                                        </div>
                                        <div class="text-sm text-gray-700">
                                            <strong>تنوع‌های تمام شده:</strong>
                                            <div class="mt-1 text-red-600">${product.out_of_stock_variants}</div>
                                        </div>
                                    </div>
                                `;
                            });

                            html += '</div>';
                            detailsDiv.innerHTML = html;
                        } else {
                            detailsDiv.innerHTML = `
                                <div class="text-center py-8 text-gray-500">
                                    <i data-feather="check-circle" class="w-12 h-12 mx-auto mb-4 text-green-500"></i>
                                    <p>هیچ محصولی تمام نشده است.</p>
                                </div>
                            `;
                        }

                        feather.replace();
                    })
                    .catch(error => {
                        console.error('Error loading out of stock items:', error);
                        detailsDiv.innerHTML = `
                            <div class="text-center py-8 text-red-500">
                                <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                                <p>خطا در بارگذاری محصولات تمام شده</p>
                            </div>
                        `;
                        feather.replace();
                    });
            } else {
                detailsDiv.classList.add('hidden');
            }
        });
    </script>
</body>
</html>