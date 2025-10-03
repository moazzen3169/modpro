<?php
require_once __DIR__ . '/env/bootstrap.php';
require_once __DIR__ . '/includes/purchases_table_renderer.php';

function sanitize_supplier_name(string $value): string
{
    $name = trim($value);
    if ($name === '') {
        throw new InvalidArgumentException('وارد کردن نام تأمین‌کننده الزامی است.');
    }

    return $name;
}

function validate_cost_price(mixed $value): float
{
    $price = filter_var($value, FILTER_VALIDATE_FLOAT);
    if ($price === false || $price <= 0) {
        throw new InvalidArgumentException('قیمت خرید وارد شده نامعتبر است.');
    }

    return round((float) $price, 2);
}

function recalc_purchase_total(mysqli $conn, int $purchase_id): void
{
    $recalcStmt = $conn->prepare(
        'UPDATE Purchases SET total_amount = (
            SELECT COALESCE(SUM(quantity * cost_price), 0)
            FROM Purchase_Items
            WHERE purchase_id = ?
        )
        WHERE purchase_id = ?'
    );
    $recalcStmt->bind_param('ii', $purchase_id, $purchase_id);
    $recalcStmt->execute();
}

function ensure_purchase_modifiable(mysqli $conn, int $purchase_id): array
{
    $purchaseStmt = $conn->prepare('SELECT purchase_id, status FROM Purchases WHERE purchase_id = ? FOR UPDATE');
    $purchaseStmt->bind_param('i', $purchase_id);
    $purchaseStmt->execute();
    $purchase = $purchaseStmt->get_result()->fetch_assoc();

    if (!$purchase) {
        throw new RuntimeException('خرید موردنظر یافت نشد.');
    }

    if (($purchase['status'] ?? '') === 'cancelled') {
        throw new RuntimeException('امکان ویرایش خرید لغوشده وجود ندارد.');
    }

    return $purchase;
}

