<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

/**
 * Get the most recent closing balance before the requested month for a supplier.
 */
function get_previous_closing_balance(mysqli $conn, int $supplierId, int $year, int $month): float
{
    $stmt = $conn->prepare(
        'SELECT closing_balance FROM Supplier_Balances
         WHERE supplier_id = ?
           AND (balance_year < ? OR (balance_year = ? AND balance_month < ?))
         ORDER BY balance_year DESC, balance_month DESC
         LIMIT 1'
    );
    if (!$stmt) {
        return 0.0;
    }

    $stmt->bind_param('iiii', $supplierId, $year, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if ($row) {
        return (float) $row['closing_balance'];
    }

    return 0.0;
}

/**
 * Update or create the balance snapshot for a supplier/month combination.
 *
 * @return array{opening_balance: float, closing_balance: float}
 */
function upsert_supplier_balance(
    mysqli $conn,
    int $supplierId,
    int $year,
    int $month,
    float $grossPurchases,
    float $totalReturns
): array {
    $stmt = $conn->prepare(
        'SELECT balance_id FROM Supplier_Balances WHERE supplier_id = ? AND balance_year = ? AND balance_month = ?'
    );
    if (!$stmt) {
        $openingBalance = get_previous_closing_balance($conn, $supplierId, $year, $month);
        $closingBalance = $openingBalance + $grossPurchases - $totalReturns;

        return [
            'opening_balance' => $openingBalance,
            'closing_balance' => $closingBalance,
        ];
    }

    $stmt->bind_param('iii', $supplierId, $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();
    $existing = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    $openingBalance = get_previous_closing_balance($conn, $supplierId, $year, $month);
    $closingBalance = $openingBalance + $grossPurchases - $totalReturns;

    if ($existing) {
        $balanceId = (int) $existing['balance_id'];
        $updateStmt = $conn->prepare(
            'UPDATE Supplier_Balances
             SET opening_balance = ?, total_purchases = ?, total_returns = ?, closing_balance = ?, updated_at = NOW()
             WHERE balance_id = ?'
        );
        if ($updateStmt) {
            $updateStmt->bind_param('ddddi', $openingBalance, $grossPurchases, $totalReturns, $closingBalance, $balanceId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    } else {
        $insertStmt = $conn->prepare(
            'INSERT INTO Supplier_Balances (supplier_id, balance_year, balance_month, opening_balance, total_purchases, total_returns, closing_balance)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if ($insertStmt) {
            $insertStmt->bind_param('iiidddd', $supplierId, $year, $month, $openingBalance, $grossPurchases, $totalReturns, $closingBalance);
            $insertStmt->execute();
            $insertStmt->close();
        }
    }

    return [
        'opening_balance' => $openingBalance,
        'closing_balance' => $closingBalance,
    ];
}

/**
 * Fetch per-supplier purchase aggregates for each month.
 *
 * @return array<int, array<string, mixed>>
 */
function get_monthly_supplier_entries(mysqli $conn): array
{
    $entries = [];

    $purchaseSql = "
        SELECT
            YEAR(p.purchase_date) AS purchase_year,
            MONTH(p.purchase_date) AS purchase_month,
            p.supplier_id,
            s.name AS supplier_name,
            COUNT(DISTINCT p.purchase_id) AS purchases_count,
            SUM(pi.quantity * pi.buy_price) AS gross_purchases
        FROM Purchases p
        JOIN Purchase_Items pi ON p.purchase_id = pi.purchase_id
        JOIN Suppliers s ON p.supplier_id = s.supplier_id
        GROUP BY purchase_year, purchase_month, p.supplier_id, s.name
    ";

    if ($purchaseResult = $conn->query($purchaseSql)) {
        while ($row = $purchaseResult->fetch_assoc()) {
            $year = (int) $row['purchase_year'];
            $month = (int) $row['purchase_month'];
            $supplierId = (int) $row['supplier_id'];
            $key = sprintf('%d-%d-%d', $year, $month, $supplierId);

            $entries[$key] = [
                'year' => $year,
                'month' => $month,
                'supplier_id' => $supplierId,
                'supplier_name' => $row['supplier_name'],
                'purchases_count' => (int) $row['purchases_count'],
                'gross_purchases' => (float) ($row['gross_purchases'] ?? 0),
                'total_returns' => 0.0,
            ];
        }
        $purchaseResult->free();
    }

    $returnsSql = "
        SELECT
            YEAR(pr.return_date) AS return_year,
            MONTH(pr.return_date) AS return_month,
            pr.supplier_id,
            s.name AS supplier_name,
            SUM(pr.total_amount) AS total_returns
        FROM Purchase_Returns pr
        JOIN Suppliers s ON pr.supplier_id = s.supplier_id
        GROUP BY return_year, return_month, pr.supplier_id, s.name
    ";

    if ($returnsResult = $conn->query($returnsSql)) {
        while ($row = $returnsResult->fetch_assoc()) {
            $year = (int) $row['return_year'];
            $month = (int) $row['return_month'];
            $supplierId = (int) $row['supplier_id'];
            $key = sprintf('%d-%d-%d', $year, $month, $supplierId);

            if (!isset($entries[$key])) {
                $entries[$key] = [
                    'year' => $year,
                    'month' => $month,
                    'supplier_id' => $supplierId,
                    'supplier_name' => $row['supplier_name'],
                    'purchases_count' => 0,
                    'gross_purchases' => 0.0,
                    'total_returns' => 0.0,
                ];
            }

            $entries[$key]['total_returns'] += (float) ($row['total_returns'] ?? 0);
        }
        $returnsResult->free();
    }

    return array_values($entries);
}

/**
 * Build month level summaries with supplier breakdowns and balances.
 *
 * @return array<int, array<string, mixed>>
 */
function build_monthly_summaries(mysqli $conn): array
{
    $entries = get_monthly_supplier_entries($conn);

    if ($entries === []) {
        return [];
    }

    usort($entries, function (array $a, array $b): int {
        $aKey = ($a['year'] * 12) + $a['month'];
        $bKey = ($b['year'] * 12) + $b['month'];

        if ($aKey === $bKey) {
            return $a['supplier_id'] <=> $b['supplier_id'];
        }

        return $aKey <=> $bKey;
    });

    $months = [];

    foreach ($entries as $entry) {
        $balances = upsert_supplier_balance(
            $conn,
            $entry['supplier_id'],
            $entry['year'],
            $entry['month'],
            $entry['gross_purchases'],
            $entry['total_returns']
        );

        $entry['opening_balance'] = $balances['opening_balance'];
        $entry['closing_balance'] = $balances['closing_balance'];
        $entry['net_change'] = $entry['closing_balance'] - $entry['opening_balance'];

        $monthKey = sprintf('%04d-%02d', $entry['year'], $entry['month']);

        if (!isset($months[$monthKey])) {
            $months[$monthKey] = [
                'year' => $entry['year'],
                'month' => $entry['month'],
                'purchases_count' => 0,
                'gross_purchases' => 0.0,
                'total_returns' => 0.0,
                'opening_balance' => 0.0,
                'closing_balance' => 0.0,
                'suppliers' => [],
            ];
        }

        $months[$monthKey]['purchases_count'] += $entry['purchases_count'];
        $months[$monthKey]['gross_purchases'] += $entry['gross_purchases'];
        $months[$monthKey]['total_returns'] += $entry['total_returns'];
        $months[$monthKey]['opening_balance'] += $entry['opening_balance'];
        $months[$monthKey]['closing_balance'] += $entry['closing_balance'];

        $months[$monthKey]['suppliers'][] = $entry;
    }

    $monthsList = array_values($months);

    foreach ($monthsList as &$month) {
        $month['net_change'] = $month['closing_balance'] - $month['opening_balance'];
        $month['net_purchases'] = $month['gross_purchases'] - $month['total_returns'];
        usort($month['suppliers'], function (array $a, array $b): int {
            return strcmp($a['supplier_name'], $b['supplier_name']);
        });
    }
    unset($month);

    usort($monthsList, function (array $a, array $b): int {
        $aKey = ($a['year'] * 12) + $a['month'];
        $bKey = ($b['year'] * 12) + $b['month'];

        return $bKey <=> $aKey;
    });

    return $monthsList;
}

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
                    $quantity = (int) ($item['quantity'] ?? 0);
                    $buy_price = (float) ($item['buy_price'] ?? 0);
                    if (empty($product_name) || empty($color_sizes) || $quantity <= 0 || $buy_price <= 0) {
                        $errors[] = 'اطلاعات آیتم نامعتبر.';
                        break;
                    }
                    foreach ($color_sizes as $cs) {
                        if (empty($cs['color']) || empty($cs['sizes'])) {
                            $errors[] = 'اطلاعات رنگ و سایز نامعتبر.';
                            break 2;
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
                        $quantity = (int) $item['quantity'];
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
                            // Find or create variant
                            $stmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE product_id = ? AND color = ? AND size = ?');
                            $stmt->bind_param('iss', $product_id, $color, $size);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($row = $result->fetch_assoc()) {
                                $variant_id = $row['variant_id'];
                            } else {
                                $initial_stock = 0;
                                $stmt = $conn->prepare('INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES (?, ?, ?, ?, ?)');
                                $stmt->bind_param('issdi', $product_id, $color, $size, $buy_price, $initial_stock);
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
ORDER BY pr.purchase_date DESC
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
        <div class="relative top-20 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">خرید جدید</h3>
                    <button onclick="closeModal('purchaseModal')" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                <form id="purchaseForm" method="POST" action="purchases.php">
                    <input type="hidden" name="action" value="create_purchase">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ خرید</label>
                        <input type="date" name="purchase_date" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>

                    <div class="mb-4">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block text-sm font-medium text-gray-700">آیتم‌ها</label>
                            <button type="button" onclick="addPurchaseItem()" class="px-3 py-1 bg-green-500 text-white rounded-md hover:bg-green-600 text-sm">
                                افزودن آیتم
                            </button>
                        </div>
                        <div id="purchaseItems" class="space-y-2">
                            <!-- Items will be added here -->
                        </div>
                    </div>

                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeModal('purchaseModal')" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400">
                            لغو
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600">
                            ثبت خرید
                        </button>
                    </div>
                </form>
            </div>
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

        function addPurchaseItem() {
            const itemsContainer = document.getElementById('purchaseItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-gray-50 p-3 rounded-md';
            itemDiv.innerHTML = `
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">نام محصول</label>
                    <input type="text" name="items[${itemIndex}][product_name]" list="productNames" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div class="mb-4">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium text-gray-700">رنگ‌ها و سایزها</label>
                        <button type="button" onclick="addColorSize(${itemIndex})" class="px-3 py-1 bg-green-500 text-white rounded-md hover:bg-green-600 text-sm">
                            افزودن رنگ
                        </button>
                    </div>
                    <div id="colorSizesContainer-${itemIndex}" class="space-y-2">
                        <!-- Color-size pairs will be added here -->
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد کل</label>
                        <input type="number" name="items[${itemIndex}][quantity]" min="1" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">قیمت خرید</label>
                        <input type="number" name="items[${itemIndex}][buy_price]" min="0" step="0.01" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>
                <button type="button" onclick="removePurchaseItem(this)" class="px-2 py-2 bg-red-500 text-white rounded-md hover:bg-red-600">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            `;
            itemsContainer.appendChild(itemDiv);
            itemIndex++;
        }

function addColorSize(itemIndex) {
            const container = document.getElementById(`colorSizesContainer-${itemIndex}`);
            const colorIndex = container.children.length;
            const colorDiv = document.createElement('div');
            colorDiv.className = 'bg-white p-2 rounded-md border';
            colorDiv.innerHTML = `
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-2">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">رنگ</label>
                        <input type="text" name="items[${itemIndex}][color_sizes][${colorIndex}][color]" list="colorNames" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">سایزها</label>
                        <div class="flex flex-wrap gap-2">
                            <label class="flex items-center">
                                <input type="checkbox" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][]" value="S" class="mr-1">
                                S
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][]" value="M" class="mr-1">
                                M
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][]" value="L" class="mr-1">
                                L
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][]" value="XL" class="mr-1">
                                XL
                            </label>
                            <label class="flex items-center">
                                <input type="checkbox" name="items[${itemIndex}][color_sizes][${colorIndex}][sizes][]" value="XXL" class="mr-1">
                                XXL
                            </label>
                        </div>
                    </div>
                </div>
                <button type="button" onclick="removeColorSize(this)" class="px-2 py-1 bg-red-500 text-white rounded-md hover:bg-red-600 text-sm">
                    حذف رنگ
                </button>
            `;
            container.appendChild(colorDiv);
        }

        function removeColorSize(button) {
            button.parentElement.remove();
        }

        function removePurchaseItem(button) {
            button.parentElement.remove();
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
