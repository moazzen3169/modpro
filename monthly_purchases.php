<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

$suppliers = [];
$supplierMap = [];
$suppliersResult = $conn->query('SELECT supplier_id, name FROM Suppliers ORDER BY name');
if ($suppliersResult) {
    while ($row = $suppliersResult->fetch_assoc()) {
        $row['supplier_id'] = (int) $row['supplier_id'];
        $suppliers[] = $row;
        $supplierMap[$row['supplier_id']] = $row['name'];
    }
    $suppliersResult->free();
}

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

$selected_supplier_id = null;
if (isset($_GET['supplier_id'])) {
    $supplierFilterValue = $_GET['supplier_id'];
    if ($supplierFilterValue !== '' && $supplierFilterValue !== 'all') {
        try {
            $candidateId = validate_int($supplierFilterValue, 1);
            if (isset($supplierMap[$candidateId])) {
                $selected_supplier_id = $candidateId;
            }
        } catch (Throwable) {
            // Ignore invalid supplier filter and fallback to all suppliers
        }
    }
}

$selected_supplier_name = $selected_supplier_id !== null
    ? ($supplierMap[$selected_supplier_id] ?? 'تامین‌کننده نامشخص')
    : 'همه تامین‌کننده‌ها';

$supplier_purchase_condition = $selected_supplier_id !== null
    ? ' AND pu.supplier_id = ' . (int) $selected_supplier_id
    : '';

$supplier_return_condition = $selected_supplier_id !== null
    ? ' AND prr.supplier_id = ' . (int) $selected_supplier_id
    : '';

$supplier_balance_condition = $selected_supplier_id !== null
    ? ' AND sb.supplier_id = ' . (int) $selected_supplier_id
    : '';

$supplier_historical_balance_condition = $selected_supplier_id !== null
    ? ' AND supplier_id = ' . (int) $selected_supplier_id
    : '';

// Get monthly purchases grouped by product
$monthly_purchases_query = "
    SELECT
        p.product_id,
        p.model_name,
        SUM(pi.quantity) AS total_quantity,
        AVG(pi.buy_price) AS avg_buy_price,
        SUM(pi.quantity * pi.buy_price) AS total_amount
    FROM Purchases pu
    JOIN Purchase_Items pi ON pu.purchase_id = pi.purchase_id
    JOIN Product_Variants pv ON pi.variant_id = pv.variant_id
    JOIN Products p ON pv.product_id = p.product_id
    WHERE pu.purchase_date BETWEEN '$start_date' AND '$end_date'" . $supplier_purchase_condition . "
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
        prr.purchase_return_id,
        prr.purchase_id,
        prr.return_date,
        prr.total_amount,
        prr.note,
        s.name AS supplier_name
    FROM Purchase_Returns prr
    LEFT JOIN Suppliers s ON prr.supplier_id = s.supplier_id
    WHERE prr.return_date BETWEEN '$start_date' AND '$end_date'" . $supplier_return_condition . "
    ORDER BY prr.return_date
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
            prr.purchase_return_id,
            SUM(pi.quantity * pi.buy_price) AS purchase_total
        FROM Purchase_Returns prr
        JOIN Purchase_Items pi ON prr.purchase_id = pi.purchase_id
        WHERE prr.return_date BETWEEN '$start_date' AND '$end_date'" . $supplier_return_condition . "
        GROUP BY prr.purchase_return_id
    ";

    if ($purchase_totals_result = $conn->query($return_purchase_totals_query)) {
        while ($row = $purchase_totals_result->fetch_assoc()) {
            $return_purchase_totals[(int) $row['purchase_return_id']] = (float) $row['purchase_total'];
        }
    }
}

// Get return quantities per product from purchase_return_items
$returns_per_product_query = "
    SELECT
        p.product_id,
        p.model_name,
        SUM(prit.quantity) AS return_quantity,
        SUM(prit.quantity * prit.return_price) AS return_amount
    FROM Purchase_Returns prr
    JOIN Purchase_Return_Items prit ON prr.purchase_return_id = prit.purchase_return_id
    JOIN Product_Variants pv ON prit.variant_id = pv.variant_id
    JOIN Products p ON pv.product_id = p.product_id
    WHERE prr.return_date BETWEEN '$start_date' AND '$end_date'" . $supplier_return_condition . "
    GROUP BY p.product_id, p.model_name
";

$returns_per_product_result = $conn->query($returns_per_product_query);