function handle_create_purchase(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $supplier_name = sanitize_supplier_name((string) ($_POST['supplier_name'] ?? ''));
        $purchase_date = validate_date((string) ($_POST['purchase_date'] ?? ''));
        $status = validate_enum((string) ($_POST['status'] ?? 'pending'), ['pending', 'received', 'cancelled']);

        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || $raw_items === []) {
            throw new InvalidArgumentException('برای ثبت خرید حداقل یک آیتم لازم است.');
        }

        $items = [];
        foreach ($raw_items as $item) {
            $variant_id = validate_int($item['variant_id'] ?? null, 1);
            $quantity = validate_int($item['quantity'] ?? null, 1);
            $cost_price = validate_cost_price($item['cost_price'] ?? null);
            $items[] = [
                'variant_id' => $variant_id,
                'quantity' => $quantity,
                'cost_price' => $cost_price,
            ];
        }

        $insertPurchaseStmt = $conn->prepare('INSERT INTO Purchases (supplier_name, purchase_date, status, total_amount) VALUES (?, ?, ?, 0)');
        $insertPurchaseStmt->bind_param('sss', $supplier_name, $purchase_date, $status);
        $insertPurchaseStmt->execute();
        $purchase_id = (int) $conn->insert_id;

        $variantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $insertItemStmt = $conn->prepare('INSERT INTO Purchase_Items (purchase_id, variant_id, quantity, cost_price) VALUES (?, ?, ?, ?)');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');

        $totalAmount = 0.0;
        foreach ($items as $item) {
            $variant_id = $item['variant_id'];
            $quantity = $item['quantity'];
            $cost_price = $item['cost_price'];

            $variantStmt->bind_param('i', $variant_id);
            $variantStmt->execute();
            $variant = $variantStmt->get_result()->fetch_assoc();
            if (!$variant) {
                throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
            }

            $insertItemStmt->bind_param('iiid', $purchase_id, $variant_id, $quantity, $cost_price);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('ii', $quantity, $variant_id);
            $updateStockStmt->execute();

            $totalAmount += $quantity * $cost_price;
        }

        $updateTotalStmt = $conn->prepare('UPDATE Purchases SET total_amount = ? WHERE purchase_id = ?');
        $updateTotalStmt->bind_param('di', $totalAmount, $purchase_id);
        $updateTotalStmt->execute();

        $conn->commit();
        redirect_with_message('purchases.php', 'success', 'خرید جدید با موفقیت ثبت شد و موجودی انبار افزایش یافت.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

function handle_add_purchase_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $purchase_id = validate_int($_POST['purchase_id'] ?? null, 1);
        $variant_id = validate_int($_POST['variant_id'] ?? null, 1);
        $quantity = validate_int($_POST['quantity'] ?? null, 1);
        $cost_price = validate_cost_price($_POST['cost_price'] ?? null);

        ensure_purchase_modifiable($conn, $purchase_id);

        $variantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $variantStmt->bind_param('i', $variant_id);
        $variantStmt->execute();
        $variant = $variantStmt->get_result()->fetch_assoc();
        if (!$variant) {
            throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
        }

        $insertItemStmt = $conn->prepare('INSERT INTO Purchase_Items (purchase_id, variant_id, quantity, cost_price) VALUES (?, ?, ?, ?)');
        $insertItemStmt->bind_param('iiid', $purchase_id, $variant_id, $quantity, $cost_price);
        $insertItemStmt->execute();

        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
        $updateStockStmt->bind_param('ii', $quantity, $variant_id);
        $updateStockStmt->execute();

        $incrementTotalStmt = $conn->prepare('UPDATE Purchases SET total_amount = total_amount + ? WHERE purchase_id = ?');
        $itemTotal = $quantity * $cost_price;
        $incrementTotalStmt->bind_param('di', $itemTotal, $purchase_id);
        $incrementTotalStmt->execute();

        $conn->commit();
        redirect_with_message('purchases.php', 'success', 'آیتم جدید به خرید اضافه شد و موجودی انبار به‌روزرسانی گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

function handle_edit_purchase(mysqli $conn): void
{
    try {
        $purchase_id = validate_int($_POST['purchase_id'] ?? null, 1);
        $supplier_name = sanitize_supplier_name((string) ($_POST['supplier_name'] ?? ''));
        $purchase_date = validate_date((string) ($_POST['purchase_date'] ?? ''));
        $status = validate_enum((string) ($_POST['status'] ?? 'pending'), ['pending', 'received', 'cancelled']);

        $updateStmt = $conn->prepare('UPDATE Purchases SET supplier_name = ?, purchase_date = ?, status = ? WHERE purchase_id = ?');
        $updateStmt->bind_param('sssi', $supplier_name, $purchase_date, $status, $purchase_id);
        $updateStmt->execute();

        redirect_with_message('purchases.php', 'success', 'جزئیات خرید با موفقیت به‌روزرسانی شد.');
    } catch (Throwable $e) {
        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

function handle_edit_purchase_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $purchase_item_id = validate_int($_POST['purchase_item_id'] ?? null, 1);
        $new_variant_id = validate_int($_POST['variant_id'] ?? null, 1);
        $new_quantity = validate_int($_POST['quantity'] ?? null, 1);
        $new_cost_price = validate_cost_price($_POST['cost_price'] ?? null);

        $currentItemStmt = $conn->prepare('SELECT purchase_id, variant_id, quantity FROM Purchase_Items WHERE purchase_item_id = ? FOR UPDATE');
        $currentItemStmt->bind_param('i', $purchase_item_id);
        $currentItemStmt->execute();
        $currentItem = $currentItemStmt->get_result()->fetch_assoc();

        if (!$currentItem) {
            throw new RuntimeException('آیتم خرید یافت نشد.');
        }

        $purchase_id = (int) $currentItem['purchase_id'];
        ensure_purchase_modifiable($conn, $purchase_id);

        $old_variant_id = (int) $currentItem['variant_id'];
        $old_quantity = (int) $currentItem['quantity'];

        if ($old_variant_id === $new_variant_id) {
            $variantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
            $variantStmt->bind_param('i', $new_variant_id);
            $variantStmt->execute();
            $variant = $variantStmt->get_result()->fetch_assoc();
            if (!$variant) {
                throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
            }

            $difference = $new_quantity - $old_quantity;
            if ($difference < 0 && (int) $variant['stock'] < abs($difference)) {
                throw new RuntimeException('کاهش تعداد این آیتم باعث منفی شدن موجودی می‌شود.');
            }

            if ($difference !== 0) {
                if ($difference > 0) {
                    $increaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
                    $increaseStmt->bind_param('ii', $difference, $new_variant_id);
                    $increaseStmt->execute();
                } else {
                    $decrease = abs($difference);
                    $decreaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
                    $decreaseStmt->bind_param('ii', $decrease, $new_variant_id);
                    $decreaseStmt->execute();
                }
            }
        } else {
            $oldVariantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
            $oldVariantStmt->bind_param('i', $old_variant_id);
            $oldVariantStmt->execute();
            $oldVariant = $oldVariantStmt->get_result()->fetch_assoc();
            if (!$oldVariant) {
                throw new RuntimeException('تنوع قبلی دیگر وجود ندارد.');
            }

            if ((int) $oldVariant['stock'] < $old_quantity) {
                throw new RuntimeException('موجودی فعلی اجازه حذف آیتم قبلی را نمی‌دهد.');
            }

            $newVariantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
            $newVariantStmt->bind_param('i', $new_variant_id);
            $newVariantStmt->execute();
            $newVariant = $newVariantStmt->get_result()->fetch_assoc();
            if (!$newVariant) {
                throw new RuntimeException('تنوع جدید یافت نشد.');
            }

            $restoreStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
            $restoreStmt->bind_param('ii', $old_quantity, $old_variant_id);
            $restoreStmt->execute();

            $assignStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
            $assignStmt->bind_param('ii', $new_quantity, $new_variant_id);
            $assignStmt->execute();
        }

        $updateItemStmt = $conn->prepare('UPDATE Purchase_Items SET variant_id = ?, quantity = ?, cost_price = ? WHERE purchase_item_id = ?');
        $updateItemStmt->bind_param('iidi', $new_variant_id, $new_quantity, $new_cost_price, $purchase_item_id);
        $updateItemStmt->execute();

        recalc_purchase_total($conn, $purchase_id);

        $conn->commit();
        redirect_with_message('purchases.php', 'success', 'آیتم خرید با موفقیت به‌روزرسانی شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

function handle_delete_purchase(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $purchase_id = validate_int($_GET['delete_purchase'] ?? null, 1);

        $purchaseStmt = $conn->prepare('SELECT status FROM Purchases WHERE purchase_id = ? FOR UPDATE');
        $purchaseStmt->bind_param('i', $purchase_id);
        $purchaseStmt->execute();
        $purchase = $purchaseStmt->get_result()->fetch_assoc();
        if (!$purchase) {
            throw new RuntimeException('خرید موردنظر یافت نشد.');
        }

        $itemsStmt = $conn->prepare('SELECT variant_id, quantity FROM Purchase_Items WHERE purchase_id = ? FOR UPDATE');
        $itemsStmt->bind_param('i', $purchase_id);
        $itemsStmt->execute();
        $itemsResult = $itemsStmt->get_result();

        $stockCheckStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');

        while ($item = $itemsResult->fetch_assoc()) {
            $variant_id = (int) $item['variant_id'];
            $quantity = (int) $item['quantity'];
            if ($quantity <= 0) {
                continue;
            }

            $stockCheckStmt->bind_param('i', $variant_id);
            $stockCheckStmt->execute();
            $variant = $stockCheckStmt->get_result()->fetch_assoc();
            if (!$variant) {
                throw new RuntimeException('برخی از تنوع‌ها دیگر موجود نیستند.');
            }

            if ((int) $variant['stock'] < $quantity) {
                throw new RuntimeException('موجودی فعلی اجازه حذف این خرید را نمی‌دهد.');
            }

            $updateStockStmt->bind_param('ii', $quantity, $variant_id);
            $updateStockStmt->execute();
        }

        $deleteItemsStmt = $conn->prepare('DELETE FROM Purchase_Items WHERE purchase_id = ?');
        $deleteItemsStmt->bind_param('i', $purchase_id);
        $deleteItemsStmt->execute();

        $deletePurchaseStmt = $conn->prepare('DELETE FROM Purchases WHERE purchase_id = ?');
        $deletePurchaseStmt->bind_param('i', $purchase_id);
        $deletePurchaseStmt->execute();

        $conn->commit();
        redirect_with_message('purchases.php', 'success', 'خرید حذف شد و موجودی اصلاح گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

function handle_delete_purchase_item(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $purchase_item_id = validate_int($_GET['delete_purchase_item'] ?? null, 1);

        $itemStmt = $conn->prepare('SELECT purchase_id, variant_id, quantity FROM Purchase_Items WHERE purchase_item_id = ? FOR UPDATE');
        $itemStmt->bind_param('i', $purchase_item_id);
        $itemStmt->execute();
        $item = $itemStmt->get_result()->fetch_assoc();
        if (!$item) {
            throw new RuntimeException('آیتم خرید یافت نشد.');
        }

        $purchase_id = (int) $item['purchase_id'];
        ensure_purchase_modifiable($conn, $purchase_id);

        $variant_id = (int) $item['variant_id'];
        $quantity = (int) $item['quantity'];

        if ($quantity > 0) {
            $variantStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
            $variantStmt->bind_param('i', $variant_id);
            $variantStmt->execute();
            $variant = $variantStmt->get_result()->fetch_assoc();
            if (!$variant) {
                throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
            }

            if ((int) $variant['stock'] < $quantity) {
                throw new RuntimeException('موجودی فعلی اجازه حذف این آیتم را نمی‌دهد.');
            }

            $decreaseStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');
            $decreaseStmt->bind_param('ii', $quantity, $variant_id);
            $decreaseStmt->execute();
        }

        $deleteStmt = $conn->prepare('DELETE FROM Purchase_Items WHERE purchase_item_id = ?');
        $deleteStmt->bind_param('i', $purchase_item_id);
        $deleteStmt->execute();

        recalc_purchase_total($conn, $purchase_id);

        $conn->commit();
        redirect_with_message('purchases.php', 'success', 'آیتم خرید حذف شد و موجودی انبار اصلاح گردید.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_purchase'])) {
        handle_create_purchase($conn);
    }

    if (isset($_POST['add_purchase_item'])) {
        handle_add_purchase_item($conn);
    }

    if (isset($_POST['edit_purchase'])) {
        handle_edit_purchase($conn);
    }

    if (isset($_POST['edit_purchase_item'])) {
        handle_edit_purchase_item($conn);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['delete_purchase'])) {
        handle_delete_purchase($conn);
    }

    if (isset($_GET['delete_purchase_item'])) {
        handle_delete_purchase_item($conn);
    }
}

$flash_messages = get_flash_messages();

$today_purchases = $conn->query("SELECT SUM(pi.quantity * pi.cost_price) AS total FROM Purchases p JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id WHERE DATE(p.purchase_date) = CURDATE()")->fetch_assoc();
$month_purchases = $conn->query("SELECT SUM(pi.quantity * pi.cost_price) AS total FROM Purchases p JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id WHERE MONTH(p.purchase_date) = MONTH(CURDATE()) AND YEAR(p.purchase_date) = YEAR(CURDATE())")->fetch_assoc();
$today_purchases_total = $today_purchases['total'] ?: 0;
$month_purchases_total = $month_purchases['total'] ?: 0;

$products = $conn->query('SELECT * FROM Products ORDER BY model_name');
$all_variants_for_select = $conn->query('SELECT pv.*, p.model_name FROM Product_Variants pv JOIN Products p ON pv.product_id = p.product_id ORDER BY p.model_name, pv.color, pv.size');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت خریدها - SuitStore Manager Pro</title>
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

        .purchase-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

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

        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #10b981;
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

        .color-option,
        .size-option {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            background-color: white;
            color: #374151;
        }

        .color-option.selected,
        .size-option.selected {
            border-color: #10b981;
            background-color: #ecfdf5;
            color: #047857;
            font-weight: 600;
        }

        .color-option:hover,
        .size-option:hover {
            border-color: #10b981;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-l border-gray-200 flex flex-col sidebar-shadow">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 ml-2 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h18M9 7h12M9 11h12M9 15h12M9 19h12M3 7h.01M3 11h.01M3 15h.01M3 19h.01" />
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
                        <a href="purchases.php" class="flex items-center px-4 py-3 bg-emerald-50 text-emerald-700 rounded-lg border-r-2 border-emerald-500">
                            <i data-feather="shopping-bag" class="ml-2 w-5 h-5"></i>
                            <span>خریدها</span>
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
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center">
                <div>
                    <h2 class="text-xl font-semibold text-gray-800">مدیریت خریدها</h2>
                    <p class="text-sm text-gray-500">ثبت و مدیریت فاکتورهای خرید و افزایش موجودی</p>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="openModal('newPurchaseModal')" class="flex items-center px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-colors shadow">
                        <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                        <span>خرید جدید</span>
                    </button>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <?php if (!empty($flash_messages['success']) || !empty($flash_messages['error'])): ?>
                    <div class="space-y-3">
                        <?php foreach ($flash_messages['success'] as $message): ?>
                            <div class="flex items-center justify-between bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg">
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

                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 purchase-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">ارزش خریدهای امروز</p>
                                <h3 class="text-2xl font-bold text-gray-800 mt-2"><?php echo number_format((float) $today_purchases_total, 0); ?> تومان</h3>
                            </div>
                            <div class="bg-emerald-100 text-emerald-600 p-3 rounded-full">
                                <i data-feather="calendar" class="w-6 h-6"></i>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 purchase-card">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500">ارزش خریدهای این ماه</p>
                                <h3 class="text-2xl font-bold text-gray-800 mt-2"><?php echo number_format((float) $month_purchases_total, 0); ?> تومان</h3>
                            </div>
                            <div class="bg-emerald-100 text-emerald-600 p-3 rounded-full">
                                <i data-feather="trending-up" class="w-6 h-6"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Purchases Table -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-200 overflow-hidden" id="purchasesList">
                    <?php
                    $purchasesQuery = "SELECT p.*, COUNT(pi.purchase_item_id) AS item_count, SUM(pi.quantity * pi.cost_price) AS total_amount"
                        . " FROM Purchases p"
                        . " LEFT JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id"
                        . " GROUP BY p.purchase_id"
                        . " ORDER BY p.purchase_date DESC, p.purchase_id DESC";

                    $purchasesResult = $conn->query($purchasesQuery);

                    echo render_purchases_table($purchasesResult);
                    ?>
                </div>

                <!-- New Purchase Modal -->
                <div id="newPurchaseModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ثبت خرید جدید</h3>
                                <button onclick="closeModal('newPurchaseModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>

                            <form method="POST" id="newPurchaseForm">
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                    <div class="lg:col-span-2">
                                        <h4 class="font-medium text-gray-700 mb-3">انتخاب محصولات</h4>

                                        <div id="purchaseSelectedItems" class="space-y-3 mb-4 max-h-60 overflow-y-auto p-2">
                                            <!-- Selected items will appear here -->
                                        </div>

                                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب محصول</label>
                                                    <select id="purchaseProductSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                        <option value="">انتخاب محصول...</option>
                                                        <?php
                                                        while ($product = $products->fetch_assoc()) {
                                                            $product_id = (int) $product['product_id'];
                                                            $product_name = htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8');
                                                            echo "<option value='{$product_id}'>{$product_name}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <div id="purchaseColorSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب رنگ</label>
                                                    <div id="purchaseColorOptions" class="flex flex-wrap gap-3"></div>
                                                </div>

                                                <div id="purchaseSizeSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب سایز</label>
                                                    <div id="purchaseSizeOptions" class="grid grid-cols-6 gap-2"></div>
                                                </div>

                                                <div id="purchaseQuantitySelection" class="hidden">
                                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                                            <input type="number" id="purchaseQuantityInput" min="1" value="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                        </div>
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">قیمت خرید (تومان)</label>
                                                            <input type="number" id="purchaseCostInput" step="0.01" min="0" value="0" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                        </div>
                                                        <div class="flex items-end">
                                                            <button type="button" onclick="addItemToPurchase()" class="w-full bg-emerald-500 text-white py-2 rounded-lg hover:bg-emerald-600 transition-colors">
                                                                افزودن به خرید
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div id="purchaseStockInfo" class="mt-2 text-sm text-gray-500"></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium text-gray-700 mb-3">جزئیات خرید</h4>
                                        <div class="bg-gray-50 p-4 rounded-lg">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">تأمین‌کننده</label>
                                                    <input type="text" name="supplier_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500" placeholder="نام تأمین‌کننده را وارد کنید">
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ خرید</label>
                                                    <input type="date" name="purchase_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                                    <select name="status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                                        <option value="pending">در انتظار دریافت</option>
                                                        <option value="received">دریافت شده</option>
                                                        <option value="cancelled">لغو شده</option>
                                                    </select>
                                                </div>
                                                <div class="pt-4 border-t border-gray-200">
                                                    <div class="flex justify-between mb-2">
                                                        <span class="text-gray-600">مجموع جزئی</span>
                                                        <span id="purchaseSubtotal" class="font-medium">0 تومان</span>
                                                    </div>
                                                    <div class="flex justify-between font-bold text-lg mt-3 pt-3 border-t border-gray-200">
                                                        <span>مجموع کل</span>
                                                        <span id="purchaseTotal" class="text-emerald-600">0 تومان</span>
                                                    </div>
                                                </div>

                                                <div id="purchaseItemsInputs"></div>

                                                <button type="submit" name="create_purchase" onclick="return validatePurchaseForm()" class="w-full bg-emerald-500 text-white py-3 rounded-lg hover:bg-emerald-600 transition-colors mt-4 flex items-center justify-center">
                                                    <i data-feather="check" class="ml-2 w-5 h-5"></i>
                                                    تکمیل خرید
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Purchase Modal -->
                <div id="editPurchaseModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش خرید</h3>
                                <button onclick="closeModal('editPurchaseModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="purchase_id" id="edit_purchase_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تأمین‌کننده</label>
                                        <input type="text" name="supplier_name" id="edit_supplier_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ خرید</label>
                                        <input type="date" name="purchase_date" id="edit_purchase_date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                        <select name="status" id="edit_status" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                            <option value="pending">در انتظار دریافت</option>
                                            <option value="received">دریافت شده</option>
                                            <option value="cancelled">لغو شده</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="edit_purchase" class="w-full bg-emerald-500 text-white py-2 rounded-lg hover:bg-emerald-600 transition-colors flex items-center justify-center">
                                        <i data-feather="save" class="ml-2 w-4 h-4"></i>
                                        ذخیره تغییرات
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Purchase Items Modal -->
                <div id="purchaseItemsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">آیتم‌های خرید</h3>
                                <div class="flex items-center space-x-2 space-x-reverse">
                                    <button onclick="openAddPurchaseItemModal()" id="openAddItemBtn" class="flex items-center px-3 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-colors">
                                        <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                                        آیتم جدید
                                    </button>
                                    <button onclick="closeModal('purchaseItemsModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                        <i data-feather="x"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="purchaseItemsContent"></div>
                        </div>
                    </div>
                </div>

                <!-- Edit Purchase Item Modal -->
                <div id="editPurchaseItemModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش آیتم خرید</h3>
                                <button onclick="closeModal('editPurchaseItemModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="purchase_item_id" id="edit_purchase_item_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">محصول</label>
                                        <select name="variant_id" id="edit_item_variant_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                            <option value="">انتخاب محصول...</option>
                                            <?php
                                            if ($all_variants_for_select instanceof mysqli_result) {
                                                while ($variant = $all_variants_for_select->fetch_assoc()) {
                                                    $variant_id = (int) $variant['variant_id'];
                                                    $model_name = htmlspecialchars($variant['model_name'], ENT_QUOTES, 'UTF-8');
                                                    $color = htmlspecialchars($variant['color'], ENT_QUOTES, 'UTF-8');
                                                    $size = htmlspecialchars($variant['size'], ENT_QUOTES, 'UTF-8');
                                                    echo "<option value='{$variant_id}'>{$model_name} - {$color} / {$size}</option>";
                                                }
                                                $all_variants_for_select->free();
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                        <input type="number" name="quantity" id="edit_item_quantity" min="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">قیمت خرید</label>
                                        <input type="number" name="cost_price" id="edit_item_cost_price" step="0.01" min="0" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <button type="submit" name="edit_purchase_item" class="w-full bg-emerald-500 text-white py-2 rounded-lg hover:bg-emerald-600 transition-colors flex items-center justify-center">
                                        <i data-feather="save" class="ml-2 w-4 h-4"></i>
                                        ذخیره تغییرات
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Add Purchase Item Modal -->
                <div id="addPurchaseItemModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">افزودن آیتم به خرید</h3>
                                <button onclick="closeModal('addPurchaseItemModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST" id="addPurchaseItemForm">
                                <input type="hidden" name="purchase_id" id="add_purchase_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">محصول</label>
                                        <select name="variant_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                            <option value="">انتخاب محصول...</option>
                                            <?php
                                            $variantsForAdd = $conn->query('SELECT pv.*, p.model_name FROM Product_Variants pv JOIN Products p ON pv.product_id = p.product_id ORDER BY p.model_name, pv.color, pv.size');
                                            while ($variant = $variantsForAdd->fetch_assoc()) {
                                                $variant_id = (int) $variant['variant_id'];
                                                $model_name = htmlspecialchars($variant['model_name'], ENT_QUOTES, 'UTF-8');
                                                $color = htmlspecialchars($variant['color'], ENT_QUOTES, 'UTF-8');
                                                $size = htmlspecialchars($variant['size'], ENT_QUOTES, 'UTF-8');
                                                echo "<option value='{$variant_id}'>{$model_name} - {$color} / {$size}</option>";
                                            }
                                            $variantsForAdd->free();
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                        <input type="number" name="quantity" min="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">قیمت خرید</label>
                                        <input type="number" name="cost_price" step="0.01" min="0" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-emerald-500">
                                    </div>
                                    <button type="submit" name="add_purchase_item" class="w-full bg-emerald-500 text-white py-2 rounded-lg hover:bg-emerald-600 transition-colors flex items-center justify-center">
                                        <i data-feather="plus-circle" class="ml-2 w-4 h-4"></i>
                                        افزودن آیتم
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

        let purchaseItems = [];
        let purchaseItemCounter = 0;
        let selectedProductId = null;
        let selectedColor = null;
        let selectedSize = null;
        let currentVariants = [];
        let currentVariantInfo = null;
        let activePurchaseIdForItemsModal = null;

        function openModal(modalId) {
            if (modalId === 'newPurchaseModal') {
                resetNewPurchaseModal();
            }
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function resetNewPurchaseModal() {
            purchaseItems = [];
            purchaseItemCounter = 0;
            selectedProductId = null;
            selectedColor = null;
            selectedSize = null;
            currentVariants = [];
            currentVariantInfo = null;

            document.getElementById('purchaseSelectedItems').innerHTML = '';
            document.getElementById('purchaseItemsInputs').innerHTML = '';
            document.getElementById('purchaseSubtotal').textContent = '0 تومان';
            document.getElementById('purchaseTotal').textContent = '0 تومان';
            document.getElementById('purchaseProductSelect').value = '';
            document.getElementById('purchaseQuantityInput').value = '1';
            document.getElementById('purchaseCostInput').value = '0';
            document.getElementById('purchaseColorOptions').innerHTML = '';
            document.getElementById('purchaseSizeOptions').innerHTML = '';
            document.getElementById('purchaseStockInfo').textContent = '';

            document.getElementById('purchaseColorSelection').classList.add('hidden');
            document.getElementById('purchaseSizeSelection').classList.add('hidden');
            document.getElementById('purchaseQuantitySelection').classList.add('hidden');
        }

        document.getElementById('purchaseProductSelect').addEventListener('change', function() {
            const productId = this.value;
            selectedProductId = productId;

            if (!productId) {
                document.getElementById('purchaseColorSelection').classList.add('hidden');
                document.getElementById('purchaseSizeSelection').classList.add('hidden');
                document.getElementById('purchaseQuantitySelection').classList.add('hidden');
                return;
            }

            fetch('get_product_colors.php?product_id=' + productId)
                .then(response => response.json())
                .then(data => {
                    currentVariants = data.variants || [];
                    const colorOptions = document.getElementById('purchaseColorOptions');
                    colorOptions.innerHTML = '';

                    if (!data.colors || data.colors.length === 0) {
                        colorOptions.innerHTML = '<p class="text-gray-500">هیچ رنگی برای این محصول موجود نیست</p>';
                        return;
                    }

                    document.getElementById('purchaseColorSelection').classList.remove('hidden');
                    document.getElementById('purchaseSizeSelection').classList.add('hidden');
                    document.getElementById('purchaseQuantitySelection').classList.add('hidden');

                    data.colors.forEach(color => {
                        const colorOption = document.createElement('div');
                        colorOption.className = 'color-option';
                        colorOption.textContent = color.color_name || color.color;
                        colorOption.setAttribute('data-color', color.color);

                        colorOption.addEventListener('click', function() {
                            selectPurchaseColor(color.color);
                        });

                        colorOptions.appendChild(colorOption);
                    });
                })
                .catch(() => {
                    document.getElementById('purchaseColorOptions').innerHTML = '<p class="text-red-500">خطا در بارگذاری رنگ‌ها</p>';
                });
        });

        function selectPurchaseColor(color) {
            selectedColor = color;
            selectedSize = null;
            currentVariantInfo = null;

            document.querySelectorAll('#purchaseColorOptions .color-option').forEach(option => {
                if (option.getAttribute('data-color') === color) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });

            loadPurchaseSizes(selectedProductId, color);
        }

        function loadPurchaseSizes(productId, color) {
            const sizeOptions = document.getElementById('purchaseSizeOptions');
            sizeOptions.innerHTML = '<div class="col-span-6 flex justify-center"><div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span></div>';
            document.getElementById('purchaseSizeSelection').classList.remove('hidden');
            document.getElementById('purchaseQuantitySelection').classList.add('hidden');

            const sizeMap = {};
            currentVariants.forEach(variant => {
                if (variant.product_id == productId && variant.color === color) {
                    sizeMap[variant.size] = {
                        size: variant.size,
                        stock: variant.stock,
                        price: variant.price,
                        variant_id: variant.variant_id
                    };
                }
            });

            const availableSizes = Object.values(sizeMap);
            availableSizes.sort((a, b) => {
                const order = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
                const indexA = order.indexOf(a.size);
                const indexB = order.indexOf(b.size);
                if (indexA === -1 || indexB === -1) {
                    return a.size.localeCompare(b.size, 'fa');
                }
                return indexA - indexB;
            });

            sizeOptions.innerHTML = '';

            if (availableSizes.length === 0) {
                sizeOptions.innerHTML = '<p class="col-span-6 text-gray-500 text-center py-4">هیچ سایزی برای این رنگ موجود نیست</p>';
                return;
            }

            availableSizes.forEach(sizeInfo => {
                const sizeOption = document.createElement('div');
                sizeOption.className = 'size-option';
                sizeOption.textContent = sizeInfo.size;
                sizeOption.setAttribute('data-size', sizeInfo.size);
                sizeOption.setAttribute('data-stock', sizeInfo.stock);
                sizeOption.setAttribute('data-price', sizeInfo.price);
                sizeOption.setAttribute('data-variant-id', sizeInfo.variant_id);

                const stockIndicator = document.createElement('div');
                stockIndicator.className = 'text-xs text-gray-500 mt-1';
                stockIndicator.textContent = `موجودی فعلی: ${sizeInfo.stock}`;
                sizeOption.appendChild(stockIndicator);

                sizeOption.addEventListener('click', function() {
                    selectPurchaseSize(sizeInfo.size, sizeInfo.stock, sizeInfo.price, sizeInfo.variant_id);
                });

                sizeOptions.appendChild(sizeOption);
            });
        }

        function selectPurchaseSize(size, stock, price, variantId) {
            selectedSize = size;
            currentVariantInfo = { variantId, stock, price };

            document.querySelectorAll('#purchaseSizeOptions .size-option').forEach(option => {
                if (option.getAttribute('data-size') === size) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });

            document.getElementById('purchaseQuantitySelection').classList.remove('hidden');
            const stockInfo = document.getElementById('purchaseStockInfo');
            stockInfo.textContent = `موجودی فعلی در انبار: ${stock}`;
            const costInput = document.getElementById('purchaseCostInput');
            costInput.value = price;
        }

        function addItemToPurchase() {
            if (!currentVariantInfo || !selectedProductId || !selectedColor || !selectedSize) {
                alert('لطفاً محصول، رنگ و سایز را انتخاب کنید.');
                return;
            }

            const quantity = parseInt(document.getElementById('purchaseQuantityInput').value, 10);
            const costPrice = parseFloat(document.getElementById('purchaseCostInput').value);

            if (!quantity || quantity < 1) {
                alert('لطفاً تعداد معتبر وارد کنید.');
                return;
            }

            if (!costPrice || costPrice <= 0) {
                alert('لطفاً قیمت خرید معتبر وارد کنید.');
                return;
            }

            const productSelect = document.getElementById('purchaseProductSelect');
            const productName = productSelect.options[productSelect.selectedIndex].text;

            const existingItem = purchaseItems.find(item => item.variantId === currentVariantInfo.variantId);
            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.costPrice = costPrice;
                existingItem.total = existingItem.quantity * costPrice;
                updatePurchaseSelectedItems();
            } else {
                const item = {
                    id: purchaseItemCounter++,
                    variantId: currentVariantInfo.variantId,
                    productName: `${productName} - ${selectedColor} - ${selectedSize}`,
                    quantity: quantity,
                    costPrice: costPrice,
                    total: quantity * costPrice
                };
                purchaseItems.push(item);
                appendPurchaseItem(item);
            }

            updatePurchaseTotals();
            updatePurchaseHiddenInputs();

            selectedColor = null;
            selectedSize = null;
            currentVariantInfo = null;
            selectedProductId = null;
            currentVariants = [];

            document.getElementById('purchaseColorSelection').classList.add('hidden');
            document.getElementById('purchaseSizeSelection').classList.add('hidden');
            document.getElementById('purchaseQuantitySelection').classList.add('hidden');
            document.getElementById('purchaseProductSelect').value = '';
            document.getElementById('purchaseColorOptions').innerHTML = '';
            document.getElementById('purchaseSizeOptions').innerHTML = '';
            document.getElementById('purchaseStockInfo').textContent = '';
            document.getElementById('purchaseQuantityInput').value = '1';
            document.getElementById('purchaseCostInput').value = '0';
        }

        function appendPurchaseItem(item) {
            const container = document.getElementById('purchaseSelectedItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-white p-4 rounded-lg border border-gray-200 flex items-center justify-between';
            itemDiv.id = `purchase-item-${item.id}`;
            itemDiv.innerHTML = `
                <div class="flex-1">
                    <h5 class="font-medium text-gray-800">${item.productName}</h5>
                    <div class="text-sm text-gray-500">تعداد: ${item.quantity} × ${item.costPrice.toLocaleString()} تومان</div>
                </div>
                <div class="text-left">
                    <div class="font-bold text-gray-800">${item.total.toLocaleString()} تومان</div>
                </div>
                <button onclick="removePurchaseItem(${item.id})" class="text-red-500 hover:text-red-700 mr-2 transition-colors">
                    <i data-feather="trash-2" class="w-4 h-4"></i>
                </button>
            `;
            container.appendChild(itemDiv);
            feather.replace();
        }

        function updatePurchaseSelectedItems() {
            const container = document.getElementById('purchaseSelectedItems');
            container.innerHTML = '';
            purchaseItems.forEach(item => appendPurchaseItem(item));
        }

        function removePurchaseItem(itemId) {
            purchaseItems = purchaseItems.filter(item => item.id !== itemId);
            const itemEl = document.getElementById(`purchase-item-${itemId}`);
            if (itemEl) {
                itemEl.remove();
            }
            updatePurchaseTotals();
            updatePurchaseHiddenInputs();
        }

        function updatePurchaseTotals() {
            const subtotal = purchaseItems.reduce((sum, item) => sum + item.total, 0);
            document.getElementById('purchaseSubtotal').textContent = subtotal.toLocaleString() + ' تومان';
            document.getElementById('purchaseTotal').textContent = subtotal.toLocaleString() + ' تومان';
        }

        function updatePurchaseHiddenInputs() {
            const inputsContainer = document.getElementById('purchaseItemsInputs');
            inputsContainer.innerHTML = '';
            purchaseItems.forEach((item, index) => {
                inputsContainer.innerHTML += `
                    <input type="hidden" name="items[${index}][variant_id]" value="${item.variantId}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                    <input type="hidden" name="items[${index}][cost_price]" value="${item.costPrice}">
                `;
            });
        }

        function validatePurchaseForm() {
            if (purchaseItems.length === 0) {
                alert('لطفاً حداقل یک آیتم به خرید اضافه کنید.');
                return false;
            }
            return true;
        }

        function openEditPurchaseModal(purchaseId, supplierName, purchaseDate, status) {
            document.getElementById('edit_purchase_id').value = purchaseId;
            document.getElementById('edit_supplier_name').value = supplierName;
            document.getElementById('edit_purchase_date').value = purchaseDate;
            document.getElementById('edit_status').value = status;
            openModal('editPurchaseModal');
        }

        function openEditPurchaseItemModal(purchaseItemId, variantId, quantity, costPrice) {
            document.getElementById('edit_purchase_item_id').value = purchaseItemId;
            document.getElementById('edit_item_variant_id').value = variantId;
            document.getElementById('edit_item_quantity').value = quantity;
            document.getElementById('edit_item_cost_price').value = costPrice;
            openModal('editPurchaseItemModal');
        }

        function showPurchaseItems(purchaseId) {
            activePurchaseIdForItemsModal = purchaseId;
            document.getElementById('add_purchase_id').value = purchaseId;
            fetch('get_purchase_items.php?purchase_id=' + purchaseId)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('purchaseItemsContent').innerHTML = html;
                    feather.replace();
                    openModal('purchaseItemsModal');
                })
                .catch(() => {
                    document.getElementById('purchaseItemsContent').innerHTML = '<div class="text-center text-red-500 py-6">خطا در بارگذاری آیتم‌ها</div>';
                    openModal('purchaseItemsModal');
                });
        }

        function openAddPurchaseItemModal() {
            if (!activePurchaseIdForItemsModal) {
                alert('ابتدا خرید موردنظر را انتخاب کنید.');
                return;
            }
            document.getElementById('add_purchase_id').value = activePurchaseIdForItemsModal;
            openModal('addPurchaseItemModal');
        }

        $(document).ready(function() {
            $('#purchasesTable').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.4/i18n/fa.json'
                }
            });
        });
    </script>
</body>
</html>
