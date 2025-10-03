<?php
require_once __DIR__ . '/env/bootstrap.php';
require_once __DIR__ . '/includes/product_helpers.php';

function handle_create_purchase(mysqli $conn): void
{
    $transactionStarted = false;

    try {
        $conn->begin_transaction();
        $transactionStarted = true;

        $supplier_id = validate_int($_POST['supplier_id'] ?? null, 1);
        $purchase_date = validate_date((string)($_POST['purchase_date'] ?? ''));
        $payment_method = validate_enum((string)($_POST['payment_method'] ?? ''), ['cash', 'credit_card', 'bank_transfer']);
        $status = validate_enum((string)($_POST['status'] ?? 'pending'), ['pending', 'paid']);

        $raw_items = $_POST['items'] ?? [];
        if (!is_array($raw_items) || $raw_items === []) {
            throw new InvalidArgumentException('برای ثبت خرید حداقل یک آیتم لازم است.');
        }

        $items = [];

        foreach ($raw_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $quantity = validate_int($item['quantity'] ?? null, 1);
            $buy_price = validate_price($item['buy_price'] ?? null);

            $variantRaw = $item['variant_id'] ?? null;
            $variant_id = ($variantRaw === null || $variantRaw === '') ? 0 : validate_int($variantRaw, 1);

            if ($variant_id > 0) {
                $items[] = [
                    'type' => 'existing',
                    'variant_id' => $variant_id,
                    'quantity' => $quantity,
                    'buy_price' => $buy_price,
                ];
                continue;
            }

            $product_id = validate_int($item['product_id'] ?? null, 1);
            $color = sanitize_text_field((string)($item['color'] ?? ''), 'وارد کردن رنگ برای تنوع جدید الزامی است.');
            $size = sanitize_text_field((string)($item['size'] ?? ''), 'وارد کردن سایز برای تنوع جدید الزامی است.');

            $sell_price_input = $item['sell_price'] ?? null;
            $sell_price = ($sell_price_input === null || $sell_price_input === '')
                ? $buy_price
                : validate_price($sell_price_input);

            $items[] = [
                'type' => 'new',
                'product_id' => $product_id,
                'color' => $color,
                'size' => $size,
                'quantity' => $quantity,
                'buy_price' => $buy_price,
                'sell_price' => $sell_price,
            ];
        }

        if ($items === []) {
            throw new InvalidArgumentException('برای ثبت خرید حداقل یک آیتم لازم است.');
        }

        $insertPurchaseStmt = $conn->prepare('INSERT INTO Purchases (supplier_id, purchase_date, payment_method, status) VALUES (?, ?, ?, ?)');
        $insertPurchaseStmt->bind_param('isss', $supplier_id, $purchase_date, $payment_method, $status);
        $insertPurchaseStmt->execute();
        $purchase_id = (int) $conn->insert_id;
        $insertPurchaseStmt->close();

        $variantLockStmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE variant_id = ? FOR UPDATE');
        $insertItemStmt = $conn->prepare('INSERT INTO Purchase_Items (purchase_id, variant_id, quantity, buy_price) VALUES (?, ?, ?, ?)');
        $updateStockStmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');

        foreach ($items as $item) {
            if ($item['type'] === 'existing') {
                $variant_id = $item['variant_id'];

                $variantLockStmt->bind_param('i', $variant_id);
                $variantLockStmt->execute();
                $variantResult = $variantLockStmt->get_result();
                $variantRow = $variantResult->fetch_assoc();

                if (!$variantRow) {
                    throw new RuntimeException('تنوع انتخاب‌شده یافت نشد.');
                }
            } else {
                $variant_id = ensure_product_variant(
                    $conn,
                    $item['product_id'],
                    $item['color'],
                    $item['size'],
                    $item['sell_price']
                );
            }

            $quantity = $item['quantity'];
            $buy_price = $item['buy_price'];

            $insertItemStmt->bind_param('iiid', $purchase_id, $variant_id, $quantity, $buy_price);
            $insertItemStmt->execute();

            $updateStockStmt->bind_param('ii', $quantity, $variant_id);
            $updateStockStmt->execute();
        }

        $variantLockStmt->close();
        $insertItemStmt->close();
        $updateStockStmt->close();

        $conn->commit();

        redirect_with_message('purchases.php', 'success', 'خرید جدید با موفقیت ثبت شد.');
    } catch (Throwable $e) {
        if ($transactionStarted) {
            $conn->rollback();
        }

        redirect_with_message('purchases.php', 'error', normalize_error_message($e));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_purchase'])) {
    handle_create_purchase($conn);
}

$flash_messages = get_flash_messages();

$suppliers = [];
$suppliersResult = $conn->query('SELECT supplier_id, name FROM Suppliers ORDER BY name');
if ($suppliersResult instanceof mysqli_result) {
    while ($row = $suppliersResult->fetch_assoc()) {
        $suppliers[] = $row;
    }
    $suppliersResult->free();
}

$products = [];
$productsResult = $conn->query('SELECT product_id, model_name FROM Products ORDER BY model_name');
if ($productsResult instanceof mysqli_result) {
    while ($row = $productsResult->fetch_assoc()) {
        $products[] = $row;
    }
    $productsResult->free();
}

// Query to get purchases grouped by year and month with summary
$purchasesByMonthQuery = "
    SELECT
        YEAR(p.purchase_date) AS purchase_year,
        MONTH(p.purchase_date) AS purchase_month,
        COUNT(DISTINCT p.purchase_id) AS purchases_count,
        SUM(pi.quantity * pi.buy_price) AS total_amount
    FROM Purchases p
    JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id
    GROUP BY purchase_year, purchase_month
    ORDER BY purchase_year DESC, purchase_month DESC
";

$purchasesByMonthResult = $conn->query($purchasesByMonthQuery);

function get_month_name(int $month): string {
    $months = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];
    return $months[$month] ?? '';
}

