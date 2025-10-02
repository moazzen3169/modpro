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
$sale_date = htmlspecialchars((string) $sale['sale_date'], ENT_QUOTES, 'UTF-8');
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
            font-family: 'Vazirmatn', sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
            color: #333;
            direction: rtl;
        }

        .receipt {
            max-width: 80mm;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 20px;
            background: white;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px 8px 0 0;
        }

        .header h1 {
            margin: 0;
            font-size: 28px;
            font-weight: 700;
            text-shadow: 1px 1px 2px rgba(0,0,0,0.3);
        }

        .header p {
            margin: 5px 0;
            font-size: 16px;
            color: #f0f0f0;
        }

        .store-info {
            margin-top: 10px;
            font-size: 14px;
            color: #e0e0e0;
        }

        .sale-info {
            margin-bottom: 20px;
        }

        .sale-info div {
            margin-bottom: 5px;
            font-size: 14px;
        }

        .items {
            border-top: 1px solid #ddd;
            border-bottom: 1px solid #ddd;
            padding: 10px 0;
            margin-bottom: 20px;
        }

        .item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .item-name {
            flex: 1;
            margin-left: 10px;
        }

        .item-details {
            text-align: left;
            min-width: 80px;
        }

        .total {
            text-align: left;
            font-weight: 600;
            font-size: 16px;
            border-top: 1px solid #333;
            padding-top: 10px;
            margin-top: 10px;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: #666;
            margin-top: 20px;
        }

        @media print {
            body {
                padding: 0;
            }
            .receipt {
                border: none;
                max-width: none;
                width: 90%;
            }
        }
    </style>
</head>
<body>
    <div class="receipt">
        <div class="header">
            <h1>آرس</h1>
            <p>فروشگاه پوشاک مردانه</p>
            <p>رسید فروش</p>
            <p>شماره فروش: #<?php echo $sale_id; ?></p>
            <div class="store-info">
                تلفن: 021-12345678 | آدرس: تهران، خیابان ولیعصر
            </div>
        </div>

        <div class="sale-info">
            <div><strong>مشتری:</strong> <?php echo $customer_name; ?></div>
            <div><strong>تاریخ:</strong> <?php echo $sale_date; ?></div>
            <div><strong>روش پرداخت:</strong> <?php echo $payment_text; ?></div>
            <div><strong>وضعیت:</strong> <?php echo $status_text; ?></div>
        </div>

        <div class="items">
            <?php foreach ($sale_items as $item): ?>
                <div class="item">
                    <div class="item-name">
                        <div><?php echo $item['model_name']; ?></div>
                        <div style="color: #666; font-size: 12px;">رنگ: <?php echo $item['color']; ?> | سایز: <?php echo $item['size']; ?></div>
                    </div>
                    <div class="item-details">
                        <div><?php echo $item['quantity']; ?> عدد</div>
                        <div><?php echo number_format($item['sell_price'], 0); ?> تومان</div>
                        <div style="font-weight: 600;"><?php echo number_format($item['total'], 0); ?> تومان</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

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
