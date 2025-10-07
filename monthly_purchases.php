<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

// Get current month and year for default selection
$current_jalali = get_current_jalali_date();
$current_year = $current_jalali[0];
$current_month = $current_jalali[1];

// Get selected month/year from GET parameters
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : $current_year;
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : $current_month;

// Validate month/year
if ($selected_month < 1 || $selected_month > 12) {
    $selected_month = $current_month;
}
if ($selected_year < 1390 || $selected_year > $current_year + 10) {
    $selected_year = $current_year;
}

// Convert Jalali to Gregorian for database queries
$selected_month_days = get_jalali_month_days($selected_year, $selected_month);
$gregorian_start = jalali_to_gregorian($selected_year, $selected_month, 1);
$gregorian_end = jalali_to_gregorian($selected_year, $selected_month, $selected_month_days);

$start_date = sprintf('%04d-%02d-%02d', $gregorian_start[0], $gregorian_start[1], $gregorian_start[2]);
$end_date = sprintf('%04d-%02d-%02d', $gregorian_end[0], $gregorian_end[1], $gregorian_end[2]);

// Get monthly purchases grouped by product
$monthly_purchases_query = "
    SELECT
        p.product_id,
        p.model_name,
        SUM(pi.quantity) AS total_quantity,
        AVG(pi.buy_price) AS avg_buy_price,
        SUM(pi.quantity * pi.buy_price) AS total_amount
    FROM Purchases pr
    JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
    JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
    JOIN Products p ON pv.product_id = p.product_id
    WHERE pr.purchase_date BETWEEN '$start_date' AND '$end_date'
    GROUP BY p.product_id, p.model_name
    ORDER BY p.model_name
";

$monthly_purchases_result = $conn->query($monthly_purchases_query);

// Prepare structured purchase data for rendering and calculations
$monthly_purchases_data = [];
$total_purchases = 0;

if ($monthly_purchases_result && $monthly_purchases_result->num_rows > 0) {
    while ($purchase = $monthly_purchases_result->fetch_assoc()) {
        $product_id = (int) $purchase['product_id'];
        $total_amount = (float) $purchase['total_amount'];
        $total_quantity = (float) $purchase['total_quantity'];

        $monthly_purchases_data[$product_id] = [
            'product_id' => $product_id,
            'model_name' => $purchase['model_name'],
            'total_quantity' => $total_quantity,
            'avg_buy_price' => (float) $purchase['avg_buy_price'],
            'total_amount' => $total_amount,
            'return_quantity' => 0.0,
            'return_amount' => 0.0,
            'net_quantity' => $total_quantity,
            'net_amount' => $total_amount,
        ];

        $total_purchases += $total_amount;
    }
}

// Get monthly returns (if any exist in Purchase_Returns table)
$monthly_returns_query = "
    SELECT
        pr.purchase_return_id,
        pr.purchase_id,
        pr.return_date,
        pr.total_amount,
        pr.note,
        s.name AS supplier_name
    FROM Purchase_Returns pr
    LEFT JOIN Suppliers s ON pr.supplier_id = s.supplier_id
    WHERE pr.return_date BETWEEN '$start_date' AND '$end_date'
    ORDER BY pr.return_date
";

$monthly_returns_result = $conn->query($monthly_returns_query);

$monthly_returns_data = [];
$total_returns = 0;
$return_totals_map = [];

if ($monthly_returns_result && $monthly_returns_result->num_rows > 0) {
    while ($return = $monthly_returns_result->fetch_assoc()) {
        $return['total_amount'] = (float) $return['total_amount'];
        $monthly_returns_data[] = $return;
        $total_returns += $return['total_amount'];
        $return_totals_map[(int) $return['purchase_return_id']] = $return;
    }
}

// Map purchase return IDs to the total value of the original purchase for proportional allocation
$return_purchase_totals = [];
if (!empty($return_totals_map)) {
    $return_purchase_totals_query = "
        SELECT
            pr.purchase_return_id,
            SUM(pi.quantity * pi.buy_price) AS purchase_total
        FROM Purchase_Returns pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        WHERE pr.return_date BETWEEN '$start_date' AND '$end_date'
        GROUP BY pr.purchase_return_id
    ";

    if ($purchase_totals_result = $conn->query($return_purchase_totals_query)) {
        while ($row = $purchase_totals_result->fetch_assoc()) {
            $return_purchase_totals[(int) $row['purchase_return_id']] = (float) $row['purchase_total'];
        }
    }
}

