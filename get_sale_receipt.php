    <?php
include 'env/db.php';

if (!isset($_GET['sale_id'])) {
    die('Sale ID not provided');
}

$sale_id = $_GET['sale_id'];

// Get sale details
$sale = $conn->query("
    SELECT s.*, c.name as customer_name
    FROM Sales s
    LEFT JOIN Customers c ON s.customer_id = c.customer_id
    WHERE s.sale_id = $sale_id
")->fetch_assoc();

if (!$sale) {
    die('Sale not found');
}

// Get sale items
$items = $conn->query("
    SELECT si.*, p.model_name, pv.color, pv.size
    FROM Sale_Items si
    JOIN Product_Variants pv ON si.variant_id = pv.variant_id
    JOIN Products p ON pv.product_id = p.product_id
    WHERE si.sale_id = $sale_id
");

// Calculate total
$total = 0;
while($item = $items->fetch_assoc()){
    $total += $item['quantity'] * $item['sell_price'];
    $sale_items[] = $item;
}
$items->data_seek(0); // Reset pointer

// Payment method text
$payment_methods = [
    'cash' => 'نقدی',
    'credit_card' => 'کارت اعتباری',
    'bank_transfer' => 'انتقال بانکی'
];
$payment_text = $payment_methods[$sale['payment_method']] ?? $sale['payment_method'];

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
            <div><strong>مشتری:</strong> <?php echo $sale['customer_name'] ?: 'مشتری حضوری'; ?></div>
            <div><strong>تاریخ:</strong> <?php echo $sale['sale_date']; ?></div>
            <div><strong>روش پرداخت:</strong> <?php echo $payment_text; ?></div>
            <div><strong>وضعیت:</strong> <?php echo $sale['status'] == 'paid' ? 'پرداخت شده' : 'در انتظار پرداخت'; ?></div>
        </div>

        <div class="items">
            <?php while($item = $items->fetch_assoc()): ?>
            <div class="item">
                <div class="item-name">
                    <?php echo $item['model_name']; ?><br>
                    <small><?php echo $item['color'] . ' / ' . $item['size']; ?></small>
                </div>
                <div class="item-details">
                    <?php echo $item['quantity']; ?> × <?php echo number_format($item['sell_price'], 0); ?><br>
                    <strong><?php echo number_format($item['quantity'] * $item['sell_price'], 0); ?> تومان</strong>
                </div>
            </div>
            <?php endwhile; ?>
        </div>

        <div class="total">
            <strong>مجموع کل: <?php echo number_format($total, 0); ?> تومان</strong>
        </div>

        <div class="footer">
            <p>با تشکر از خرید شما</p>
            <p>تاریخ چاپ: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
