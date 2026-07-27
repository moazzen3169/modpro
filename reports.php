<?php
require_once __DIR__ . '/env/bootstrap.php';

// Calculate average purchase price per variant for profit calculations
$average_purchase_prices = $conn->query("SELECT variant_id, SUM(quantity * buy_price) / NULLIF(SUM(quantity), 0) AS avg_buy_price FROM Purchase_Items GROUP BY variant_id");
$avg_purchase_map = [];
if ($average_purchase_prices) {
    while ($row = $average_purchase_prices->fetch_assoc()) {
        $avg_purchase_map[$row['variant_id']] = (float)$row['avg_buy_price'];
    }
}

/**
 * Helper to calculate total sales sum
 */
function fetch_sales_sum_reports(mysqli $conn, string $where, string $expression = 'si.quantity * si.sell_price'): float {
    $result = $conn->query("SELECT SUM($expression) AS total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE $where");
    if (!$result) {
        return 0.0;
    }
    $row = $result->fetch_assoc();
    return (float) ($row['total'] ?? 0);
}

/**
 * Helper to calculate profit based on date condition
 */
function calculate_profit_reports(mysqli $conn, array $avg_purchase_map, string $where): float {
    $query = $conn->query("SELECT si.variant_id, si.quantity, si.sell_price FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE $where");
    if (!$query) {
        return 0.0;
    }
    $profit = 0.0;
    while ($item = $query->fetch_assoc()) {
        $variant_id = $item['variant_id'];
        $avg_buy_price = $avg_purchase_map[$variant_id] ?? 0;
        $profit += $item['quantity'] * ((float)$item['sell_price'] - $avg_buy_price);
    }
    return $profit;
}

// Get current Jalali date info
[$currentJalaliYear, $currentJalaliMonth, $currentJalaliDay] = get_current_jalali_date();

// Handle date inputs with validation and defaults
$error_msg = '';
try {
    $start_date_str = $_GET['start_date'] ?? '';
    if (empty($start_date_str)) {
        // Default to first day of the current year
        $start_date_str = sprintf('%04d/01/01', $currentJalaliYear);
    }

    $end_date_str = $_GET['end_date'] ?? '';
    if (empty($end_date_str)) {
        $end_date_str = get_current_jalali_date_string();
    }

    [$start_y, $start_m, $start_d] = parse_jalali_date_string($start_date_str);
    [$end_y, $end_m, $end_d] = parse_jalali_date_string($end_date_str);

    // Ensure start date <= end date
    $start_ts = jalali_to_timestamp($start_y, $start_m, $start_d);
    $end_ts = jalali_to_timestamp($end_y, $end_m, $end_d);
    if ($start_ts > $end_ts) {
        // Swap dates
        $temp = $start_date_str;
        $start_date_str = $end_date_str;
        $end_date_str = $temp;
        [$start_y, $start_m, $start_d] = parse_jalali_date_string($start_date_str);
        [$end_y, $end_m, $end_d] = parse_jalali_date_string($end_date_str);
    }
} catch (Throwable $e) {
    // Fallback to defaults
    $start_date_str = sprintf('%04d/01/01', $currentJalaliYear);
    $end_date_str = get_current_jalali_date_string();
    [$start_y, $start_m, $start_d] = parse_jalali_date_string($start_date_str);
    [$end_y, $end_m, $end_d] = parse_jalali_date_string($end_date_str);
    $error_msg = 'تاریخ‌های وارد شده نامعتبر هستند. تاریخ‌های پیش‌فرض اعمال شدند.';
}

// Generate the list of Jalali months in the range
$months_list = [];
$curr_y = $start_y;
$curr_m = $start_m;
while ($curr_y < $end_y || ($curr_y == $end_y && $curr_m <= $end_m)) {
    $months_list[] = [$curr_y, $curr_m];
    $curr_m++;
    if ($curr_m > 12) {
        $curr_m = 1;
        $curr_y++;
    }
}

// Process data for each month
$monthly_data = [];
$total_period_sales = 0.0;
$total_period_profit = 0.0;
$total_period_quantity = 0.0;
$total_period_tx = 0;

