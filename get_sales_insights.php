<?php
require_once __DIR__ . '/env/bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'روش درخواست نامعتبر است.']);
    exit();
}

$dateFilter = trim((string) ($_POST['date'] ?? ''));
$searchTerm = trim((string) ($_POST['search'] ?? ''));
$categoryFilter = trim((string) ($_POST['category'] ?? ''));
$supplierId = null;
$supplierName = null;
$productId = null;
$jalaliYear = null;
$jalaliMonth = null;

if (isset($_POST['supplier_id'])) {
    $supplierFilterValue = $_POST['supplier_id'];
    if ($supplierFilterValue !== '' && $supplierFilterValue !== 'all') {
        try {
            $candidateId = validate_int($supplierFilterValue, 1);
            $supplierCheck = $conn->prepare('SELECT supplier_id, name FROM Suppliers WHERE supplier_id = ?');
            $supplierCheck->bind_param('i', $candidateId);
            $supplierCheck->execute();
            $supplierRow = $supplierCheck->get_result()->fetch_assoc();
            if ($supplierRow) {
                $supplierId = $candidateId;
                $supplierName = $supplierRow['name'] ?? '';
            }
            $supplierCheck->close();
        } catch (Throwable $e) {
            echo json_encode(['error' => normalize_error_message($e)]);
            exit();
        }
    }
}

try {
    if ($dateFilter !== '') {
        $dateFilter = validate_date($dateFilter);
    }

    if (isset($_POST['jalali_year']) && $_POST['jalali_year'] !== '') {
        $jalaliYear = validate_int($_POST['jalali_year'], 1300);
    }

    if (isset($_POST['jalali_month']) && $_POST['jalali_month'] !== '' && $_POST['jalali_month'] !== 'all') {
        $jalaliMonth = validate_int($_POST['jalali_month'], 1);
        if ($jalaliMonth < 1 || $jalaliMonth > 12) {
            throw new InvalidArgumentException('ماه انتخاب‌شده نامعتبر است.');
        }
    }

    if (isset($_POST['product_id']) && $_POST['product_id'] !== '') {
        $productId = validate_int($_POST['product_id'], 1);
    }
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['error' => normalize_error_message($e)]);
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

if ($jalaliYear !== null) {
    if ($jalaliMonth !== null) {
        [$startRange, $endRange] = get_jalali_month_gregorian_range($jalaliYear, $jalaliMonth);
    } else {
        [$startRange, $endRange] = get_jalali_year_gregorian_range($jalaliYear);
    }

    $conditions[] = 's.sale_date BETWEEN ? AND ?';
    $params[] = $startRange;
    $params[] = $endRange;
    $types .= 'ss';
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

if ($categoryFilter !== '') {
    $conditions[] = 'p.category = ?';
    $params[] = $categoryFilter;
    $types .= 's';
}

if ($productId !== null) {
    $conditions[] = 'p.product_id = ?';
    $params[] = $productId;
    $types .= 'i';
}

$latestSupplierQuery = "SELECT pi.variant_id, pu.supplier_id, pi.buy_price"
    . " FROM Purchase_Items pi"
    . " JOIN Purchases pu ON pi.purchase_id = pu.purchase_id"
    . " WHERE pi.purchase_id = ("
    . "     SELECT pi2.purchase_id"
    . "     FROM Purchase_Items pi2"
    . "     JOIN Purchases pu2 ON pi2.purchase_id = pu2.purchase_id"
    . "     WHERE pi2.variant_id = pi.variant_id"
    . "     ORDER BY pu2.purchase_date DESC, pi2.purchase_id DESC"
    . "     LIMIT 1"
    . " )";

$query = 'SELECT '
    . 'COALESCE(SUM(si.quantity), 0) AS total_quantity,'
    . ' COALESCE(SUM(si.quantity * si.sell_price), 0) AS total_amount,'
    . ' COALESCE(SUM((si.sell_price - COALESCE(ls.buy_price, 0)) * si.quantity), 0) AS total_profit'
    . ' FROM Sale_Items si'
    . ' JOIN Sales s ON s.sale_id = si.sale_id'
    . ' LEFT JOIN Customers c ON s.customer_id = c.customer_id'
    . ' LEFT JOIN Product_Variants pv ON si.variant_id = pv.variant_id'
    . ' LEFT JOIN Products p ON pv.product_id = p.product_id'
    . ' LEFT JOIN (' . $latestSupplierQuery . ') ls ON ls.variant_id = si.variant_id';

if ($supplierId !== null) {
    $conditions[] = 'ls.supplier_id = ?';
    $params[] = $supplierId;
    $types .= 'i';
}

if ($conditions !== []) {
    $query .= ' WHERE ' . implode(' AND ', $conditions);
}

$stmt = $conn->prepare($query);
if ($stmt === false) {
    http_response_code(500);
    echo json_encode(['error' => 'خطا در آماده‌سازی کوئری خلاصه فروش.']);
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
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $summaryParts = [];

    if ($dateFilter !== '') {
        $summaryParts[] = 'تاریخ ' . convert_gregorian_to_jalali_for_display($dateFilter);
    } elseif ($jalaliYear !== null) {
        if ($jalaliMonth !== null) {
            $summaryParts[] = sprintf('ماه %s %d', get_jalali_month_name($jalaliMonth), $jalaliYear);
        } else {
            $summaryParts[] = 'سال ' . $jalaliYear;
        }
    }

    if ($supplierId !== null) {
        $summaryParts[] = 'تامین‌کننده: ' . ($supplierName ?? ('#' . $supplierId));
    }

    if ($categoryFilter !== '') {
        $summaryParts[] = 'دسته‌بندی: ' . $categoryFilter;
    }

    if ($productId !== null) {
        $productNameStmt = $conn->prepare('SELECT model_name FROM Products WHERE product_id = ?');
        $productNameStmt->bind_param('i', $productId);
        $productNameStmt->execute();
        $productName = $productNameStmt->get_result()->fetch_assoc()['model_name'] ?? null;
        $productNameStmt->close();

        $summaryParts[] = 'محصول: ' . ($productName ?? ('#' . $productId));
    }

    $summary = $summaryParts !== [] ? implode(' | ', $summaryParts) : 'همه فروش‌ها در بازه موجود';

    echo json_encode([
        'total_quantity' => (float) ($result['total_quantity'] ?? 0),
        'total_amount' => (float) ($result['total_amount'] ?? 0),
        'total_profit' => (float) ($result['total_profit'] ?? 0),
        'summary' => $summary,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => normalize_error_message($e)]);
}
