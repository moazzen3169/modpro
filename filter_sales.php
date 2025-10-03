<?php
declare(strict_types=1);

require_once __DIR__ . '/env/bootstrap.php';
require_once __DIR__ . '/includes/sales_table_renderer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo '<div class="text-center py-8 text-red-500">درخواست نامعتبر است.</div>';
    exit();
}

$dateFilter = trim((string) ($_POST['date'] ?? ''));
$searchTerm = trim((string) ($_POST['search'] ?? ''));

try {
    if ($dateFilter !== '') {
        $dateFilter = validate_date($dateFilter);
    }
} catch (Throwable $e) {
    http_response_code(422);
    $message = htmlspecialchars(normalize_error_message($e), ENT_QUOTES, 'UTF-8');
    echo '<div class="text-center py-8 text-red-500">' . $message . '</div>';
    exit();
}

$conditions = [];
$params = [];
$types = '';

if ($dateFilter !== '') {
    $conditions[] = 'DATE(s.sale_date) = ?';
    $params[] = $dateFilter;
    $types .= 's';
}

if ($searchTerm !== '') {
    $conditions[] = '('
        . 'CAST(s.sale_id AS CHAR) LIKE ? OR '
        . 'COALESCE(c.name, "") LIKE ? OR '
        . 's.payment_method LIKE ? OR '
        . 's.status LIKE ?'
        . ')';
    $likeTerm = '%' . $searchTerm . '%';
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $params[] = $likeTerm;
    $types .= 'ssss';
}

$query = 'SELECT s.*, c.name AS customer_name, COUNT(si.sale_item_id) AS item_count, '
    . 'SUM(si.quantity * si.sell_price) AS total_amount '
    . 'FROM Sales s '
    . 'LEFT JOIN Customers c ON s.customer_id = c.customer_id '
    . 'LEFT JOIN Sale_Items si ON s.sale_id = si.sale_id';

if ($conditions !== []) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}

$query .= ' GROUP BY s.sale_id ORDER BY s.sale_date DESC, s.sale_id DESC';

$stmt = $conn->prepare($query);
if ($stmt === false) {
    http_response_code(500);
    echo '<div class="text-center py-8 text-red-500">خطا در آماده‌سازی کوئری فیلتر.</div>';
    exit();
}

if ($params !== []) {
    $bindParams = [];
    $bindParams[] = &$types;
    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

try {
    $stmt->execute();
    $result = $stmt->get_result();
    echo render_sales_table($result);
} catch (Throwable $e) {
    http_response_code(500);
    $message = htmlspecialchars(normalize_error_message($e), ENT_QUOTES, 'UTF-8');
    echo '<div class="text-center py-8 text-red-500">' . $message . '</div>';
} finally {
    $stmt->close();
}