foreach ($months_list as $index => [$cy, $cm]) {
    // Determine the exact range for this month
    $month_start_jalali = ($index === 0) ? $start_date_str : sprintf('%04d/%02d/01', $cy, $cm);
    $month_end_jalali = ($index === count($months_list) - 1) ? $end_date_str : sprintf('%04d/%02d/%02d', $cy, $cm, get_jalali_month_days($cy, $cm));

    $g_start = jalali_to_gregorian_string($month_start_jalali);
    $g_end = jalali_to_gregorian_string($month_end_jalali);

    // Fetch metrics
    $sales_sum = fetch_sales_sum_reports($conn, "s.sale_date BETWEEN '$g_start' AND '$g_end'");

    $qty_res = $conn->query("SELECT SUM(si.quantity) as total_qty FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE s.sale_date BETWEEN '$g_start' AND '$g_end'");
    $sales_qty = (float)($qty_res->fetch_assoc()['total_qty'] ?? 0);

    $tx_res = $conn->query("SELECT COUNT(DISTINCT s.sale_id) as tx_count FROM Sales s WHERE s.sale_date BETWEEN '$g_start' AND '$g_end'");
    $tx_count = (int)($tx_res->fetch_assoc()['tx_count'] ?? 0);

    $profit = calculate_profit_reports($conn, $avg_purchase_map, "s.sale_date BETWEEN '$g_start' AND '$g_end'");

    $month_name = get_jalali_month_name($cm) . ' ' . $cy;

    $monthly_data[] = [
        'month_name' => $month_name,
        'year' => $cy,
        'month' => $cm,
        'sales_amount' => $sales_sum,
        'sales_quantity' => $sales_qty,
        'tx_count' => $tx_count,
        'profit' => $profit
    ];

    $total_period_sales += $sales_sum;
    $total_period_profit += $profit;
    $total_period_quantity += $sales_qty;
    $total_period_tx += $tx_count;
}

// ----------------------------------------------------
// PRODUCT COMPARISON SECTION BACKEND
// ----------------------------------------------------

// Fetch all products for selector
$all_products = [];
$prod_query = $conn->query("SELECT product_id, model_name, category FROM Products ORDER BY model_name");
if ($prod_query) {
    while ($row = $prod_query->fetch_assoc()) {
        $all_products[] = [
            'product_id' => (int)$row['product_id'],
            'model_name' => $row['model_name'],
            'category' => $row['category'] ?: 'بدون دسته‌بندی'
        ];
    }
}

// Get selected products for comparison
$selected_product_ids = [];
if (isset($_GET['compare_products']) && is_array($_GET['compare_products'])) {
    foreach ($_GET['compare_products'] as $id) {
        $id_int = (int) $id;
        if ($id_int > 0) {
            $selected_product_ids[] = $id_int;
        }
    }
} elseif (isset($_GET['compare_products']) && is_string($_GET['compare_products'])) {
    $parts = explode(',', $_GET['compare_products']);
    foreach ($parts as $part) {
        $id_int = (int) trim($part);
        if ($id_int > 0) {
            $selected_product_ids[] = $id_int;
        }
    }
}

