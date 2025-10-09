<?php
require_once __DIR__ . '/env/bootstrap.php';

try {
    $sale_id = validate_int($_GET['sale_id'] ?? null, 1);
} catch (Throwable $e) {
    echo '<div style="text-align: center; padding: 50px; font-family: Vazirmatn, Arial, sans-serif;">
            <h2 style="color: #e74c3c;">خطا</h2>
            <p>شناسه فروش نامعتبر است.</p>
         </div>';
    exit();
}

$saleStmt = $conn->prepare('SELECT s.sale_id, s.sale_date, s.payment_method, s.status, COALESCE(c.name, "") AS customer_name FROM Sales s LEFT JOIN Customers c ON s.customer_id = c.customer_id WHERE s.sale_id = ?');
$saleStmt->bind_param('i', $sale_id);
$saleStmt->execute();
$sale = $saleStmt->get_result()->fetch_assoc();

if (!$sale) {
    echo '<div style="text-align: center; padding: 50px; font-family: Vazirmatn, Arial, sans-serif;">
            <h2 style="color: #e74c3c;">خطا</h2>
            <p>فروش با شماره ' . $sale_id . ' یافت نشد.</p>
         </div>';
    exit();
}

$itemsStmt = $conn->prepare('SELECT si.quantity, si.sell_price, p.model_name, pv.color, pv.size FROM Sale_Items si JOIN Product_Variants pv ON si.variant_id = pv.variant_id JOIN Products p ON pv.product_id = p.product_id WHERE si.sale_id = ?');
$itemsStmt->bind_param('i', $sale_id);
$itemsStmt->execute();
$itemsResult = $itemsStmt->get_result();

$sale_items = [];
$total = 0;
while ($item = $itemsResult->fetch_assoc()) {
    $quantity = (int) $item['quantity'];
    $sell_price = (float) $item['sell_price'];
    $line_total = $quantity * $sell_price;
    $total += $line_total;

    $sale_items[] = [
        'model_name' => htmlspecialchars((string) $item['model_name'], ENT_QUOTES, 'UTF-8'),
        'color' => htmlspecialchars((string) $item['color'], ENT_QUOTES, 'UTF-8'),
        'size' => htmlspecialchars((string) $item['size'], ENT_QUOTES, 'UTF-8'),
        'quantity' => $quantity,
        'sell_price' => $sell_price,
        'total' => $line_total,
    ];
}

if (count($sale_items) === 0) {
    echo '<div style="text-align: center; padding: 50px; font-family: Vazirmatn, Arial, sans-serif;">
            <h2 style="color: #f39c12;">اطلاعات ناقص</h2>
            <p>هیچ آیتمی برای فروش شماره ' . $sale_id . ' یافت نشد.</p>
         </div>';
    exit();
}

$payment_methods = [
    'cash' => 'نقدی',
    'credit_card' => 'کارت اعتباری',
    'bank_transfer' => 'انتقال بانکی',
];
$payment_text = htmlspecialchars($payment_methods[$sale['payment_method']] ?? $sale['payment_method'], ENT_QUOTES, 'UTF-8');
$customer_name = htmlspecialchars($sale['customer_name'] ?: 'مشتری حضوری', ENT_QUOTES, 'UTF-8');
$sale_date = htmlspecialchars(convert_gregorian_to_jalali_for_display((string) $sale['sale_date']), ENT_QUOTES, 'UTF-8');
$status_text = $sale['status'] === 'paid' ? 'پرداخت شده' : 'در انتظار پرداخت';
$status_text = htmlspecialchars($status_text, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>رسید فروش #<?php echo $sale_id; ?></title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;600;700&display=swap');

        body {
            font-family:peyda;
            margin: 0;
            padding: 10px;
            background: white;
            color: #333;
            direction: rtl;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;

        }

        .receipt {
            max-width: 88mm;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 10px;
            background: white;
            font-size: 12px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 8px;
            margin-bottom: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 10px;
            border-radius: 8px 8px 0 0;
        }

        .header h1 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }

        .header p {
            margin: 3px 0;
            font-size: 12px;
            color: #f0f0f0;
        }

        .store-info {
            margin-top: 5px;
            font-size: 10px;
            color: #e0e0e0;
        }

        .sale-info {
            margin-bottom: 15px;
            font-size: 12px;
        }

        .sale-info div {
            margin-bottom: 3px;
        }

        table.items-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 12px;
        }

        table.items-table th,
        table.items-table td {
            border: 1px solid #ddd;
            padding: 4px 6px;
            text-align: center;
            font-size: 10px;
        }

        table.items-table th {
            background-color: #f5f5f5;
            font-weight: 600;
        }

        .total {
            text-align: left;
            font-weight: 700;
            font-size: 14px;
            border-top: 2px solid #333;
            padding-top: 8px;
            margin-top: 8px;
        }

        .footer {
            text-align: center;
            font-size: 10px;
            color: #666;
            margin-top: 15px;
        }

        @media print {
            body {
                padding: 0;
                font-size: 11px;
            }
            .receipt {
                border: none;
                max-width: none;
                width: 80mm;
                margin: 0;
                padding: 5px;
            }
            table.items-table th,
            table.items-table td {
                border: 1px solid #000;
                padding: 3px 5px;
            }
            .header {
                padding: 10px 5px;
                margin-bottom: 10px;
            }
            .sale-info {
                margin-bottom: 10px;
            }
            .total {
                font-size: 13px;
                padding-top: 5px;
                margin-top: 5px;
                border-top: 1px solid #000;
            }
            .footer {
                font-size: 9px;
                margin-top: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>فروشگاه هادی</h1>
            <p>کتشلوار و کت دامن </p>
            <p>رسید فروش</p>
            <p>شماره فروش: #<?php echo $sale_id; ?></p>
            <div class="store-info">
                آدرس : تبریز بازار پاساژ و میدان نماز ورودی 1 و 7
                تلفن:09911631448
            </div>
        </div>

        <div class="sale-info">
            <div><strong>مشتری:</strong> <?php echo $customer_name; ?></div>
            <div><strong>تاریخ:</strong> <?php echo $sale_date; ?></div>
            <div><strong>روش پرداخت:</strong> <?php echo $payment_text; ?></div>
            <div><strong>وضعیت:</strong> <?php echo $status_text; ?></div>
        </div>

        <table class="items-table" cellspacing="0" cellpadding="0" border="0">
            <thead>
                <tr>
                    <th>نام کالا</th>
                    <th>رنگ</th>
                    <th>سایز</th>
                    <th>تعداد</th>
                    <th>قیمت واحد</th>
                    <th>جمع</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sale_items as $item): ?>
                <tr>
                    <td style="text-align: right;"><?php echo $item['model_name']; ?></td>
                    <td><?php echo $item['color']; ?></td>
                    <td><?php echo $item['size']; ?></td>
                    <td><?php echo $item['quantity']; ?></td>
                    <td><?php echo number_format($item['sell_price'], 0); ?> تومان</td>
                    <td><?php echo number_format($item['total'], 0); ?> تومان</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="total">
            مجموع کل: <?php echo number_format($total, 0); ?> تومان
        </div>

        <div class="footer">
            <p>از خرید شما سپاسگزاریم</p>
            <p>لطفاً رسید را نزد خود نگه دارید</p>
        </div>
    </div>
</body>
</html>