// Function to get detailed purchases for a given year and month
function get_purchase_details(mysqli $conn, int $year, int $month): array {
    $details = [];

    // Get purchases
    $purchasesStmt = $conn->prepare("
        SELECT
            p.purchase_id,
            p.purchase_date,
            s.name AS supplier_name,
            pi.quantity,
            pi.buy_price,
            pr.model_name,
            pv.color,
            pv.size,
            (pi.quantity * pi.buy_price) as total_amount
        FROM Purchases p
        JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products pr ON pv.product_id = pr.product_id
        JOIN Suppliers s ON p.supplier_id = s.supplier_id
        WHERE YEAR(p.purchase_date) = ? AND MONTH(p.purchase_date) = ?
        ORDER BY p.purchase_date ASC, p.purchase_id ASC
    ");
    $purchasesStmt->bind_param('ii', $year, $month);
    $purchasesStmt->execute();
    $purchasesResult = $purchasesStmt->get_result();
    while ($row = $purchasesResult->fetch_assoc()) {
        $details[] = $row;
    }
    $purchasesStmt->close();

    return $details;
}
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
        .details-table {
            max-height: 300px;
            overflow-y: auto;
        }
        .details-table table {
            width: 100%;
            border-collapse: collapse;
        }
        .details-table th, .details-table td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: right;
        }
        .details-table th {
            background-color: #f3f4f6;
            font-weight: 600;
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
                        <a href="purchases.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-700 rounded-lg border-r-2 border-blue-500">
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
        <div class="flex-1 overflow-auto p-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
                <h2 class="text-2xl font-semibold">مشاهده خریدها</h2>
                <button
                    id="open-purchase-modal"
                    class="inline-flex items-center justify-center px-5 py-2.5 bg-blue-600 text-white rounded-lg shadow-sm hover:bg-blue-700 transition"
                    type="button"
                >
                    <i data-feather="plus" class="ml-2 w-5 h-5"></i>
                    <span>ثبت خرید جدید</span>
                </button>
            </div>

            <div
                id="purchase-modal"
                class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 px-4"
            >
                <div class="bg-white w-full max-w-4xl rounded-2xl shadow-xl overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">ثبت خرید جدید</h3>
                        <button id="close-purchase-modal" type="button" class="text-gray-500 hover:text-gray-700">
                            <i data-feather="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                    <form method="POST" class="p-6 space-y-6" id="purchase-form">
                        <input type="hidden" name="create_purchase" value="1">

                        <div class="grid gap-4 md:grid-cols-2">
                            <div>
                                <label for="supplier-select" class="block text-sm font-medium text-gray-700 mb-1">تامین‌کننده</label>
                                <select
                                    id="supplier-select"
                                    name="supplier_id"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required
                                >
                                    <option value="">انتخاب تامین‌کننده</option>
                                    <?php if (empty($suppliers)): ?>
                                        <option value="" disabled>هیچ تامین‌کننده‌ای ثبت نشده است</option>
                                    <?php else: ?>
                                        <?php foreach ($suppliers as $supplier): ?>
                                            <option value="<?php echo htmlspecialchars($supplier['supplier_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div>
                                <label for="purchase-date" class="block text-sm font-medium text-gray-700 mb-1">تاریخ خرید</label>
                                <input
                                    type="date"
                                    id="purchase-date"
                                    name="purchase_date"
                                    value="<?php echo date('Y-m-d'); ?>"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required
                                >
                            </div>
                            <div>
                                <label for="payment-method" class="block text-sm font-medium text-gray-700 mb-1">روش پرداخت</label>
                                <select
                                    id="payment-method"
                                    name="payment_method"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required
                                >
                                    <option value="cash">نقدی</option>
                                    <option value="credit_card">کارت بانکی</option>
                                    <option value="bank_transfer">واریز بانکی</option>
                                </select>
                            </div>
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                <select
                                    id="status"
                                    name="status"
                                    class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                    required
                                >
                                    <option value="pending">در انتظار پرداخت</option>
                                    <option value="paid">تسویه شده</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label for="product-select" class="block text-sm font-medium text-gray-700 mb-1">محصول</label>
                                    <select
                                        id="product-select"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        <?php echo empty($products) ? 'disabled' : ''; ?>
                                    >
                                        <option value="">انتخاب محصول</option>
                                        <?php if (empty($products)): ?>
                                            <option value="" disabled>هیچ محصولی ثبت نشده است</option>
                                        <?php else: ?>
                                            <?php foreach ($products as $product): ?>
                                                <option value="<?php echo htmlspecialchars($product['product_id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                    <?php echo htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="variant-select" class="block text-sm font-medium text-gray-700 mb-1">تنوع موجود</label>
                                    <select
                                        id="variant-select"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
                                        disabled
                                    >
                                        <option value="">ابتدا محصول را انتخاب کنید</option>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">برای ایجاد تنوع جدید گزینه «ایجاد تنوع جدید» را انتخاب کنید.</p>
                                </div>
                            </div>

                            <div id="new-variant-fields" class="hidden grid gap-4 md:grid-cols-2">
                                <div>
                                    <label for="new-color-input" class="block text-sm font-medium text-gray-700 mb-1">رنگ جدید</label>
                                    <input
                                        type="text"
                                        id="new-color-input"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="مثلاً مشکی"
                                    >
                                </div>
                                <div>
                                    <label for="new-size-input" class="block text-sm font-medium text-gray-700 mb-1">سایز جدید</label>
                                    <input
                                        type="text"
                                        id="new-size-input"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="مثلاً L"
                                    >
                                </div>
                            </div>

                            <div class="grid gap-4 md:grid-cols-3">
                                <div>
                                    <label for="item-quantity" class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                    <input
                                        type="number"
                                        id="item-quantity"
                                        min="1"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="تعداد"
                                    >
                                </div>
                                <div>
                                    <label for="item-buy-price" class="block text-sm font-medium text-gray-700 mb-1">قیمت خرید (تومان)</label>
                                    <input
                                        type="number"
                                        id="item-buy-price"
                                        min="0"
                                        step="0.01"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="قیمت خرید"
                                    >
                                </div>
                                <div>
                                    <label for="item-sell-price" class="block text-sm font-medium text-gray-700 mb-1">قیمت فروش پیشنهادی (اختیاری)</label>
                                    <input
                                        type="number"
                                        id="item-sell-price"
                                        min="0"
                                        step="0.01"
                                        class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="قیمت فروش برای تنوع جدید"
                                    >
                                    <p class="text-xs text-gray-500 mt-1">در صورت ایجاد تنوع جدید می‌توانید قیمت فروش اولیه را مشخص کنید.</p>
                                </div>
                            </div>

                            <div class="flex justify-end">
                                <button
                                    type="button"
                                    id="add-item-button"
                                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition"
                                >
                                    افزودن آیتم
                                </button>
                            </div>

                            <div class="border border-gray-200 rounded-lg overflow-hidden">
                                <table class="min-w-full divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">محصول</th>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">رنگ</th>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">سایز</th>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">تعداد</th>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">قیمت خرید</th>
                                            <th class="px-4 py-2 text-right font-semibold text-gray-600">عملیات</th>
                                        </tr>
                                    </thead>
                                    <tbody id="purchase-items-body">
                                        <tr id="no-items-row">
                                            <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                                                هنوز آیتمی اضافه نشده است.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 pt-2">
                            <button
                                type="button"
                                id="cancel-purchase-button"
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition"
                            >
                                انصراف
                            </button>
                            <button
                                type="submit"
                                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition"
                            >
                                ثبت خرید
                            </button>
                        </div>
                    </form>
                </div>
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

            <?php if ($purchasesByMonthResult === false || $purchasesByMonthResult->num_rows === 0): ?>
                <p class="text-gray-600">هیچ خریدی ثبت نشده است.</p>
            <?php else: ?>
                <?php while ($month = $purchasesByMonthResult->fetch_assoc()): ?>
                    <?php
                        $year = (int) $month['purchase_year'];
                        $monthNum = (int) $month['purchase_month'];
                        $purchasesCount = (int) $month['purchases_count'];
                        $totalAmount = (float) $month['total_amount'];
                        $monthName = get_month_name($monthNum);
                        $details = get_purchase_details($conn, $year, $monthNum);
                    ?>
                    <div class="month-summary" id="month-summary-<?php echo $year . '-' . $monthNum; ?>">
                        <div class="month-header" onclick="toggleDetails('<?php echo $year . '-' . $monthNum; ?>')">
                            <span><?php echo "{$monthName} {$year}"; ?> (<?php echo $purchasesCount; ?> خرید)</span>
                            <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" >
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="month-details hidden p-4">
                            <div class="details-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>شماره خرید</th>
                                            <th>تاریخ</th>
                                            <th>تامین‌کننده</th>
                                            <th>نام محصول</th>
                                            <th>رنگ</th>
                                            <th>سایز</th>
                                            <th>تعداد</th>
                                            <th>قیمت خرید (تومان)</th>
                                            <th>قیمت کل (تومان)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($details as $detail): ?>
                                            <tr>
                                                <td>#خرید-<?php echo htmlspecialchars($detail['purchase_id'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['purchase_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['model_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['color'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['size'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($detail['quantity'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo number_format($detail['buy_price'], 0); ?></td>
                                                <td><?php echo number_format($detail['total_amount'], 0); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4 font-semibold text-gray-700">
                                مجموع خرید: <?php echo number_format($totalAmount, 0); ?> تومان
                            </div>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        feather.replace();

        const purchaseModal = document.getElementById('purchase-modal');
        const openModalButton = document.getElementById('open-purchase-modal');
        const closeModalButton = document.getElementById('close-purchase-modal');
        const cancelPurchaseButton = document.getElementById('cancel-purchase-button');
        const purchaseForm = document.getElementById('purchase-form');
        const productSelect = document.getElementById('product-select');
        const variantSelect = document.getElementById('variant-select');
        const newVariantFields = document.getElementById('new-variant-fields');
        const newColorInput = document.getElementById('new-color-input');
        const newSizeInput = document.getElementById('new-size-input');
        const quantityInput = document.getElementById('item-quantity');
        const buyPriceInput = document.getElementById('item-buy-price');
        const sellPriceInput = document.getElementById('item-sell-price');
        const addItemButton = document.getElementById('add-item-button');
        const itemsBody = document.getElementById('purchase-items-body');
        const noItemsRow = document.getElementById('no-items-row');

        let variantLookup = [];
        let itemIndex = 0;

        function updateEmptyState() {
            if (!noItemsRow || !itemsBody) {
                return;
            }

            const rows = Array.from(itemsBody.querySelectorAll('tr')).filter((row) => row !== noItemsRow);
            if (rows.length === 0) {
                noItemsRow.classList.remove('hidden');
            } else {
                noItemsRow.classList.add('hidden');
            }
        }

        function resetPurchaseForm() {
            if (purchaseForm) {
                purchaseForm.reset();
            }

            if (variantSelect) {
                variantSelect.innerHTML = '<option value="">ابتدا محصول را انتخاب کنید</option>';
                variantSelect.disabled = true;
            }

            variantLookup = [];
            itemIndex = 0;

            if (newVariantFields) {
                newVariantFields.classList.add('hidden');
            }
            if (newColorInput) {
                newColorInput.value = '';
            }
            if (newSizeInput) {
                newSizeInput.value = '';
            }
            if (sellPriceInput) {
                sellPriceInput.value = '';
            }

            if (itemsBody) {
                Array.from(itemsBody.querySelectorAll('tr')).forEach((row) => {
                    if (row !== noItemsRow) {
                        row.remove();
                    }
                });
            }

            updateEmptyState();
        }

        function openPurchaseModal() {
            if (!purchaseModal) {
                return;
            }

            resetPurchaseForm();
            purchaseModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            feather.replace();
        }

        function closePurchaseModal() {
            if (!purchaseModal) {
                return;
            }

            purchaseModal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        if (openModalButton) {
            openModalButton.addEventListener('click', openPurchaseModal);
        }

        [closeModalButton, cancelPurchaseButton].forEach((button) => {
            if (button) {
                button.addEventListener('click', closePurchaseModal);
            }
        });

        if (purchaseModal) {
            purchaseModal.addEventListener('click', (event) => {
                if (event.target === purchaseModal) {
                    closePurchaseModal();
                }
            });
        }

        if (productSelect) {
            productSelect.addEventListener('change', () => {
                if (!variantSelect) {
                    return;
                }

                const productId = productSelect.value;
                variantSelect.disabled = true;
                variantSelect.innerHTML = '<option value="">در حال بارگذاری...</option>';

                if (newVariantFields) {
                    newVariantFields.classList.add('hidden');
                }
                if (newColorInput) {
                    newColorInput.value = '';
                }
                if (newSizeInput) {
                    newSizeInput.value = '';
                }
                if (sellPriceInput) {
                    sellPriceInput.value = '';
                }

                if (!productId) {
                    variantSelect.innerHTML = '<option value="">ابتدا محصول را انتخاب کنید</option>';
                    return;
                }

                fetch('get_product_colors.php?product_id=' + encodeURIComponent(productId))
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('network-error');
                        }
                        return response.json();
                    })
                    .then((data) => {
                        variantLookup = Array.isArray(data.variants) ? data.variants : [];
                        variantSelect.innerHTML = '<option value="">انتخاب تنوع</option>';
                        variantLookup.forEach((variant) => {
                            const option = document.createElement('option');
                            option.value = variant.variant_id;
                            option.textContent = variant.color + ' - ' + variant.size + ' (موجودی ' + variant.stock + ')';
                            option.dataset.color = variant.color;
                            option.dataset.size = variant.size;
                            variantSelect.appendChild(option);
                        });
                        const newOption = document.createElement('option');
                        newOption.value = '__new__';
                        newOption.textContent = 'ایجاد تنوع جدید';
                        variantSelect.appendChild(newOption);
                        variantSelect.disabled = false;
                    })
                    .catch(() => {
                        variantLookup = [];
                        variantSelect.innerHTML = '<option value="">خطا در دریافت تنوع‌ها</option>';
                    });
            });
        }

        if (variantSelect) {
            variantSelect.addEventListener('change', () => {
                if (!newVariantFields) {
                    return;
                }

                if (variantSelect.value === '__new__') {
                    newVariantFields.classList.remove('hidden');
                } else {
                    newVariantFields.classList.add('hidden');
                    if (newColorInput) {
                        newColorInput.value = '';
                    }
                    if (newSizeInput) {
                        newSizeInput.value = '';
                    }
                    if (sellPriceInput) {
                        sellPriceInput.value = '';
                    }
                }
            });
        }

        if (addItemButton) {
            addItemButton.addEventListener('click', () => {
                if (!productSelect || !variantSelect || !itemsBody) {
                    return;
                }

                const productId = productSelect.value;
                if (!productId) {
                    alert('لطفاً محصول را انتخاب کنید.');
                    return;
                }

                const selectedOption = productSelect.options[productSelect.selectedIndex];
                const productName = selectedOption ? selectedOption.textContent.trim() : '';

                const variantValue = variantSelect.value;
                if (!variantValue) {
                    alert('لطفاً تنوع محصول را انتخاب کنید.');
                    return;
                }

                let variantId = 0;
                let colorText = '';
                let sizeText = '';

                if (variantValue === '__new__') {
                    colorText = newColorInput ? newColorInput.value.trim() : '';
                    sizeText = newSizeInput ? newSizeInput.value.trim() : '';

                    if (!colorText) {
                        alert('لطفاً رنگ جدید را وارد کنید.');
                        return;
                    }

                    if (!sizeText) {
                        alert('لطفاً سایز جدید را وارد کنید.');
                        return;
                    }
                } else {
                    variantId = parseInt(variantValue, 10);
                    if (!Number.isInteger(variantId) || variantId <= 0) {
                        alert('تنوع انتخاب‌شده نامعتبر است.');
                        return;
                    }

                    const matchedVariant = variantLookup.find((variant) => Number(variant.variant_id) === variantId);
                    colorText = matchedVariant ? matchedVariant.color : '';
                    sizeText = matchedVariant ? matchedVariant.size : '';
                }

                const quantity = parseInt(quantityInput ? quantityInput.value : '', 10);
                if (!Number.isInteger(quantity) || quantity <= 0) {
                    alert('تعداد باید عددی بزرگ‌تر از صفر باشد.');
                    return;
                }

                const buyPrice = parseFloat(buyPriceInput ? buyPriceInput.value : '');
                if (!Number.isFinite(buyPrice) || buyPrice <= 0) {
                    alert('قیمت خرید باید عددی بزرگ‌تر از صفر باشد.');
                    return;
                }

                let sellPriceValue = '';
                if (variantValue === '__new__' && sellPriceInput) {
                    const rawSellPrice = sellPriceInput.value;
                    if (rawSellPrice !== '') {
                        const parsedSellPrice = parseFloat(rawSellPrice);
                        if (!Number.isFinite(parsedSellPrice) || parsedSellPrice <= 0) {
                            alert('قیمت فروش وارد شده نامعتبر است.');
                            return;
                        }
                        sellPriceValue = parsedSellPrice.toString();
                    }
                }

                const row = document.createElement('tr');
                row.className = 'border-b last:border-b-0';
                row.innerHTML = `
                    <td class="px-4 py-3 text-sm text-gray-700">${productName}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${colorText || '—'}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${sizeText || '—'}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${quantity}</td>
                    <td class="px-4 py-3 text-sm text-gray-700">${buyPrice.toLocaleString('fa-IR')}</td>
                    <td class="px-4 py-3 text-sm text-right">
                        <button type="button" class="text-red-600 hover:text-red-700 remove-item">حذف</button>
                    </td>
                `;

                const hiddenVariantInput = document.createElement('input');
                hiddenVariantInput.type = 'hidden';
                hiddenVariantInput.name = `items[${itemIndex}][variant_id]`;
                hiddenVariantInput.value = variantId > 0 ? variantId.toString() : '';
                row.appendChild(hiddenVariantInput);

                const hiddenQuantityInput = document.createElement('input');
                hiddenQuantityInput.type = 'hidden';
                hiddenQuantityInput.name = `items[${itemIndex}][quantity]`;
                hiddenQuantityInput.value = quantity.toString();
                row.appendChild(hiddenQuantityInput);

                const hiddenBuyPriceInput = document.createElement('input');
                hiddenBuyPriceInput.type = 'hidden';
                hiddenBuyPriceInput.name = `items[${itemIndex}][buy_price]`;
                hiddenBuyPriceInput.value = buyPrice.toString();
                row.appendChild(hiddenBuyPriceInput);

                if (variantValue === '__new__') {
                    const hiddenProductInput = document.createElement('input');
                    hiddenProductInput.type = 'hidden';
                    hiddenProductInput.name = `items[${itemIndex}][product_id]`;
                    hiddenProductInput.value = productId;
                    row.appendChild(hiddenProductInput);

                    const hiddenColorInput = document.createElement('input');
                    hiddenColorInput.type = 'hidden';
                    hiddenColorInput.name = `items[${itemIndex}][color]`;
                    hiddenColorInput.value = colorText;
                    row.appendChild(hiddenColorInput);

                    const hiddenSizeInput = document.createElement('input');
                    hiddenSizeInput.type = 'hidden';
                    hiddenSizeInput.name = `items[${itemIndex}][size]`;
                    hiddenSizeInput.value = sizeText;
                    row.appendChild(hiddenSizeInput);

                    if (sellPriceValue !== '') {
                        const hiddenSellPriceInput = document.createElement('input');
                        hiddenSellPriceInput.type = 'hidden';
                        hiddenSellPriceInput.name = `items[${itemIndex}][sell_price]`;
                        hiddenSellPriceInput.value = sellPriceValue;
                        row.appendChild(hiddenSellPriceInput);
                    }
                }

                const removeButton = row.querySelector('.remove-item');
                if (removeButton) {
                    removeButton.addEventListener('click', () => {
                        row.remove();
                        updateEmptyState();
                    });
                }

                itemsBody.appendChild(row);
                itemIndex += 1;

                updateEmptyState();

                if (quantityInput) {
                    quantityInput.value = '';
                }
                if (buyPriceInput) {
                    buyPriceInput.value = '';
                }
                if (variantValue === '__new__') {
                    if (newColorInput) {
                        newColorInput.value = '';
                    }
                    if (newSizeInput) {
                        newSizeInput.value = '';
                    }
                    if (sellPriceInput) {
                        sellPriceInput.value = '';
                    }
                }
            });
        }

        updateEmptyState();

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
    </script>
</body>
</html>