// If no products selected, pre-select the top 3 best-sellers for an amazing first experience!
if (empty($_GET['submit_comparison']) && empty($selected_product_ids)) {
    $top_products_query = $conn->query("
        SELECT p.product_id
        FROM Products p
        JOIN Product_Variants pv ON p.product_id = pv.product_id
        JOIN Sale_Items si ON pv.variant_id = si.variant_id
        GROUP BY p.product_id
        ORDER BY SUM(si.quantity) DESC
        LIMIT 3
    ");
    if ($top_products_query && $top_products_query->num_rows > 0) {
        while ($row = $top_products_query->fetch_assoc()) {
            $selected_product_ids[] = (int)$row['product_id'];
        }
    }
}

// Get Gregorian range for comparison
$range_g_start = jalali_to_gregorian_string($start_date_str);
$range_g_end = jalali_to_gregorian_string($end_date_str);

// Process comparison statistics
$comparison_results = [];
if (!empty($selected_product_ids)) {
    $ids_str = implode(',', $selected_product_ids);

    $comp_query = $conn->query("
        SELECT
            p.product_id,
            p.model_name,
            p.category,
            si.variant_id,
            si.quantity,
            si.sell_price
        FROM Products p
        LEFT JOIN Product_Variants pv ON p.product_id = pv.product_id
        LEFT JOIN Sale_Items si ON pv.variant_id = si.variant_id
        LEFT JOIN Sales s ON si.sale_id = s.sale_id AND s.sale_date BETWEEN '{$range_g_start}' AND '{$range_g_end}'
        WHERE p.product_id IN ({$ids_str})
    ");

    if ($comp_query) {
        $temp_map = [];
        // Pre-initialize to handle zero sales gracefully
        foreach ($selected_product_ids as $p_id) {
            $p_name = 'محصول نامشخص';
            $p_cat = 'نامشخص';
            foreach ($all_products as $prod) {
                if ($prod['product_id'] === $p_id) {
                    $p_name = $prod['model_name'];
                    $p_cat = $prod['category'];
                    break;
                }
            }
            $temp_map[$p_id] = [
                'product_id' => $p_id,
                'model_name' => $p_name,
                'category' => $p_cat,
                'total_quantity' => 0.0,
                'total_revenue' => 0.0,
                'total_profit' => 0.0
            ];
        }

        while ($row = $comp_query->fetch_assoc()) {
            $p_id = (int)$row['product_id'];
            if (!isset($temp_map[$p_id])) continue;

            if ($row['variant_id'] !== null) {
                $qty = (float)$row['quantity'];
                $sell_price = (float)$row['sell_price'];
                $variant_id = $row['variant_id'];

                $avg_buy = $avg_purchase_map[$variant_id] ?? 0.0;
                $profit = $qty * ($sell_price - $avg_buy);

                $temp_map[$p_id]['total_quantity'] += $qty;
                $temp_map[$p_id]['total_revenue'] += $qty * $sell_price;
                $temp_map[$p_id]['total_profit'] += $profit;
            }
        }
        $comparison_results = array_values($temp_map);
    }
}

// Chart labels and datasets preparation for monthly chart
$chart_labels = [];
$chart_revenue = [];
$chart_profit = [];
$chart_quantity = [];

foreach ($monthly_data as $data) {
    $chart_labels[] = $data['month_name'];
    $chart_revenue[] = $data['sales_amount'];
    $chart_profit[] = $data['profit'];
    $chart_quantity[] = $data['sales_quantity'];
}

// Product comparison chart data
$comp_labels = [];
$comp_revenue = [];
$comp_profit = [];
$comp_quantity = [];

foreach ($comparison_results as $res) {
    $comp_labels[] = $res['model_name'];
    $comp_revenue[] = $res['total_revenue'];
    $comp_profit[] = $res['total_profit'];
    $comp_quantity[] = $res['total_quantity'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>گزارش‌ها و تحلیل‌های پیشرفته - SuitStore Manager Pro</title>
    <script src="libs/tailwind.js"></script>
    <script src="libs/feather-icons.js"></script>
    <script src="libs/chart.js"></script>
    <link href="css/global.css" rel="stylesheet">
    <style>
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <div class="flex h-screen overflow-hidden">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto custom-scrollbar">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-6 flex flex-col md:flex-row justify-between items-start md:items-center header-shadow gap-4">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">گزارش‌ها و آمار پیشرفته</h2>
                    <p class="text-sm text-gray-500 mt-1">تحلیل عملکرد فروش و سوددهی و مقایسه هوشمند محصولات</p>
                </div>
                <div class="flex items-center space-x-3 space-x-reverse">
                    <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium bg-blue-50 text-blue-700 border border-blue-100">
                        <i data-feather="calendar" class="w-4 h-4 ml-1.5"></i>
                        بازه گزارش: <?php echo htmlspecialchars($start_date_str, ENT_QUOTES, 'UTF-8'); ?> الی <?php echo htmlspecialchars($end_date_str, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                </div>
            </header>

            <!-- Main Content Area -->
            <main class="p-6 max-w-7xl mx-auto space-y-6">

                <?php if (!empty($error_msg)): ?>
                    <div class="flex items-center p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl">
                        <i data-feather="alert-triangle" class="w-5 h-5 ml-2.5 flex-shrink-0"></i>
                        <span class="text-sm font-medium"><?php echo htmlspecialchars($error_msg, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <!-- Date Range Filters Card -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex items-center gap-2 mb-4">
                        <div class="p-2 bg-blue-50 rounded-lg text-blue-500">
                            <i data-feather="sliders" class="w-5 h-5"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-800">تنظیم محدوده زمانی گزارش</h3>
                    </div>

                    <form method="GET" id="filterForm" class="space-y-4">
                        <!-- Keep compared products in URL when changing dates -->
                        <?php foreach ($selected_product_ids as $id): ?>
                            <input type="hidden" name="compare_products[]" value="<?php echo $id; ?>">
                        <?php endforeach; ?>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 items-end">
                            <div class="space-y-2">
                                <label class="text-sm font-semibold text-gray-600 block">از تاریخ (جلالی)</label>
                                <div class="relative">
                                    <i data-feather="calendar" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4"></i>
                                    <input type="text" name="start_date" id="startDateInput" value="<?php echo htmlspecialchars($start_date_str, ENT_QUOTES, 'UTF-8'); ?>" placeholder="مثال: 1404/01/01" inputmode="numeric" pattern="[0-9]{4}/[0-9]{2}/[0-9]{2}" dir="ltr" required class="w-full pr-10 pl-4 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 text-sm font-medium">
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-semibold text-gray-600 block">تا تاریخ (جلالی)</label>
                                <div class="relative">
                                    <i data-feather="calendar" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4"></i>
                                    <input type="text" name="end_date" id="endDateInput" value="<?php echo htmlspecialchars($end_date_str, ENT_QUOTES, 'UTF-8'); ?>" placeholder="مثال: 1404/07/30" inputmode="numeric" pattern="[0-9]{4}/[0-9]{2}/[0-9]{2}" dir="ltr" required class="w-full pr-10 pl-4 py-2.5 border border-gray-200 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 text-sm font-medium">
                                </div>
                            </div>

                            <button type="submit" class="w-full bg-blue-600 text-white font-semibold py-2.5 px-4 rounded-xl hover:bg-blue-700 transition duration-200 flex items-center justify-center gap-2 shadow-sm shadow-blue-600/10 h-[42px]">
                                <i data-feather="refresh-cw" class="w-4 h-4"></i>
                                به‌روزرسانی گزارش
                            </button>
                        </div>

                        <!-- Quick Dates Shortcuts -->
                        <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-gray-100">
                            <span class="text-xs text-gray-400 ml-2 font-medium">دسترسی سریع:</span>
                            <button type="button" onclick="setQuickRange('current_month')" class="px-3 py-1 text-xs font-semibold bg-gray-50 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg border border-gray-200 hover:border-blue-100 transition">ماه جاری</button>
                            <button type="button" onclick="setQuickRange('last_3_months')" class="px-3 py-1 text-xs font-semibold bg-gray-50 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg border border-gray-200 hover:border-blue-100 transition">۳ ماه اخیر</button>
                            <button type="button" onclick="setQuickRange('last_6_months')" class="px-3 py-1 text-xs font-semibold bg-gray-50 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg border border-gray-200 hover:border-blue-100 transition">۶ ماه اخیر</button>
                            <button type="button" onclick="setQuickRange('current_year')" class="px-3 py-1 text-xs font-semibold bg-gray-50 text-gray-600 hover:bg-blue-50 hover:text-blue-600 rounded-lg border border-gray-200 hover:border-blue-100 transition">سال جاری (تاکنون)</button>
                        </div>
                    </form>
                </div>

                <!-- KPI Cards Range Summary -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center">
                        <div class="p-4 bg-blue-50 rounded-xl text-blue-600 ml-4">
                            <i data-feather="dollar-sign" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h4 class="text-xs text-gray-400 font-semibold uppercase tracking-wider">مجموع مبلغ فروش</h4>
                            <p class="text-xl font-bold text-gray-800 mt-1"><?php echo number_format($total_period_sales, 0); ?> تومان</p>
                            <p class="text-[11px] text-gray-400 mt-0.5">در بازه انتخابی</p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center">
                        <div class="p-4 bg-emerald-50 rounded-xl text-emerald-600 ml-4">
                            <i data-feather="trending-up" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h4 class="text-xs text-gray-400 font-semibold uppercase tracking-wider">سود خالص ناخالص</h4>
                            <p class="text-xl font-bold text-gray-800 mt-1"><?php echo number_format($total_period_profit, 0); ?> تومان</p>
                            <p class="text-[11px] text-gray-400 mt-0.5">بر اساس متوسط خرید</p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center">
                        <div class="p-4 bg-purple-50 rounded-xl text-purple-600 ml-4">
                            <i data-feather="pie-chart" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h4 class="text-xs text-gray-400 font-semibold uppercase tracking-wider">حاشیه سود ناخالص</h4>
                            <p class="text-xl font-bold text-gray-800 mt-1">
                                <?php
                                $margin = ($total_period_sales > 0) ? ($total_period_profit / $total_period_sales) * 100 : 0;
                                echo number_format($margin, 1);
                                ?>%
                            </p>
                            <p class="text-[11px] text-gray-400 mt-0.5">از کل مبلغ فروش</p>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center">
                        <div class="p-4 bg-amber-50 rounded-xl text-amber-600 ml-4">
                            <i data-feather="shopping-bag" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h4 class="text-xs text-gray-400 font-semibold uppercase tracking-wider">کل اقلام فروخته شده</h4>
                            <p class="text-xl font-bold text-gray-800 mt-1"><?php echo number_format($total_period_quantity, 0); ?> عدد</p>
                            <p class="text-[11px] text-gray-400 mt-0.5">در قالب <?php echo $total_period_tx; ?> فاکتور</p>
                        </div>
                    </div>
                </div>

                <!-- Section 1: Monthly Chart & Table -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Chart Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 lg:col-span-2">
                        <div class="flex justify-between items-center mb-6">
                            <div class="flex items-center gap-2">
                                <div class="p-2 bg-indigo-50 rounded-lg text-indigo-500">
                                    <i data-feather="activity" class="w-5 h-5"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-gray-800">روند فروش و سود به تفکیک ماه</h3>
                                    <p class="text-xs text-gray-400 mt-0.5">نمودار تغییرات ماهانه درآمد در محدوده زمانی تعیین‌شده</p>
                                </div>
                            </div>
                        </div>

                        <div class="relative h-[320px] w-full">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>

                    <!-- Mini KPI & Details List -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center gap-2 mb-4">
                                <div class="p-2 bg-amber-50 rounded-lg text-amber-500">
                                    <i data-feather="bar-chart-2" class="w-5 h-5"></i>
                                </div>
                                <h3 class="text-base font-bold text-gray-800">خلاصه تحلیل این بازه</h3>
                            </div>

                            <div class="space-y-4">
                                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                    <div class="text-xs text-gray-400 font-medium">متوسط فروش ماهانه</div>
                                    <div class="text-lg font-bold text-gray-800 mt-1">
                                        <?php
                                        $months_count = max(1, count($monthly_data));
                                        echo number_format($total_period_sales / $months_count, 0);
                                        ?> تومان
                                    </div>
                                </div>

                                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                    <div class="text-xs text-gray-400 font-medium">متوسط سود ماهانه</div>
                                    <div class="text-lg font-bold text-gray-800 mt-1">
                                        <?php echo number_format($total_period_profit / $months_count, 0); ?> تومان
                                    </div>
                                </div>

                                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                    <div class="text-xs text-gray-400 font-medium">تعداد کل فاکتورها</div>
                                    <div class="text-lg font-bold text-gray-800 mt-1">
                                        <?php echo number_format($total_period_tx, 0); ?> عدد
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 mt-4 text-center">
                            <p class="text-xs text-gray-400 font-medium leading-relaxed">
                                سیستم قیمت متوسط خرید هر مدل کت و شلوار را بر اساس آخرین خریدها محاسبه کرده و با فروش‌ها مقایسه می‌کند تا دقیق‌ترین تخمین سود به دست آید.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Monthly Details Grid Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100 flex justify-between items-center">
                        <div class="flex items-center gap-2">
                            <div class="p-2 bg-emerald-50 rounded-lg text-emerald-500">
                                <i data-feather="list" class="w-5 h-5"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-800">جدول جزئیات عملکرد ماهانه</h3>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-right text-gray-600">
                            <thead class="bg-gray-50/50 text-gray-700 border-b border-gray-100">
                                <tr>
                                    <th class="px-6 py-4 font-bold">ماه</th>
                                    <th class="px-6 py-4 font-bold text-center">تعداد فاکتورها</th>
                                    <th class="px-6 py-4 font-bold text-center">تعداد اقلام فروخته شده</th>
                                    <th class="px-6 py-4 font-bold">مجموع درآمد (تومان)</th>
                                    <th class="px-6 py-4 font-bold">سود خالص (تومان)</th>
                                    <th class="px-6 py-4 font-bold text-center">نسبت حاشیه سود</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php if (!empty($monthly_data)): ?>
                                    <?php foreach ($monthly_data as $data): ?>
                                        <tr class="hover:bg-gray-50/80 transition duration-150">
                                            <td class="px-6 py-4 font-semibold text-gray-800"><?php echo htmlspecialchars($data['month_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="px-6 py-4 text-center font-medium"><?php echo number_format($data['tx_count']); ?></td>
                                            <td class="px-6 py-4 text-center font-medium"><?php echo number_format($data['sales_quantity']); ?></td>
                                            <td class="px-6 py-4 font-bold text-blue-600"><?php echo number_format($data['sales_amount']); ?></td>
                                            <td class="px-6 py-4 font-bold text-emerald-600"><?php echo number_format($data['profit']); ?></td>
                                            <td class="px-6 py-4 text-center">
                                                <?php
                                                $row_margin = ($data['sales_amount'] > 0) ? ($data['profit'] / $data['sales_amount']) * 100 : 0;
                                                $color_class = 'bg-red-50 text-red-600';
                                                if ($row_margin > 35) {
                                                    $color_class = 'bg-emerald-50 text-emerald-600';
                                                } elseif ($row_margin > 20) {
                                                    $color_class = 'bg-amber-50 text-amber-600';
                                                }
                                                ?>
                                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold <?php echo $color_class; ?>">
                                                    <?php echo number_format($row_margin, 1); ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-10 text-center text-gray-400">اطلاعاتی در این محدوده زمانی یافت نشد.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 2: Product Performance & Comparison -->
                <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6 pb-4 border-b border-gray-100">
                        <div class="flex items-center gap-2">
                            <div class="p-2 bg-blue-50 rounded-lg text-blue-500">
                                <i data-feather="git-pull-request" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-800">مقایسه و ارزیابی عملکرد محصولات</h3>
                                <p class="text-xs text-gray-400 mt-1">محصولات مورد نظر خود را انتخاب کنید تا عملکرد فروش، درآمد و سوددهی آن‌ها را در بازه فوق مقایسه کنید.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Selection & Interactive Grid -->
                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 items-start">
                        <!-- Left Selector Side -->
                        <div class="space-y-4 lg:col-span-1">
                            <div class="bg-gray-50/50 rounded-xl p-4 border border-gray-100">
                                <h4 class="text-sm font-bold text-gray-700 mb-3 flex justify-between items-center">
                                    <span>لیست محصولات موجود</span>
                                    <span class="text-xs text-blue-600 font-semibold" id="selectedCount">0 انتخاب شده</span>
                                </h4>

                                <!-- Search Field -->
                                <div class="relative mb-3">
                                    <i data-feather="search" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 w-4 h-4"></i>
                                    <input type="text" id="productSearch" placeholder="جستجوی مدل..." class="w-full pr-9 pl-3 py-1.5 border border-gray-200 rounded-lg focus:outline-none focus:ring-1 focus:ring-blue-500 text-xs font-medium">
                                </div>

                                <!-- Checklist Scroll Container -->
                                <div class="max-h-[300px] overflow-y-auto custom-scrollbar space-y-1.5 p-1 bg-white rounded-lg border border-gray-150">
                                    <?php foreach ($all_products as $p): ?>
                                        <?php $checked = in_array($p['product_id'], $selected_product_ids, true); ?>
                                        <label class="flex items-center p-2 rounded-lg hover:bg-gray-50 transition cursor-pointer text-xs font-medium border border-transparent select-none product-label <?php echo $checked ? 'bg-blue-50/60 border-blue-100/50' : ''; ?>" data-name="<?php echo htmlspecialchars($p['model_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="checkbox" name="compare_products[]" value="<?php echo $p['product_id']; ?>" <?php echo $checked ? 'checked' : ''; ?> class="compare-checkbox ml-2.5 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" onchange="toggleProductHighlight(this)">
                                            <div class="flex-1">
                                                <div class="text-gray-800 font-bold"><?php echo htmlspecialchars($p['model_name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                                <div class="text-[10px] text-gray-400 mt-0.5"><?php echo htmlspecialchars($p['category'], ENT_QUOTES, 'UTF-8'); ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="grid grid-cols-2 gap-2 mt-3">
                                    <button type="button" onclick="deselectAllProducts()" class="py-1.5 text-center bg-white border border-gray-200 text-gray-600 hover:bg-gray-50 rounded-lg text-[11px] font-bold transition">پاک کردن همه</button>
                                    <button type="button" onclick="selectTopThree()" class="py-1.5 text-center bg-blue-50 border border-blue-100 text-blue-700 hover:bg-blue-100 rounded-lg text-[11px] font-bold transition">انتخاب پرفروش‌ترین‌ها</button>
                                </div>

                                <button type="button" onclick="submitComparisonForm()" class="w-full mt-3 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-lg text-xs transition shadow-sm flex items-center justify-center gap-1">
                                    <i data-feather="check-square" class="w-3.5 h-3.5"></i>
                                    اعمال مقایسه
                                </button>
                            </div>
                        </div>

                        <!-- Right Comparison Visualizer -->
                        <div class="lg:col-span-3 space-y-6">
                            <?php if (!empty($comparison_results)): ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-start">
                                    <!-- Comparison Chart -->
                                    <div class="bg-white rounded-xl border border-gray-150 p-4">
                                        <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4">نمودار مقایسه مالی درآمد و سود</h4>
                                        <div class="relative h-[250px]">
                                            <canvas id="compChart"></canvas>
                                        </div>
                                    </div>

                                    <!-- Comparison Quantity Chart -->
                                    <div class="bg-white rounded-xl border border-gray-150 p-4">
                                        <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-4">نمودار مقایسه تعداد فروش رفتگی</h4>
                                        <div class="relative h-[250px]">
                                            <canvas id="compQtyChart"></canvas>
                                        </div>
                                    </div>
                                </div>

                                <!-- Cards for side-by-side comparison with margins -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <?php foreach ($comparison_results as $res): ?>
                                        <div class="bg-white rounded-xl border border-gray-200 p-5 shadow-sm hover:shadow transition duration-200 relative overflow-hidden flex flex-col justify-between">
                                            <!-- Category Badge -->
                                            <span class="absolute top-4 left-4 inline-block px-2 py-0.5 bg-gray-100 text-gray-600 text-[10px] font-bold rounded-md">
                                                <?php echo htmlspecialchars($res['category'], ENT_QUOTES, 'UTF-8'); ?>
                                            </span>

                                            <div>
                                                <h4 class="font-bold text-base text-gray-800 mb-4"><?php echo htmlspecialchars($res['model_name'], ENT_QUOTES, 'UTF-8'); ?></h4>

                                                <div class="space-y-3.5">
                                                    <div class="flex justify-between text-xs">
                                                        <span class="text-gray-400">تعداد فروش:</span>
                                                        <span class="font-bold text-gray-800"><?php echo number_format($res['total_quantity']); ?> عدد</span>
                                                    </div>

                                                    <div class="flex justify-between text-xs">
                                                        <span class="text-gray-400">کل درآمد:</span>
                                                        <span class="font-bold text-blue-600"><?php echo number_format($res['total_revenue']); ?> تومان</span>
                                                    </div>

                                                    <div class="flex justify-between text-xs">
                                                        <span class="text-gray-400">سود کل:</span>
                                                        <span class="font-bold text-emerald-600"><?php echo number_format($res['total_profit']); ?> تومان</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="pt-4 border-t border-gray-100 mt-4 flex items-center justify-between">
                                                <span class="text-xs text-gray-400">حاشیه سود:</span>
                                                <?php
                                                $p_margin = ($res['total_revenue'] > 0) ? ($res['total_profit'] / $res['total_revenue']) * 100 : 0;
                                                $badge_color = 'bg-red-50 text-red-600';
                                                if ($p_margin > 35) {
                                                    $badge_color = 'bg-emerald-50 text-emerald-600';
                                                } elseif ($p_margin > 20) {
                                                    $badge_color = 'bg-amber-50 text-amber-600';
                                                }
                                                ?>
                                                <span class="px-2.5 py-1 rounded-lg text-xs font-bold <?php echo $badge_color; ?>">
                                                    <?php echo number_format($p_margin, 1); ?>%
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="bg-gray-50/50 rounded-2xl border border-dashed border-gray-200 p-12 text-center text-gray-500">
                                    <i data-feather="inbox" class="w-12 h-12 mx-auto mb-4 text-gray-400"></i>
                                    <h4 class="font-bold text-gray-700">هیچ محصولی انتخاب نشده است</h4>
                                    <p class="text-xs text-gray-400 mt-1 max-w-sm mx-auto">لطفاً از پنل سمت راست، محصولات مورد نظر خود را جهت مقایسه و ارزیابی تیک بزنید و سپس دکمه اعمال مقایسه را کلیک کنید.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script>
        feather.replace();

        // Count initial selected products
        function updateSelectedCount() {
            const count = document.querySelectorAll('.compare-checkbox:checked').length;
            document.getElementById('selectedCount').textContent = `${count} انتخاب شده`;
        }
        updateSelectedCount();

        function toggleProductHighlight(checkbox) {
            const label = checkbox.closest('.product-label');
            if (checkbox.checked) {
                label.classList.add('bg-blue-50/60', 'border-blue-100/50');
            } else {
                label.classList.remove('bg-blue-50/60', 'border-blue-100/50');
            }
            updateSelectedCount();
        }

        // Live product search
        document.getElementById('productSearch').addEventListener('input', function(e) {
            const val = e.target.value.toLowerCase().trim();
            document.querySelectorAll('.product-label').forEach(label => {
                const name = label.getAttribute('data-name').toLowerCase();
                if (name.includes(val)) {
                    label.style.display = 'flex';
                } else {
                    label.style.display = 'none';
                }
            });
        });

        function deselectAllProducts() {
            document.querySelectorAll('.compare-checkbox').forEach(cb => {
                cb.checked = false;
                const label = cb.closest('.product-label');
                label.classList.remove('bg-blue-50/60', 'border-blue-100/50');
            });
            updateSelectedCount();
        }

        function selectTopThree() {
            deselectAllProducts();
            // Select first 3 available items in the list
            let count = 0;
            document.querySelectorAll('.compare-checkbox').forEach(cb => {
                if (count < 3) {
                    cb.checked = true;
                    const label = cb.closest('.product-label');
                    label.classList.add('bg-blue-50/60', 'border-blue-100/50');
                    count++;
                }
            });
            updateSelectedCount();
        }

        function submitComparisonForm() {
            // Collect checked product IDs
            const productIds = [];
            document.querySelectorAll('.compare-checkbox:checked').forEach(cb => {
                productIds.push(cb.value);
            });

            // Update action in GET params
            const url = new URL(window.location.href);
            url.searchParams.delete('compare_products[]');
            url.searchParams.delete('compare_products');

            // Re-set dates
            url.searchParams.set('start_date', document.getElementById('startDateInput').value);
            url.searchParams.set('end_date', document.getElementById('endDateInput').value);
            url.searchParams.set('submit_comparison', '1');

            // Construct new URL with multi-selection array
            let rawUrl = url.origin + url.pathname + '?start_date=' + encodeURIComponent(url.searchParams.get('start_date')) + '&end_date=' + encodeURIComponent(url.searchParams.get('end_date')) + '&submit_comparison=1';
            productIds.forEach(id => {
                rawUrl += '&compare_products[]=' + id;
            });

            window.location.href = rawUrl;
        }

        function setQuickRange(range) {
            const cy = <?php echo $currentJalaliYear; ?>;
            const cm = <?php echo $currentJalaliMonth; ?>;
            const cd = <?php echo $currentJalaliDay; ?>;

            let start = '';
            let end = '';

            if (range === 'current_month') {
                start = `${cy}/${String(cm).padStart(2, '0')}/01`;
                end = `${cy}/${String(cm).padStart(2, '0')}/${String(cd).padStart(2, '0')}`;
            } else if (range === 'last_3_months') {
                let startM = cm - 2;
                let startY = cy;
                if (startM <= 0) {
                    startM += 12;
                    startY -= 1;
                }
                start = `${startY}/${String(startM).padStart(2, '0')}/01`;
                end = `${cy}/${String(cm).padStart(2, '0')}/${String(cd).padStart(2, '0')}`;
            } else if (range === 'last_6_months') {
                let startM = cm - 5;
                let startY = cy;
                if (startM <= 0) {
                    startM += 12;
                    startY -= 1;
                }
                start = `${startY}/${String(startM).padStart(2, '0')}/01`;
                end = `${cy}/${String(cm).padStart(2, '0')}/${String(cd).padStart(2, '0')}`;
            } else if (range === 'current_year') {
                start = `${cy}/01/01`;
                end = `${cy}/${String(cm).padStart(2, '0')}/${String(cd).padStart(2, '0')}`;
            }

            document.getElementById('startDateInput').value = start;
            document.getElementById('endDateInput').value = end;

            // Keep compared products in quick range reloads
            submitComparisonForm();
        }

        // ==========================================
        // CHART 1: MONTHLY SALES & PROFIT
        // ==========================================
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        const monthlyChart = new Chart(monthlyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($chart_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'مجموع مبلغ فروش (تومان)',
                        data: <?php echo json_encode($chart_revenue); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.8)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'سود خالص خالص (تومان)',
                        data: <?php echo json_encode($chart_profit); ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.8)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 1,
                        borderRadius: 6,
                        yAxisID: 'y'
                    },
                    {
                        label: 'تعداد کل اقلام',
                        data: <?php echo json_encode($chart_quantity); ?>,
                        type: 'line',
                        borderColor: 'rgb(245, 158, 11)',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 3,
                        pointBackgroundColor: 'rgb(245, 158, 11)',
                        pointRadius: 4,
                        tension: 0.3,
                        yAxisID: 'y1',
                        fill: false
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: {
                            font: {
                                family: 'Vazirmatn, sans-serif',
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        titleFont: { family: 'Vazirmatn, sans-serif' },
                        bodyFont: { family: 'Vazirmatn, sans-serif' }
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return (value / 1000000).toLocaleString('fa-IR') + ' م تومان';
                            },
                            font: { family: 'Vazirmatn, sans-serif', size: 10 }
                        },
                        grid: {
                            drawOnChartArea: true,
                            color: '#f3f4f6'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fa-IR') + ' عدد';
                            },
                            font: { family: 'Vazirmatn, sans-serif', size: 10 }
                        },
                        grid: {
                            drawOnChartArea: false // prevent grid overlap
                        }
                    },
                    x: {
                        ticks: {
                            font: { family: 'Vazirmatn, sans-serif', size: 10 }
                        },
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });

        // ==========================================
        // CHART 2: PRODUCT FINANCIALS COMPARISON
        // ==========================================
        <?php if (!empty($comparison_results)): ?>
        const compCtx = document.getElementById('compChart').getContext('2d');
        const compChart = new Chart(compCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($comp_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'درآمد محصول (تومان)',
                        data: <?php echo json_encode($comp_revenue); ?>,
                        backgroundColor: 'rgba(59, 130, 246, 0.85)',
                        borderColor: 'rgb(59, 130, 246)',
                        borderWidth: 1,
                        borderRadius: 6,
                    },
                    {
                        label: 'سود محصول (تومان)',
                        data: <?php echo json_encode($comp_profit); ?>,
                        backgroundColor: 'rgba(16, 185, 129, 0.85)',
                        borderColor: 'rgb(16, 185, 129)',
                        borderWidth: 1,
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { family: 'Vazirmatn, sans-serif', size: 11 } }
                    },
                    tooltip: {
                        titleFont: { family: 'Vazirmatn, sans-serif' },
                        bodyFont: { family: 'Vazirmatn, sans-serif' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fa-IR') + ' تومان';
                            },
                            font: { family: 'Vazirmatn, sans-serif', size: 10 }
                        },
                        grid: { color: '#f3f4f6' }
                    },
                    x: {
                        ticks: { font: { family: 'Vazirmatn, sans-serif', size: 10 } },
                        grid: { display: false }
                    }
                }
            }
        });

        // ==========================================
        // CHART 3: PRODUCT QUANTITY COMPARISON
        // ==========================================
        const compQtyCtx = document.getElementById('compQtyChart').getContext('2d');
        const compQtyChart = new Chart(compQtyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($comp_labels, JSON_UNESCAPED_UNICODE); ?>,
                datasets: [
                    {
                        label: 'تعداد فروخته شده (عدد)',
                        data: <?php echo json_encode($comp_quantity); ?>,
                        backgroundColor: 'rgba(245, 158, 11, 0.85)',
                        borderColor: 'rgb(245, 158, 11)',
                        borderWidth: 1,
                        borderRadius: 6,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { font: { family: 'Vazirmatn, sans-serif', size: 11 } }
                    },
                    tooltip: {
                        titleFont: { family: 'Vazirmatn, sans-serif' },
                        bodyFont: { family: 'Vazirmatn, sans-serif' }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'right',
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fa-IR') + ' عدد';
                            },
                            font: { family: 'Vazirmatn, sans-serif', size: 10 }
                        },
                        grid: { color: '#f3f4f6' }
                    },
                    x: {
                        ticks: { font: { family: 'Vazirmatn, sans-serif', size: 10 } },
                        grid: { display: false }
                    }
                }
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>