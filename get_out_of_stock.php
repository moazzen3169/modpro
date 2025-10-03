<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Get products that have variants with stock = 0
    $outOfStockQuery = "
        SELECT
            p.product_id,
            p.model_name,
            p.brand,
            p.category,
            GROUP_CONCAT(
                CONCAT(
                    pv.color, ' - ', pv.size, ' (', pv.stock, ')'
                ) SEPARATOR '; '
            ) as out_of_stock_variants
        FROM Products p
        JOIN Product_Variants pv ON p.product_id = pv.product_id
        WHERE pv.stock = 0
        GROUP BY p.product_id, p.model_name, p.brand, p.category
        ORDER BY p.model_name
    ";

    $result = $conn->query($outOfStockQuery);

    $outOfStockProducts = [];
    while ($row = $result->fetch_assoc()) {
        $outOfStockProducts[] = [
            'product_id' => (int) $row['product_id'],
            'model_name' => $row['model_name'],
            'brand' => $row['brand'],
            'category' => $row['category'],
            'out_of_stock_variants' => $row['out_of_stock_variants']
        ];
    }

    echo json_encode([
        'products' => $outOfStockProducts
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در بارگذاری محصولات تمام شده: ' . $e->getMessage()]);
}