// Allocate returns proportionally to products based on their contribution in the original purchase
if (!empty($return_totals_map)) {
    $returns_allocation_query = "
        SELECT
            pr.purchase_return_id,
            p.product_id,
            p.model_name,
            SUM(pi.quantity) AS product_purchase_quantity,
            SUM(pi.quantity * pi.buy_price) AS product_purchase_total
        FROM Purchase_Returns pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
        JOIN Products p ON pv.product_id = p.product_id
        WHERE pr.return_date BETWEEN '$start_date' AND '$end_date'
        GROUP BY pr.purchase_return_id, p.product_id
    ";

    if ($returns_allocation_result = $conn->query($returns_allocation_query)) {
        while ($allocation = $returns_allocation_result->fetch_assoc()) {
            $return_id = (int) $allocation['purchase_return_id'];
            $product_id = (int) $allocation['product_id'];
            $purchase_total = $return_purchase_totals[$return_id] ?? 0.0;
            $return_total_amount = $return_totals_map[$return_id]['total_amount'] ?? 0.0;
            $product_purchase_total = (float) $allocation['product_purchase_total'];

            if ($purchase_total <= 0 || $return_total_amount <= 0 || $product_purchase_total <= 0) {
                continue;
            }

            $allocation_ratio = $product_purchase_total / $purchase_total;
            $allocated_amount = $allocation_ratio * $return_total_amount;

            $product_purchase_quantity = (float) $allocation['product_purchase_quantity'];
            $average_price = $product_purchase_quantity > 0 ? $product_purchase_total / $product_purchase_quantity : 0.0;
            $allocated_quantity = $average_price > 0 ? $allocated_amount / $average_price : 0.0;

            if (!isset($monthly_purchases_data[$product_id])) {
                $monthly_purchases_data[$product_id] = [
                    'product_id' => $product_id,
                    'model_name' => $allocation['model_name'],
                    'total_quantity' => 0.0,
                    'avg_buy_price' => 0.0,
                    'total_amount' => 0.0,
                    'return_quantity' => 0.0,
                    'return_amount' => 0.0,
                    'net_quantity' => 0.0,
                    'net_amount' => 0.0,
                ];
            }

            $monthly_purchases_data[$product_id]['return_quantity'] += $allocated_quantity;
            $monthly_purchases_data[$product_id]['return_amount'] += $allocated_amount;
        }
    }
}

// Update net values for each product after allocating returns
foreach ($monthly_purchases_data as $product_id => $data) {
    $return_quantity = $data['return_quantity'] ?? 0.0;
    $return_amount = $data['return_amount'] ?? 0.0;

    $monthly_purchases_data[$product_id]['net_quantity'] = $data['total_quantity'] - $return_quantity;
    $monthly_purchases_data[$product_id]['net_amount'] = $data['total_amount'] - $return_amount;
}

$net_total = $total_purchases - $total_returns;

// Get previous debts (supplier balances from previous months)
$previous_debts_query = "
    SELECT
        s.name as supplier_name,
        sb.closing_balance
    FROM Supplier_Balances sb
    JOIN Suppliers s ON sb.supplier_id = s.supplier_id
    WHERE (sb.balance_year < $selected_year OR (sb.balance_year = $selected_year AND sb.balance_month < $selected_month))
    AND sb.closing_balance > 0
    ORDER BY sb.balance_year DESC, sb.balance_month DESC, s.name
";

$previous_debts_result = $conn->query($previous_debts_query);

$previous_debts_data = [];
$total_previous_debts = 0.0;

if ($previous_debts_result && $previous_debts_result->num_rows > 0) {
    while ($debt = $previous_debts_result->fetch_assoc()) {
        $debt['closing_balance'] = (float) $debt['closing_balance'];
        $total_previous_debts += $debt['closing_balance'];
        $previous_debts_data[] = $debt;
    }
}

// Aggregate historical invoices for context and to highlight prior balances
$previous_invoices_query = "
    SELECT
        balance_year,
        balance_month,
        SUM(total_purchases) AS monthly_purchases,
        SUM(total_returns) AS monthly_returns,
        SUM(closing_balance) AS closing_balance
    FROM Supplier_Balances
    WHERE (balance_year < $selected_year OR (balance_year = $selected_year AND balance_month < $selected_month))
    GROUP BY balance_year, balance_month
    ORDER BY balance_year DESC, balance_month DESC
    LIMIT 12
";

