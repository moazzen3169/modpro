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

$monthlySummaries = build_monthly_summaries($conn);
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
            <h2 class="text-2xl font-semibold mb-6">مشاهده خریدها</h2>

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

            <?php if (empty($monthlySummaries)): ?>
                <p class="text-gray-600">هیچ خریدی ثبت نشده است.</p>
            <?php else: ?>
                <?php foreach ($monthlySummaries as $month): ?>
                    <?php
                        $year = (int) $month['year'];
                        $monthNum = (int) $month['month'];
                        $purchasesCount = (int) $month['purchases_count'];
                        $grossPurchases = (float) $month['gross_purchases'];
                        $returnsAmount = (float) $month['total_returns'];
                        $openingBalance = (float) $month['opening_balance'];
                        $closingBalance = (float) $month['closing_balance'];
                        $netChange = (float) $month['net_change'];
                        $monthName = get_month_name($monthNum);
                        $details = get_purchase_details($conn, $year, $monthNum);
                    ?>
                    <div class="month-summary" id="month-summary-<?php echo $year . '-' . $monthNum; ?>">
                        <div class="month-header" onclick="toggleDetails('<?php echo $year . '-' . $monthNum; ?>')">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:gap-4">
                                <span><?php echo "{$monthName} {$year}"; ?> (<?php echo $purchasesCount; ?> خرید)</span>
                                <span class="text-sm text-gray-500">خالص بدهی: <?php echo number_format($netChange, 0); ?> تومان</span>
                            </div>
                            <svg class="toggle-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" >
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </div>
                        <div class="month-details hidden p-4">
                            <div class="summary-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>خرید ناخالص</th>
                                            <th>مرجوعی‌ها</th>
                                            <th>بدهی قبلی</th>
                                            <th>بدهی جدید</th>
                                            <th>مانده پایان ماه</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td><?php echo number_format($grossPurchases, 0); ?></td>
                                            <td><?php echo number_format($returnsAmount, 0); ?></td>
                                            <td><?php echo number_format($openingBalance, 0); ?></td>
                                            <td><?php echo number_format($netChange, 0); ?></td>
                                            <td><?php echo number_format($closingBalance, 0); ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <?php if (!empty($month['suppliers'])): ?>
                                <div class="summary-table mt-4">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>تامین‌کننده</th>
                                                <th>خرید ناخالص</th>
                                                <th>مرجوعی‌ها</th>
                                                <th>بدهی قبلی</th>
                                                <th>بدهی جدید</th>
                                                <th>مانده پایان ماه</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($month['suppliers'] as $supplierSummary): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($supplierSummary['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td><?php echo number_format($supplierSummary['gross_purchases'], 0); ?></td>
                                                    <td><?php echo number_format($supplierSummary['total_returns'], 0); ?></td>
                                                    <td><?php echo number_format($supplierSummary['opening_balance'], 0); ?></td>
                                                    <td><?php echo number_format($supplierSummary['net_change'], 0); ?></td>
                                                    <td><?php echo number_format($supplierSummary['closing_balance'], 0); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>

                            <div class="details-table mt-6">
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

                            <?php if (empty($details)): ?>
                                <p class="mt-4 text-sm text-gray-500">در این ماه خریدی برای نمایش وجود ندارد.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
    </script>
</body>
</html>
