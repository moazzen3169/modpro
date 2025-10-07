<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

// Get sales statistics
$today_sales_amount = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE DATE(s.sale_date) = CURDATE()")->fetch_assoc()['total'] ?: 0;
$month_sales_amount = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc()['total'] ?: 0;
$year_sales_amount = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc()['total'] ?: 0;

$today_sales_count = $conn->query("SELECT COUNT(DISTINCT s.sale_id) as count FROM Sales s WHERE DATE(s.sale_date) = CURDATE()")->fetch_assoc()['count'] ?: 0;
$month_sales_count = $conn->query("SELECT COUNT(DISTINCT s.sale_id) as count FROM Sales s WHERE MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc()['count'] ?: 0;
$year_sales_count = $conn->query("SELECT COUNT(DISTINCT s.sale_id) as count FROM Sales s WHERE YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc()['count'] ?: 0;

// Get best-selling products
$best_selling = $conn->query("SELECT p.model_name, SUM(si.quantity) as total_sold FROM Products p JOIN Product_Variants pv ON p.product_id = pv.product_id JOIN Sale_Items si ON pv.variant_id = si.variant_id GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");

// Get low-stock products
$low_stock = $conn->query("SELECT p.model_name, pv.color, pv.size, pv.stock FROM Products p JOIN Product_Variants pv ON p.product_id = pv.product_id WHERE pv.stock <= 5 ORDER BY pv.stock ASC LIMIT 10");

// Get sales data for last 30 days for chart
$sales_chart_data = [];
$sales_chart_labels = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $amount = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE DATE(s.sale_date) = '$date'")->fetch_assoc()['total'] ?: 0;
    $sales_chart_data[] = $amount;
    $sales_chart_labels[] = convert_gregorian_to_jalali_for_display($date);
}

// Get top products for bar chart
$top_products_chart = [];
$top_products_labels = [];
$top_products_data = [];
$top_products_query = $conn->query("SELECT p.model_name, SUM(si.quantity) as total_sold FROM Products p JOIN Product_Variants pv ON p.product_id = pv.product_id JOIN Sale_Items si ON pv.variant_id = si.variant_id GROUP BY p.product_id ORDER BY total_sold DESC LIMIT 5");
while ($row = $top_products_query->fetch_assoc()) {
    $top_products_labels[] = $row['model_name'];
    $top_products_data[] = $row['total_sold'];
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>داشبورد - SuitStore Manager Pro</title>
    <script src="libs/tailwind.js"></script>
    <script src="libs/feather-icons.js"></script>
    <script src="libs/chart.js"></script>
    <link href="libs/vazirmatn.css" rel="stylesheet">
    <style>
        body {
            font-family: 'Vazirmatn', sans-serif;
        }

        .sidebar-shadow {
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }

        .header-shadow {
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .card-hover {
            transition: all 0.3s ease;
        }

        .card-hover:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .animate-fade-in {
            animation: fadeIn 0.3s ease-in-out;
        }

        .animate-slide-up {
            animation: slideUp 0.3s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .low-stock {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <?php include 'sidebar.php'; ?>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto custom-scrollbar">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center header-shadow">
                <h2 class="text-xl font-semibold text-gray-800">داشبورد</h2>
                <div class="flex items-center space-x-4">
                    <div class="text-sm text-gray-500">
                        <?php echo htmlspecialchars(get_current_jalali_date_string(), ENT_QUOTES, 'UTF-8'); ?>
                    </div>
                </div>
            </header>

            <!-- Dashboard Content -->
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
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-50 rounded-lg ml-4">
                                <i data-feather="dollar-sign" class="w-6 h-6 text-blue-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش امروز</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($today_sales_amount, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-50 rounded-lg ml-4">
                                <i data-feather="trending-up" class="w-6 h-6 text-green-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش این ماه</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($month_sales_amount, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-50 rounded-lg ml-4">
                                <i data-feather="calendar" class="w-6 h-6 text-purple-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش امسال</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($year_sales_amount, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-indigo-50 rounded-lg ml-4">
                                <i data-feather="shopping-bag" class="w-6 h-6 text-indigo-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">تعداد فروش امروز</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($today_sales_count, 0); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-teal-50 rounded-lg ml-4">
                                <i data-feather="bar-chart-2" class="w-6 h-6 text-teal-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">تعداد فروش این ماه</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($month_sales_count, 0); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center">
                            <div class="p-3 bg-orange-50 rounded-lg ml-4">
                                <i data-feather="activity" class="w-6 h-6 text-orange-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">تعداد فروش امسال</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($year_sales_count, 0); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Sales Over Time Chart -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">نمودار فروش ۳۰ روز گذشته</h3>
                        <canvas id="salesChart" width="400" height="200"></canvas>
                    </div>

                    <!-- Top Products Chart -->
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">پرفروش‌ترین محصولات</h3>
                        <canvas id="topProductsChart" width="400" height="200"></canvas>
                    </div>
                </div>

                <!-- Tables Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Best Selling Products -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <h3 class="text-lg font-semibold text-gray-800">پرفروش‌ترین محصولات</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">محصول</th>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">تعداد فروش</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php
                                    if ($best_selling->num_rows > 0) {
                                        while($product = $best_selling->fetch_assoc()){
                                            echo "<tr class='hover:bg-gray-50'>
                                                    <td class='px-6 py-4 text-gray-800'>{$product['model_name']}</td>
                                                    <td class='px-6 py-4 text-gray-800 font-medium'>{$product['total_sold']}</td>
                                                </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='2' class='px-6 py-8 text-center text-gray-500'>هیچ فروشی ثبت نشده است</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Low Stock Products -->
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="p-6 border-b border-gray-100">
                            <h3 class="text-lg font-semibold text-gray-800">محصولات کم‌موجود</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">محصول</th>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">رنگ</th>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">سایز</th>
                                        <th class="px-6 py-3 text-right font-medium text-gray-700">موجودی</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <?php
                                    if ($low_stock->num_rows > 0) {
                                        while($item = $low_stock->fetch_assoc()){
                                            $stock_class = $item['stock'] == 0 ? 'low-stock' : '';
                                            echo "<tr class='hover:bg-gray-50 {$stock_class}'>
                                                    <td class='px-6 py-4 text-gray-800'>{$item['model_name']}</td>
                                                    <td class='px-6 py-4 text-gray-800'>{$item['color']}</td>
                                                    <td class='px-6 py-4 text-gray-800'>{$item['size']}</td>
                                                    <td class='px-6 py-4 text-gray-800 font-medium'>{$item['stock']}</td>
                                                </tr>";
                                        }
                                    } else {
                                        echo "<tr><td colspan='4' class='px-6 py-8 text-center text-gray-500'>هیچ محصول کم‌موجودی وجود ندارد</td></tr>";
                                    }
                                    ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        feather.replace();

        // Sales Chart
        const salesCtx = document.getElementById('salesChart').getContext('2d');
        const salesChart = new Chart(salesCtx, {
            type: 'line',
            data: {
                labels: [<?php echo "'" . implode("','", $sales_chart_labels) . "'"; ?>],
                datasets: [{
                    label: 'فروش روزانه (تومان)',
                    data: [<?php echo implode(',', $sales_chart_data); ?>],
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return value.toLocaleString('fa-IR') + ' تومان';
                            }
                        }
                    }
                }
            }
        });

        // Top Products Chart
        const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
        const topProductsChart = new Chart(topProductsCtx, {
            type: 'bar',
            data: {
                labels: [<?php echo "'" . implode("','", $top_products_labels) . "'"; ?>],
                datasets: [{
                    label: 'تعداد فروش',
                    data: [<?php echo implode(',', $top_products_data); ?>],
                    backgroundColor: 'rgba(34, 197, 94, 0.8)',
                    borderColor: 'rgb(34, 197, 94)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>