$returns_per_product = [];
if ($returns_per_product_result && $returns_per_product_result->num_rows > 0) {
    while ($return = $returns_per_product_result->fetch_assoc()) {
        $product_id = (int) $return['product_id'];
        $returns_per_product[$product_id] = [
            'return_quantity' => (float) $return['return_quantity'],
            'return_amount' => (float) $return['return_amount'],
        ];
    }
}

// Allocate returns to products
foreach ($monthly_purchases_data as $product_id => $data) {
    $return_quantity = $returns_per_product[$product_id]['return_quantity'] ?? 0.0;
    $return_amount = $returns_per_product[$product_id]['return_amount'] ?? 0.0;

    $monthly_purchases_data[$product_id]['return_quantity'] = $return_quantity;
    $monthly_purchases_data[$product_id]['return_amount'] = $return_amount;
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
    WHERE (sb.balance_year < $selected_year OR (sb.balance_year = $selected_year AND sb.balance_month < $selected_month))" . $supplier_balance_condition . "
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
    WHERE (balance_year < $selected_year OR (balance_year = $selected_year AND balance_month < $selected_month))" . $supplier_historical_balance_condition . "
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

// Generate invoice number including supplier context
$invoice_supplier_segment = $selected_supplier_id !== null
    ? 'S' . str_pad((string) $selected_supplier_id, 3, '0', STR_PAD_LEFT)
    : 'ALL';
$invoice_number = sprintf('INV-%04d-%02d-%s-%03d', $selected_year, $selected_month, $invoice_supplier_segment, rand(100, 999));
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فاکتور ماهانه خریدها - SuitStore Manager Pro</title>
    <script src="libs/tailwind.js"></script>
    <script src="libs/feather-icons.js"></script>
    <link rel="stylesheet" href="libs/vazirmatn.css">
    <link href="css/global.css" rel="stylesheet">
    <style>
        body {
            line-height: 1;
        }
        .invoice-container {
            max-width: 700px;
            margin: 0 auto;
            background: white;
            /* border: 1px solid #000; */
        }
        .invoice-header {
            display: flex;
            justify-content: space-between;
            align-items: right;
            padding: 10px;
            border:  1px solid #000;
            background: #f9f9f9;
            font-size: 14px;
            direction: ltr;
        }
        .company-info h1 {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin: 0;
        }
        .company-info p {
            font-size: 12px;
            color: #666;
            margin: 5px 0 0;
        }
        .invoice-title h2 {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin: 0;
            text-align: right;
        }
        .invoice-title p {
            font-size: 12px;
            color: #666;
            margin: 2px 0;
            text-align: right;
        }
        .supplier-info {
            padding:10px 20px 0px 10px;
            border: 0px solid #000;
            border-left: 1px solid #000;
            border-bottom: 1px solid #000;
            border-right: 1px solid #000;
            background: #fafafa;
            display: flex;
            gap: 10px;
            direction: rtl;

        }
        .supplier-info h3 {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin: 0 0 10px;
            direction: rtl;
        }
        .supplier-info p {
            font-size: 12px;
            color: #666;
            direction: rtl;
            margin: 0;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            font-size: 12px;
        }
        .invoice-table th {
            background: #fafafa;
            color: #000;
            font-weight: bold;
            padding: 5px;
            border: 1px solid #000;
            text-align: center;
        }
        .invoice-table td {
            padding: 5px;

            border: 1px solid #000;
            text-align: center;
            
        }
        .total-section {
            padding: 10px;
            background: #f9f9f9;
            border: 1px solid #000;
            width: 50%;
            margin-bottom: 10px;
        }
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 12px;
        }
        .total-row.final {
            font-weight: bold;
            font-size: 14px;
            border-top: 1px solid #000;
            padding-top: 10px;
            margin-top: 10px;
            color: #333;
        }
        .invoice-footer {
            padding: 20px;
            border-top: 1px solid #000;
            text-align: center;
            font-size: 11px;
            color: #666;
        }
       
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                background: white;
                paddin: 10px;
                padding: 0;
            }
            .invoice-container {
                box-shadow: none;
                border: none;
                margin: 0;
                padding: 10px;
                max-width: none;
                border: 1px solid #000;

            }
            @page {
                size: A5;
                margin: 10px;
            }
        }
    </style>