$previous_invoices_result = $conn->query($previous_invoices_query);
$previous_invoices = [];
$historical_invoices_total = 0.0;

if ($previous_invoices_result && $previous_invoices_result->num_rows > 0) {
    while ($invoice = $previous_invoices_result->fetch_assoc()) {
        $invoice['closing_balance'] = (float) $invoice['closing_balance'];
        $invoice['monthly_purchases'] = (float) $invoice['monthly_purchases'];
        $invoice['monthly_returns'] = (float) $invoice['monthly_returns'];
        $historical_invoices_total += $invoice['closing_balance'];
        $previous_invoices[] = $invoice;
    }
}

// Fetch current stock per product to display live inventory status
$product_inventory_query = "
    SELECT
        p.product_id,
        SUM(pv.stock) AS total_stock
    FROM Products p
    LEFT JOIN Product_Variants pv ON p.product_id = pv.product_id
    GROUP BY p.product_id
";

$product_inventory_result = $conn->query($product_inventory_query);
$product_inventory_map = [];

if ($product_inventory_result && $product_inventory_result->num_rows > 0) {
    while ($inventory = $product_inventory_result->fetch_assoc()) {
        $product_inventory_map[(int) $inventory['product_id']] = (int) $inventory['total_stock'];
    }
}

foreach ($monthly_purchases_data as $product_id => $data) {
    $monthly_purchases_data[$product_id]['current_stock'] = $product_inventory_map[$product_id] ?? 0;
}

if (!empty($monthly_purchases_data)) {
    uasort($monthly_purchases_data, function ($a, $b) {
        return strcmp($a['model_name'], $b['model_name']);
    });
}

$total_current_stock = 0;
foreach ($monthly_purchases_data as $product_data) {
    $total_current_stock += (int) ($product_data['current_stock'] ?? 0);
}

