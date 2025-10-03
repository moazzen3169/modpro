<?php
require_once __DIR__ . '/env/bootstrap.php';

function handle_create_return(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $purchase_id = validate_int($_POST['purchase_id'] ?? null, 1);
        $return_date = validate_date((string)($_POST['return_date'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));

        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || $raw_items === []) {
            throw new InvalidArgumentException('برای ثبت مرجوعی تأمین‌کننده حداقل یک آیتم لازم است.');
        }

        $purchaseStmt = $conn->prepare('SELECT purchase_id, supplier_id FROM Purchases WHERE purchase_id = ?');
        $purchaseStmt->bind_param('i', $purchase_id);
        $purchaseStmt->execute();
        $purchase = $purchaseStmt->get_result()->fetch_assoc();
        if (!$purchase) {
            throw new RuntimeException('خرید انتخاب‌شده یافت نشد.');
        }
        $supplier_id = (int) $purchase['supplier_id'];

        $purchaseItemsStmt = $conn->prepare('SELECT purchase_item_id, variant_id, quantity, buy_price FROM Purchase_Items WHERE purchase_id = ?');
        $purchaseItemsStmt->bind_param('i', $purchase_id);
        $purchaseItemsStmt->execute();
        $purchaseItemsResult = $purchaseItemsStmt->get_result();

        $purchaseItems = [];
        while ($row = $purchaseItemsResult->fetch_assoc()) {
            $purchaseItems[(int) $row['purchase_item_id']] = [
                'variant_id' => (int) $row['variant_id'],
                'quantity' => (int) $row['quantity'],
                'buy_price' => (float) $row['buy_price'],
            ];
        }

        if ($purchaseItems === []) {
            throw new RuntimeException('هیچ آیتمی برای این خرید ثبت نشده است.');
        }

        $returnItems = [];
        $totalAmount = 0.0;
        $requestedQuantities = [];

        $returnedQtyStmt = $conn->prepare(
            'SELECT COALESCE(SUM(ri.quantity), 0) AS returned_quantity
             FROM Return_Items ri
             JOIN Returns r ON ri.return_id = r.return_id
             WHERE ri.purchase_item_id = ? AND r.purchase_id = ?'
        );

        $variantLockStmt = $conn->prepare('SELECT stock FROM Product_Variants WHERE variant_id = ? FOR UPDATE');

        foreach ($raw_items as $item) {
            $purchase_item_id = validate_int($item['purchase_item_id'] ?? null, 1);
            $variant_id = validate_int($item['variant_id'] ?? null, 1);
            $quantity = validate_int($item['quantity'] ?? null, 1);

            if (!isset($purchaseItems[$purchase_item_id])) {
                throw new RuntimeException('آیتم انتخاب‌شده متعلق به این خرید نیست.');
            }

            $purchaseItem = $purchaseItems[$purchase_item_id];
            if ($purchaseItem['variant_id'] !== $variant_id) {
                throw new RuntimeException('تنوع انتخاب‌شده با آیتم خرید همخوانی ندارد.');
            }

            $returnedQtyStmt->bind_param('ii', $purchase_item_id, $purchase_id);
            $returnedQtyStmt->execute();
            $returnedQtyResult = $returnedQtyStmt->get_result()->fetch_assoc();
            $alreadyReturned = (int) ($returnedQtyResult['returned_quantity'] ?? 0);

            $availableForReturn = $purchaseItem['quantity'] - $alreadyReturned;
            if ($availableForReturn <= 0) {
                throw new RuntimeException('برای یکی از آیتم‌های انتخاب‌شده امکان مرجوعی باقی نمانده است.');
            }

            $alreadyRequested = $requestedQuantities[$purchase_item_id] ?? 0;
            if ($quantity + $alreadyRequested > $availableForReturn) {
                throw new RuntimeException('تعداد مرجوعی انتخاب‌شده از حداکثر قابل بازگشت بیشتر است.');
            }

            $variantLockStmt->bind_param('i', $variant_id);
            $variantLockStmt->execute();
            $variantRow = $variantLockStmt->get_result()->fetch_assoc();
            if (!$variantRow) {
                throw new RuntimeException('تنوع انتخاب‌شده وجود ندارد.');
            }

            $currentStock = (int) $variantRow['stock'];
            if ($currentStock < $quantity) {
                throw new RuntimeException('موجودی کافی برای کاهش انبار در دسترس نیست.');
            }

            $requestedQuantities[$purchase_item_id] = $alreadyRequested + $quantity;

            $price = $purchaseItem['buy_price'];
            $totalAmount += $quantity * $price;
            $returnItems[] = [
                'purchase_item_id' => $purchase_item_id,
                'variant_id' => $variant_id,
                'quantity' => $quantity,
                'price' => $price,
            ];
        }

        $insertReturnStmt = $conn->prepare('INSERT INTO Returns (purchase_id, supplier_id, return_date, reason, total_amount) VALUES (?, ?, ?, ?, ?)');
        $insertReturnStmt->bind_param('iissd', $purchase_id, $supplier_id, $return_date, $reason, $totalAmount);
        $insertReturnStmt->execute();
        $return_id = (int) $conn->insert_id;

        $insertItemStmt = $conn->prepare('INSERT INTO Return_Items (return_id, purchase_item_id, variant_id, quantity, return_price) VALUES (?, ?, ?, ?, ?)');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock - ? WHERE variant_id = ?');

        foreach ($returnItems as $returnItem) {
            $insertItemStmt->bind_param('iiiid', $return_id, $returnItem['purchase_item_id'], $returnItem['variant_id'], $returnItem['quantity'], $returnItem['price']);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('ii', $returnItem['quantity'], $returnItem['variant_id']);
            $updateStockStmt->execute();
        }

        $conn->commit();
        redirect_with_message('returns.php', 'success', 'مرجوعی تأمین‌کننده با موفقیت ثبت شد و موجودی کاهش یافت.');
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

$purchasesResult = $conn->query('SELECT p.purchase_id, p.purchase_date, s.name AS supplier_name FROM Purchases p JOIN Suppliers s ON p.supplier_id = s.supplier_id ORDER BY p.purchase_date DESC, p.purchase_id DESC');
$purchases = [];
if ($purchasesResult) {
    while ($row = $purchasesResult->fetch_assoc()) {
        $purchases[] = [
            'purchase_id' => (int) $row['purchase_id'],
            'purchase_date' => (string) $row['purchase_date'],
            'supplier_name' => (string) $row['supplier_name'],
        ];
    }
}
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

    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <?php include 'sidebar.php'; ?>

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
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تأمین‌کننده</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">شماره خرید</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تاریخ</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">دلیل</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">تعداد آیتم</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">مبلغ مرجوعی</th>
                                <th class="px-6 py-4 text-right text-xs font-bold text-blue-700 uppercase tracking-wider">عملیات</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-100">
                            <?php
$returnsQuery = "
SELECT
    r.return_id,
    r.return_date,
    r.reason,
    r.total_amount,
    p.purchase_id,
    s.name AS supplier_name,
    COUNT(ri.return_item_id) AS item_count
FROM Returns r
JOIN Purchases p ON r.purchase_id = p.purchase_id
JOIN Suppliers s ON r.supplier_id = s.supplier_id
LEFT JOIN Return_Items ri ON r.return_id = ri.return_id
GROUP BY r.return_id, r.return_date, r.reason, r.total_amount, p.purchase_id, supplier_name
ORDER BY r.return_date DESC, r.return_id DESC";
$returns = $conn->query($returnsQuery);

                            if ($returns && $returns->num_rows > 0) {
                                while($return = $returns->fetch_assoc()){
                                    $return_id = (int) $return['return_id'];
                                    $supplier_name = htmlspecialchars((string) $return['supplier_name'], ENT_QUOTES, 'UTF-8');
                                    $purchase_id = (int) $return['purchase_id'];
                                    $return_date = htmlspecialchars((string) $return['return_date'], ENT_QUOTES, 'UTF-8');
                                    $reason = htmlspecialchars((string) ($return['reason'] ?? ''), ENT_QUOTES, 'UTF-8');
                                    $item_count = (int) $return['item_count'];
                                    $total_amount = number_format((float) ($return['total_amount'] ?? 0), 0);

                                    echo "<tr class='hover:bg-gray-50'>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900'>#مرجوعی-{$return_id}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>{$supplier_name}</td>
                                            <td class='px-6 py-4 whitespace-nowrap text-sm text-gray-500'>#خرید-{$purchase_id}</td>
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
                                        <h4 class="font-medium text-gray-700 mb-3">انتخاب آیتم‌ها</h4>

                                        <!-- Selected Items -->
                                        <div id="selectedReturnItems" class="space-y-3 mb-4 max-h-60 overflow-y-auto p-2">
                                            <!-- Items will be added here dynamically -->
                                        </div>

                                        <!-- Add Item Form -->
                                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب آیتم خرید</label>
                                                    <select id="returnVariantSelect" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100" disabled>
                                                        <option value="">ابتدا خرید را انتخاب کنید</option>
                                                    </select>
                                                </div>

                                                <div id="returnVariantInfo" class="bg-white border border-gray-200 rounded-lg p-3 text-sm hidden">
                                                    <div class="flex justify-between">
                                                        <span class="text-gray-600">موجودی قابل مرجوع:</span>
                                                        <span id="variantAvailable" class="font-medium text-gray-800">0</span>
                                                    </div>
                                                    <div class="flex justify-between mt-2">
                                                        <span class="text-gray-600">قیمت خرید:</span>
                                                        <span id="variantPrice" class="font-medium text-gray-800">0 تومان</span>
                                                    </div>
                                                </div>

                                                <div class="grid grid-cols-2 gap-4">
                                                    <div>
                                                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                                        <input type="number" id="returnQuantityInput" min="1" value="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100" disabled>
                                                    </div>
                                                    <div class="flex items-end">
                                                        <button type="button" id="addReturnItemButton" onclick="addItemToReturn()" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                                                            افزودن به مرجوعی
                                                        </button>
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
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">انتخاب خرید</label>
                                                    <select name="purchase_id" id="returnPurchaseSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100" <?php echo empty($purchases) ? 'disabled' : ''; ?>>
                                                        <option value="">انتخاب خرید...</option>
                                                        <?php foreach ($purchases as $purchase): ?>
                                                            <option value="<?php echo $purchase['purchase_id']; ?>">
                                                                <?php echo '#خرید-' . $purchase['purchase_id'] . ' | ' . htmlspecialchars($purchase['supplier_name'], ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($purchase['purchase_date'], ENT_QUOTES, 'UTF-8'); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <?php if (empty($purchases)): ?>
                                                        <p class="text-sm text-red-500 mt-2">هیچ خریدی برای ثبت مرجوعی موجود نیست.</p>
                                                    <?php endif; ?>
                                                </div>

                                                <div id="purchaseSummary" class="bg-white border border-gray-200 rounded-lg p-3 text-sm">
                                                    <div class="flex justify-between">
                                                        <span class="text-gray-600">تأمین‌کننده:</span>
                                                        <span id="summarySupplier" class="font-medium text-gray-800">—</span>
                                                    </div>
                                                    <div class="flex justify-between mt-2">
                                                        <span class="text-gray-600">تاریخ خرید:</span>
                                                        <span id="summaryPurchaseDate" class="font-medium text-gray-800">—</span>
                                                    </div>
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
        let currentReturnVariants = [];
        let selectedPurchaseId = null;

        const purchaseSelect = document.getElementById('returnPurchaseSelect');
        const variantSelect = document.getElementById('returnVariantSelect');
        const quantityInput = document.getElementById('returnQuantityInput');
        const addItemButton = document.getElementById('addReturnItemButton');
        const variantInfoBox = document.getElementById('returnVariantInfo');
        const variantAvailableSpan = document.getElementById('variantAvailable');
        const variantPriceSpan = document.getElementById('variantPrice');
        const summarySupplier = document.getElementById('summarySupplier');
        const summaryPurchaseDate = document.getElementById('summaryPurchaseDate');

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
            currentReturnVariants = [];
            selectedPurchaseId = null;

            document.getElementById('selectedReturnItems').innerHTML = '';
            document.getElementById('returnItemsInputs').innerHTML = '';
            document.getElementById('returnSubtotal').textContent = '0 تومان';
            document.getElementById('returnTotal').textContent = '0 تومان';

            if (purchaseSelect) {
                purchaseSelect.value = '';
            }

            clearVariantSelection();
            updatePurchaseSummary('—', '—');
        }

        function clearVariantSelection() {
            if (variantSelect) {
                variantSelect.innerHTML = '<option value="">ابتدا خرید را انتخاب کنید</option>';
                variantSelect.disabled = true;
            }
            if (quantityInput) {
                quantityInput.value = '1';
                quantityInput.disabled = true;
            }
            if (addItemButton) {
                addItemButton.disabled = true;
            }
            if (variantInfoBox) {
                variantInfoBox.classList.add('hidden');
            }
        }

        function updatePurchaseSummary(supplier, date) {
            const supplierText = supplier ? supplier : '—';
            const dateText = date ? date : '—';

            if (summarySupplier) {
                summarySupplier.textContent = supplierText;
            }
            if (summaryPurchaseDate) {
                summaryPurchaseDate.textContent = dateText;
            }
        }

        if (purchaseSelect) {
            purchaseSelect.addEventListener('change', handlePurchaseChange);
        }

        function handlePurchaseChange() {
            selectedPurchaseId = purchaseSelect.value || null;
            returnItems = [];
            returnItemCounter = 0;
            currentReturnVariants = [];

            document.getElementById('selectedReturnItems').innerHTML = '';
            document.getElementById('returnItemsInputs').innerHTML = '';
            document.getElementById('returnSubtotal').textContent = '0 تومان';
            document.getElementById('returnTotal').textContent = '0 تومان';

            clearVariantSelection();

            if (!selectedPurchaseId) {
                updatePurchaseSummary('—', '—');
                return;
            }

            if (variantSelect) {
                variantSelect.disabled = true;
                variantSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';
            }

            fetch('get_purchase_return_items.php?purchase_id=' + selectedPurchaseId)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        throw new Error(data.message || 'خطا در دریافت اطلاعات خرید');
                    }

                    currentReturnVariants = data.items || [];
                    const purchaseInfo = data.purchase || {};
                    updatePurchaseSummary(purchaseInfo.supplier_name || '—', purchaseInfo.purchase_date || '—');

                    const availableItems = currentReturnVariants.filter(item => item.available_quantity > 0);

                    if (!variantSelect) {
                        return;
                    }

                    if (availableItems.length === 0) {
                        variantSelect.innerHTML = '<option value="">هیچ آیتمی برای مرجوعی باقی نمانده است</option>';
                        quantityInput.disabled = true;
                        addItemButton.disabled = true;
                        variantInfoBox.classList.add('hidden');
                        return;
                    }

                    variantSelect.disabled = false;
                    variantSelect.innerHTML = '<option value="">انتخاب آیتم...</option>';
                    availableItems.forEach(item => {
                        const label = `${item.product_name} - ${item.color} - ${item.size}`;
                        const option = document.createElement('option');
                        option.value = item.purchase_item_id;
                        option.textContent = `${label} | موجود: ${item.available_quantity}`;
                        variantSelect.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error loading purchase items:', error);
                    updatePurchaseSummary('—', '—');
                    if (variantSelect) {
                        variantSelect.innerHTML = '<option value="">خطا در بارگذاری آیتم‌های خرید</option>';
                        variantSelect.disabled = true;
                    }
                    if (quantityInput) {
                        quantityInput.disabled = true;
                    }
                    if (addItemButton) {
                        addItemButton.disabled = true;
                    }
                    if (variantInfoBox) {
                        variantInfoBox.classList.add('hidden');
                    }
                });
        }

        if (variantSelect) {
            variantSelect.addEventListener('change', handleVariantChange);
        }

        function handleVariantChange() {
            if (!variantSelect) {
                return;
            }

            const purchaseItemId = variantSelect.value;
            if (!purchaseItemId) {
                quantityInput.disabled = true;
                addItemButton.disabled = true;
                variantInfoBox.classList.add('hidden');
                return;
            }

            const variant = currentReturnVariants.find(item => String(item.purchase_item_id) === purchaseItemId);
            if (!variant) {
                quantityInput.disabled = true;
                addItemButton.disabled = true;
                variantInfoBox.classList.add('hidden');
                return;
            }

            const remaining = getRemainingQuantityForItem(variant.purchase_item_id, variant.available_quantity);
            quantityInput.value = remaining > 0 ? '1' : '0';
            quantityInput.max = remaining;
            quantityInput.disabled = remaining <= 0;
            addItemButton.disabled = remaining <= 0;

            variantAvailableSpan.textContent = remaining.toLocaleString('fa-IR');
            variantPriceSpan.textContent = `${Number(variant.buy_price).toLocaleString('fa-IR')} تومان`;
            variantInfoBox.classList.remove('hidden');
        }

        function getRemainingQuantityForItem(purchaseItemId, totalAvailable) {
            const existingItem = returnItems.find(item => item.purchaseItemId === purchaseItemId);
            const used = existingItem ? existingItem.quantity : 0;
            const remaining = totalAvailable - used;
            return remaining > 0 ? remaining : 0;
        }

        function addItemToReturn() {
            if (!selectedPurchaseId) {
                alert('لطفاً ابتدا خریدی را انتخاب کنید.');
                return;
            }

            if (!variantSelect || !variantSelect.value) {
                alert('لطفاً آیتمی از خرید انتخاب کنید.');
                return;
            }

            const variant = currentReturnVariants.find(item => String(item.purchase_item_id) === variantSelect.value);
            if (!variant) {
                alert('آیتم انتخاب‌شده معتبر نیست.');
                return;
            }

            const quantity = parseInt(quantityInput.value, 10);
            if (!quantity || quantity <= 0) {
                alert('لطفاً تعداد معتبر وارد کنید.');
                return;
            }

            const remaining = getRemainingQuantityForItem(variant.purchase_item_id, variant.available_quantity);
            if (quantity > remaining) {
                alert('تعداد انتخاب‌شده بیشتر از مقدار قابل مرجوع است.');
                return;
            }

            const existingItem = returnItems.find(item => item.purchaseItemId === variant.purchase_item_id);
            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.total = existingItem.quantity * variant.buy_price;
            } else {
                const label = `${variant.product_name} - ${variant.color} - ${variant.size}`;
                returnItems.push({
                    id: returnItemCounter++,
                    purchaseItemId: variant.purchase_item_id,
                    variantId: variant.variant_id,
                    label: label,
                    quantity: quantity,
                    price: variant.buy_price,
                    total: quantity * variant.buy_price
                });
            }

            updateSelectedReturnItemsDisplay();
            updateReturnTotals();
            updateReturnHiddenInputs();
            handleVariantChange();
        }

        function addReturnItemToDisplay(item) {
            const selectedItems = document.getElementById('selectedReturnItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-white p-4 rounded-lg border border-gray-200 flex items-center justify-between';
            itemDiv.id = `return-item-${item.id}`;

            itemDiv.innerHTML = `
                <div class="flex-1">
                    <h5 class="font-medium text-gray-800">${item.label}</h5>
                    <div class="text-sm text-gray-500">تعداد: ${Number(item.quantity).toLocaleString('fa-IR')} × ${Number(item.price).toLocaleString('fa-IR')} تومان</div>
                </div>
                <div class="text-left">
                    <div class="font-bold text-gray-800">${Number(item.total).toLocaleString('fa-IR')} تومان</div>
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
            const element = document.getElementById(`return-item-${itemId}`);
            if (element) {
                element.remove();
            }
            updateReturnTotals();
            updateReturnHiddenInputs();
            handleVariantChange();
        }

        function updateSelectedReturnItemsDisplay() {
            const selectedItems = document.getElementById('selectedReturnItems');
            selectedItems.innerHTML = '';
            returnItems.forEach(item => addReturnItemToDisplay(item));
        }

        function updateReturnTotals() {
            const subtotal = returnItems.reduce((sum, item) => sum + item.total, 0);
            document.getElementById('returnSubtotal').textContent = subtotal.toLocaleString('fa-IR') + ' تومان';
            document.getElementById('returnTotal').textContent = subtotal.toLocaleString('fa-IR') + ' تومان';
        }

        function updateReturnHiddenInputs() {
            const inputsContainer = document.getElementById('returnItemsInputs');
            inputsContainer.innerHTML = '';

            returnItems.forEach((item, index) => {
                inputsContainer.innerHTML += `
                    <input type="hidden" name="items[${index}][purchase_item_id]" value="${item.purchaseItemId}">
                    <input type="hidden" name="items[${index}][variant_id]" value="${item.variantId}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                `;
            });
        }

        function validateReturnForm() {
            if (!purchaseSelect || !purchaseSelect.value) {
                alert('لطفاً خرید موردنظر را انتخاب کنید.');
                return false;
            }

            if (returnItems.length === 0) {
                alert('لطفاً حداقل یک آیتم به مرجوعی اضافه کنید.');
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