</head>
<body class="bg-gray-50  text-gray-900" dir="rtl">
<div class="flex h-screen overflow-hidden">
<?php include 'sidebar.php'; ?>
<main class="flex-1 overflow-y-auto bg-gray-50 p-6">
    <!-- Filter Section -->
    <div class="bg-white p-6 mb-6 rounded-lg shadow-sm border border-gray-200 no-print">
        <h2 class="text-xl font-bold text-gray-800 mb-4">انتخاب دوره و تامین‌کننده</h2>
        <form method="GET" class="flex flex-wrap gap-4 items-end">
            <div class="flex flex-col">
                <label for="month" class="text-sm font-medium text-gray-700 mb-1">ماه:</label>
                <select name="month" id="month" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo get_jalali_month_name($m); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex flex-col">
                <label for="year" class="text-sm font-medium text-gray-700 mb-1">سال:</label>
                <select name="year" id="year" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <?php for ($y = $current_year - 5; $y <= $current_year; $y++): ?>
                        <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="flex flex-col">
                <label for="supplier_id" class="text-sm font-medium text-gray-700 mb-1">تامین‌کننده:</label>
                <select name="supplier_id" id="supplier_id" class="px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <option value="all" <?php echo $selected_supplier_id === null ? 'selected' : ''; ?>>همه تامین‌کننده‌ها</option>
                    <?php foreach ($suppliers as $supplier): ?>
                        <option value="<?php echo (int) $supplier['supplier_id']; ?>" <?php echo $selected_supplier_id === (int) $supplier['supplier_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                نمایش فاکتور
            </button>
        </form>
    </div>
    <div class="invoice-container">
        <!-- Invoice Header -->
        <div class="invoice-header">
            <div class="company-info">
                <h1>فروشگاه هادی</h1>
                <p>تبریز / بازار / میدان و پاساژ نماز ورودی1 و 7</p>
                <p>+98 9911631448</p>
            </div>
            <div class="invoice-title">
                <h2>فاکتور خرید ماهانه</h2>
                <p>شماره فاکتور: <?php echo $invoice_number; ?></p>
                <p>تاریخ: <?php echo get_current_jalali_date_string(); ?></p>
            </div>
        </div>

        <div class="supplier-info">
            <h3>تامین‌کننده:</h3>
            <p><?php echo htmlspecialchars($selected_supplier_name, ENT_QUOTES, 'UTF-8'); ?></p>
            <p>دوره: <?php echo get_jalali_month_name($selected_month); ?> <?php echo $selected_year; ?></p>
        </div>

        <!-- Invoice Items Table -->
        <table class="invoice-table">
            <thead>
                <tr>
                    <th>شرح کالا</th>
                    <th>تعداد</th>
                    <th>قیمت واحد (تومان)</th>
                    <th>مبلغ (تومان)</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($monthly_purchases_data)): ?>
                    <?php foreach ($monthly_purchases_data as $purchase): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($purchase['model_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo number_format($purchase['net_quantity'], 0); ?></td>
                            <td><?php echo number_format($purchase['avg_buy_price'], 0); ?></td>
                            <td><?php echo number_format($purchase['net_amount'], 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" style="text-align:center">هیچ خریدی در این ماه ثبت نشده است</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="total-section">
            <div class="total-row">
                <span>جمع کل خرید:</span>
                <span><?php echo number_format($total_purchases, 0); ?> تومان</span>
            </div>
            <?php if ($total_returns > 0): ?>
                <div class="total-row" style="color:red">
                    <span>مرجوعی‌ها:</span>
                    <span>-<?php echo number_format($total_returns, 0); ?> تومان</span>
                </div>
            <?php endif; ?>
            <div class="total-row">
                <span>خالص خرید:</span>
                <span><?php echo number_format($net_total, 0); ?> تومان</span>
            </div>
            <?php if ($total_previous_debts > 0): ?>
                <div class="total-row">
                    <span>بدهی‌های قبلی:</span>
                    <span><?php echo number_format($total_previous_debts, 0); ?> تومان</span>
                </div>
            <?php endif; ?>
            <div class="total-row final">
                <span>مجموع قابل پرداخت:</span>
                <span><?php echo number_format($net_total + $total_previous_debts, 0); ?> تومان</span>
            </div>
        </div>

        <div class="invoice-footer">
            <p>این فاکتور به صورت خودکار تولید شده و معتبر است.</p>
        </div>
    </div>

    <script>
        feather.replace();
    </script>
</body>
</html>