// Generate invoice number
$invoice_number = sprintf('INV-%04d-%02d-%03d', $selected_year, $selected_month, rand(100, 999));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور ماهانه خریدها - SuitStore Manager Pro</title>
    <script src="libs/tailwind.js"></script>
    <script src="libs/feather-icons.js"></script>
    <link href="css/global.css" rel="stylesheet">
    <style>
        .invoice-container {
            max-width: 210mm;
            margin: 0 auto;
            background: white;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .invoice-header {
            background: linear-gradient(135deg, #1e40af, #3b82f6);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .invoice-header h1 {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .invoice-header .subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        .invoice-details {
            padding: 30px;
            border-bottom: 2px solid #e5e7eb;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        .invoice-table th,
        .invoice-table td {
            padding: 12px;
            text-align: right;
            border-bottom: 1px solid #e5e7eb;
        }
        .invoice-table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
        }
        .total-section {
            background: #f9fafb;
            padding: 20px;
            border-top: 2px solid #e5e7eb;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            font-size: 16px;
        }
        .total-row.final {
            font-size: 18px;
            font-weight: bold;
            color: #1f2937;
            border-top: 1px solid #d1d5db;
            margin-top: 10px;
            padding-top: 20px;
        }
        .returns-section {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
        .debts-section {
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
        }
        .invoice-footer {
            border-top: 2px solid #e5e7eb;
            padding: 15px;
            text-align: center;
            background: #f9fafb;
        }
        .footer-content p {
            margin: 5px 0;
            font-size: 14px;
        }
        .footer-note {
            font-style: italic;
            color: #666;
        }
        @media print {
            /* Hide non-invoice elements */
            body { background: white; margin: 0; padding: 0; font-family: 'Vazirmatn', Arial, sans-serif; font-size: 12px; }
            .flex.h-screen.overflow-hidden { display: block; height: auto; overflow: visible; }
            aside, header, .no-print { display: none !important; }
            main { padding: 0; margin: 0; }

            /* A5 paper size optimization */
            @page {
                size: A5;
                margin: 10mm;
            }

            /* Invoice container for print */
            .invoice-container {
                box-shadow: none;
                margin: 0;
                width: 100%;
                max-width: none;
                page-break-inside: avoid;
                border: 1px solid #000;
                background: white;
            }

            /* Professional header for print */
            .invoice-header {
                background: white;
                color: #000;
                padding: 15px 20px;
                text-align: center;
                border-bottom: 1px double #000;
                position: relative;
            }
            .invoice-header::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 3px;
                background: #000;
            }
            .invoice-header p {
                font-size: 14px;
                margin: 5px 0;
                color: #000;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            /* Professional details section */
            .invoice-details {
                padding: 15px 20px;
                /* border-bottom: 1px solid #000; */
                background: white;
            }
            .invoice-details h3 {
                font-size: 13px;
                margin-bottom: 8px;
                color: #000;
                font-weight: bold;
                text-decoration: underline;
                text-transform: uppercase;
            }
            .invoice-details p {
                font-size: 11px;
                margin-bottom: 4px;
                color: #000;
                line-height: 1.5;
            }

            /* Professional table for print */
            .invoice-table {
                font-size: 11px;
                margin: 10px 0;
                border-collapse: collapse;
                width: 100%;
                border: 1px solid #000;
            }
            .invoice-table th,
            .invoice-table td {
                padding: 8px 10px;
                text-align: right;
                border-bottom: 1px solid #000;
                border-right: 1px solid #000;
            }
            .invoice-table th {
                background: white;
                font-weight: bold;
                color: #000;
                font-size: 10px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .invoice-table td {
                color: #000;
                background: white;
            }

            /* Professional sections */
            .returns-section,
            .debts-section {
                padding: 10px 15px;
                margin: 15px 0;
                page-break-inside: avoid;
                background: white;
                border-radius: 0;
            }
            .returns-section h4,
            .debts-section h4 {
                font-size: 12px;
                margin-bottom: 8px;
                color: #000;
                font-weight: bold;
                text-decoration: underline;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* Professional totals section */
            .total-section {
                padding: 15px 20px;
                margin-top: 15px;
                page-break-inside: avoid;
                background: white;
            }
            .total-row {
                font-size: 12px;
                padding: 6px 0;
                color: #000;
                display: flex;
                justify-content: space-between;
                font-weight: 600;
            }
            .total-row.final {
                font-size: 16px;
                padding-top: 10px;
                margin-top: 10px;
                border-top: 1px solid #000;
                font-weight: bold;
                color: #000;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }

            /* Invoice footer */
            .invoice-footer {
                border-top: 1px solid #000;
                padding: 15px 20px;
                text-align: center;
                background: white;
                page-break-inside: avoid;
            }
            .footer-content p {
                margin: 3px 0;
                font-size: 11px;
                color: #000;
                font-weight: bold;
            }
            .footer-note {
                font-style: italic;
                color: #333;
                font-size: 10px;
            }

            /* Ensure no page breaks in important sections */
            .invoice-header,
            .total-section,
            .invoice-footer {
                page-break-inside: avoid;
            }

            /* Professional grid layout */
            .grid.grid-cols-2.gap-6.mb-6 {
                gap: 6;
                margin-bottom: 8;
                display: flex;
                justify-content: space-between;
            }
            .grid.grid-cols-2.gap-6.mb-6 > div {
                flex: 1;
                margin: 0 8px;
            }


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
                <h2 class="text-xl font-semibold text-gray-800">فاکتور ماهانه خریدها</h2>
                <div class="flex items-center space-x-4">
                    <!-- Month/Year Selector -->
                    <form method="GET" class="flex items-center space-x-2 space-x-reverse">
                        <select name="month" class="border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>>
                                    <?php echo get_jalali_month_name($m); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <?php for ($y = $current_year - 5; $y <= $current_year; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <button type="submit" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                            <i data-feather="search" class="ml-2"></i>
                            نمایش
                        </button>
                    </form>

                    <button onclick="window.print()" class="flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors no-print">
                        <i data-feather="printer" class="ml-2"></i>
                        چاپ
                    </button>
                </div>
            </header>

            <!-- Invoice Content -->
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

                <div class="invoice-container">
                    <!-- Invoice Header -->
                    <div class="invoice-header">
                        <div class="mt-4">
                            <p class="text-lg">فاکتور خرید ماه <?php echo get_jalali_month_name($selected_month); ?> <?php echo $selected_year; ?></p>
                            <p class="text-sm opacity-75">شماره فاکتور: <?php echo $invoice_number; ?></p>
                        </div>
                    </div>

                    <!-- Invoice Details -->
                    <div class="invoice-details">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
                                <h3 class="font-semibold text-gray-800 mb-3">اطلاعات دوره</h3>
                                <ul class="space-y-2 text-sm text-gray-700 leading-relaxed">
                                    <li><strong class="text-gray-900">ماه انتخابی:</strong> <?php echo get_jalali_month_name($selected_month); ?> <?php echo $selected_year; ?></li>

                                    <li><strong class="text-gray-900">تعداد محصولات در گزارش:</strong> <?php echo count($monthly_purchases_data); ?> مورد</li>
                                    <li><strong class="text-gray-900">موجودی فعلی محصولات:</strong> <?php echo number_format($total_current_stock, 0); ?> عدد</li>
                                </ul>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-5">
                                <h3 class="font-semibold text-gray-800 mb-3">وضعیت مالی فاکتور</h3>
                                <ul class="space-y-2 text-sm text-gray-700 leading-relaxed">
                                    <li><strong class="text-gray-900">تاریخ صدور:</strong> <?php echo get_current_jalali_date_string(); ?></li>
                                    <li><strong class="text-gray-900">شماره فاکتور:</strong> <?php echo $invoice_number; ?></li>
                                    <li><strong class="text-gray-900">مجموع خریدها:</strong> <span class="font-semibold text-indigo-600"><?php echo number_format($total_purchases, 0); ?> تومان</span></li>
                                    <li><strong class="text-gray-900">مجموع مرجوعی‌ها:</strong> <span class="font-semibold text-rose-600"><?php echo number_format($total_returns, 0); ?> تومان</span></li>
                                    <li><strong class="text-gray-900">خالص خرید ماه:</strong> <span class="font-semibold text-emerald-600"><?php echo number_format($net_total, 0); ?> تومان</span></li>
                                </ul>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-8">
                            <div class="rounded-2xl bg-gradient-to-br from-blue-500 to-blue-600 text-white p-5 shadow-lg">
                                <p class="text-sm opacity-80">خریدهای این ماه</p>
                                <p class="text-2xl font-bold mt-2"><?php echo number_format($total_purchases, 0); ?> تومان</p>
                            </div>
                            <div class="rounded-2xl bg-gradient-to-br from-red-500 to-rose-600 text-white p-5 shadow-lg">
                                <p class="text-sm opacity-80">مرجوعی‌های این ماه</p>
                                <p class="text-2xl font-bold mt-2"><?php echo number_format($total_returns, 0); ?> تومان</p>
                            </div>
                            <div class="rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 text-white p-5 shadow-lg">
                                <p class="text-sm opacity-80">مجموع حساب‌های قبلی</p>
                                <p class="text-2xl font-bold mt-2"><?php echo number_format($total_previous_debts, 0); ?> تومان</p>
                            </div>
                            <div class="rounded-2xl bg-gradient-to-br from-amber-500 to-orange-500 text-white p-5 shadow-lg">
                                <p class="text-sm opacity-80">مجموع نهایی فاکتورهای قبلی</p>
                                <p class="text-2xl font-bold mt-2"><?php echo number_format($historical_invoices_total, 0); ?> تومان</p>
                            </div>
                        </div>

                        <!-- Products Table -->
                        <?php if (!empty($monthly_purchases_data)): ?>
                            <h3 class="font-semibold text-gray-800 mb-4">ریز خرید و مرجوعی محصولات</h3>
                            <table class="invoice-table">
                                <thead>
                                    <tr>
                                        <th>نام محصول</th>
                                        <th>تعداد خرید</th>
                                        <th>مبلغ خرید</th>
                                        <th>تعداد مرجوعی</th>
                                        <th>مبلغ مرجوعی</th>
                                        <th>تعداد خالص</th>
                                        <th>مبلغ خالص</th>
                                        <th>موجودی فعلی</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_purchases_data as $purchase): ?>
                                        <tr>
                                            <td class="font-medium"><?php echo htmlspecialchars($purchase['model_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo number_format($purchase['total_quantity'], 0); ?></td>
                                            <td><?php echo number_format($purchase['total_amount'], 0); ?> تومان</td>
                                            <td class="text-red-600"><?php echo number_format($purchase['return_quantity'], 0); ?></td>
                                            <td class="text-red-600"><?php echo number_format($purchase['return_amount'], 0); ?> تومان</td>
                                            <td class="text-emerald-600 font-semibold"><?php echo number_format($purchase['net_quantity'], 0); ?></td>
                                            <td class="text-emerald-600 font-semibold"><?php echo number_format($purchase['net_amount'], 0); ?> تومان</td>
                                            <td><?php echo number_format($purchase['current_stock'], 0); ?> عدد</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center py-8 text-gray-500">
                                <i data-feather="shopping-bag" class="w-12 h-12 mx-auto mb-4 opacity-50"></i>
                                <p>هیچ خریدی در این ماه ثبت نشده است.</p>
                            </div>
                        <?php endif; ?>

                        <!-- Returns Section -->
                        <?php if (!empty($monthly_returns_data)): ?>
                            <div class="returns-section">
                                <h4 class="font-semibold text-red-800 mb-3 flex items-center">
                                    <i data-feather="refresh-ccw" class="ml-2"></i>
                                    مرجوعی‌های ماه
                                </h4>
                                <table class="invoice-table">
                                    <thead>
                                        <tr>
                                            <th>تاریخ</th>
                                            <th>تامین کننده</th>
                                            <th>مبلغ مرجوعی</th>
                                            <th>توضیحات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($monthly_returns_data as $return): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars(convert_gregorian_to_jalali_for_display($return['return_date']), ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td><?php echo htmlspecialchars($return['supplier_name'] ?: 'تامین کننده نامشخص', ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-red-600"><?php echo number_format($return['total_amount'], 0); ?> تومان</td>
                                                <td><?php echo htmlspecialchars($return['note'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- Previous Debts Section -->
                        <?php if (!empty($previous_debts_data)): ?>
                            <div class="debts-section">
                                <h4 class="font-semibold text-yellow-800 mb-3 flex items-center">
                                    <i data-feather="alert-triangle" class="ml-2"></i>
                                    بدهی‌های ماه‌های قبلی</h4>
                                <table class="invoice-table">
                                    <thead>
                                        <tr>
                                            <th>تامین کننده</th>
                                            <th>مبلغ بدهی</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($previous_debts_data as $debt): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($debt['supplier_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                <td class="text-yellow-600 font-medium"><?php echo number_format($debt['closing_balance'], 0); ?> تومان</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($previous_invoices)): ?>
                            <div class="mt-6">
                                <h4 class="font-semibold text-gray-800 mb-3 flex items-center">
                                    <i data-feather="calendar" class="ml-2"></i>
                                    روند فاکتورهای پیشین
                                </h4>
                                <table class="invoice-table">
                                    <thead>
                                        <tr>
                                            <th>ماه</th>
                                            <th>مجموع خرید</th>
                                            <th>مجموع مرجوعی</th>
                                            <th>مانده نهایی</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($previous_invoices as $invoice): ?>
                                            <tr>
                                                <td><?php echo get_jalali_month_name((int) $invoice['balance_month']); ?> <?php echo $invoice['balance_year']; ?></td>
                                                <td><?php echo number_format($invoice['monthly_purchases'], 0); ?> تومان</td>
                                                <td class="text-red-600"><?php echo number_format($invoice['monthly_returns'], 0); ?> تومان</td>
                                                <td class="font-semibold"><?php echo number_format($invoice['closing_balance'], 0); ?> تومان</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>

                        <!-- Totals Section -->
                        <div class="total-section">
                            <div class="total-row">
                                <span>مجموع خریدها:</span>
                                <span><?php echo number_format($total_purchases, 0); ?> تومان</span>
                            </div>
                            <div class="total-row">
                                <span>مجموع مرجوعی‌ها:</span>
                                <span class="text-red-600"><?php echo $total_returns > 0 ? '-' . number_format($total_returns, 0) . ' تومان' : '0 تومان'; ?></span>
                            </div>
                            <div class="total-row">
                                <span>خالص خرید ماه:</span>
                                <span><?php echo number_format($net_total, 0); ?> تومان</span>
                            </div>
                            <div class="total-row">
                                <span>مانده فاکتورهای قبلی:</span>
                                <span><?php echo number_format($total_previous_debts, 0); ?> تومان</span>
                            </div>
                            <div class="total-row">
                                <span>مجموع نهایی فاکتورهای گذشته:</span>
                                <span><?php echo number_format($historical_invoices_total, 0); ?> تومان</span>
                            </div>
                            <div class="total-row final">
                                <span>مبلغ قابل پرداخت این ماه:</span>
                                <span><?php echo number_format($net_total + $total_previous_debts, 0); ?> تومان</span>
                            </div>
                        </div>

                        <!-- Invoice Footer -->
                        <div class="invoice-footer">
                            <div class="footer-content">
                                <p><strong>شماره فاکتور:</strong> <?php echo $invoice_number; ?></p>
                                <p><strong>تاریخ صدور:</strong> <?php echo get_current_jalali_date_string(); ?></p>
                                <p class="footer-note">این فاکتور به صورت خودکار تولید شده و معتبر است.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>
