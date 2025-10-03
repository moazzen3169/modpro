<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

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
