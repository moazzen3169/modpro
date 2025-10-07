<?php
declare(strict_types=1);

require_once __DIR__ . '/env/bootstrap.php';

function sanitize_supplier_input(array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    if ($name === '') {
        throw new InvalidArgumentException('نام تامین‌کننده الزامی است.');
    }
    if (mb_strlen($name, 'UTF-8') > 255) {
        throw new InvalidArgumentException('نام تامین‌کننده بیش از حد طولانی است.');
    }

    $phone = trim((string)($input['phone'] ?? ''));
    if ($phone !== '') {
        if (mb_strlen($phone, 'UTF-8') > 20 || !preg_match('/^[0-9+\-\s]{4,20}$/u', $phone)) {
            throw new InvalidArgumentException('شماره تماس تامین‌کننده معتبر نیست.');
        }
    }

    $email = trim((string)($input['email'] ?? ''));
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email, 'UTF-8') > 255)) {
        throw new InvalidArgumentException('آدرس ایمیل تامین‌کننده معتبر نیست.');
    }

    $address = trim((string)($input['address'] ?? ''));
    if ($address !== '' && mb_strlen($address, 'UTF-8') > 1000) {
        throw new InvalidArgumentException('آدرس تامین‌کننده بیش از حد طولانی است.');
    }

    return [
        'name' => $name,
        'phone' => $phone !== '' ? $phone : null,
        'email' => $email !== '' ? $email : null,
        'address' => $address !== '' ? $address : null,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirectSupplierId = null;

    try {
        if ($action === 'create_supplier') {
            $payload = sanitize_supplier_input($_POST);
            $name = $payload['name'];
            $phone = $payload['phone'];
            $email = $payload['email'];
            $address = $payload['address'];

            $stmt = $conn->prepare('INSERT INTO Suppliers (name, phone, email, address) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $name, $phone, $email, $address);
            $stmt->execute();
            $newSupplierId = (int) $conn->insert_id;
            $stmt->close();

            add_flash_message('success', 'تامین‌کننده جدید با موفقیت ثبت شد.');
            header('Location: suppliers.php?supplier_id=' . $newSupplierId);
            exit();
        }

        if ($action === 'update_supplier') {
            $supplierId = validate_int($_POST['supplier_id'] ?? null, 1);
            $redirectSupplierId = $supplierId;

            $checkStmt = $conn->prepare('SELECT supplier_id FROM Suppliers WHERE supplier_id = ?');
            $checkStmt->bind_param('i', $supplierId);
            $checkStmt->execute();
            if (!$checkStmt->get_result()->fetch_row()) {
                throw new RuntimeException('تامین‌کننده موردنظر یافت نشد.');
            }
            $checkStmt->close();

            $payload = sanitize_supplier_input($_POST);
            $name = $payload['name'];
            $phone = $payload['phone'];
            $email = $payload['email'];
            $address = $payload['address'];

            $updateStmt = $conn->prepare('UPDATE Suppliers SET name = ?, phone = ?, email = ?, address = ? WHERE supplier_id = ?');
            $updateStmt->bind_param('ssssi', $name, $phone, $email, $address, $supplierId);
            $updateStmt->execute();
            $updateStmt->close();

            add_flash_message('success', 'اطلاعات تامین‌کننده با موفقیت به‌روزرسانی شد.');
            header('Location: suppliers.php?supplier_id=' . $supplierId);
            exit();
        }

        if ($action === 'delete_supplier') {
            $supplierId = validate_int($_POST['supplier_id'] ?? null, 1);

            $usageStmt = $conn->prepare('
                SELECT
                    (SELECT COUNT(*) FROM Purchases WHERE supplier_id = ?) AS purchase_count,
                    (SELECT COUNT(*) FROM Purchase_Returns WHERE supplier_id = ?) AS return_count
            ');
            $usageStmt->bind_param('ii', $supplierId, $supplierId);
            $usageStmt->execute();
            $usage = $usageStmt->get_result()->fetch_assoc() ?: ['purchase_count' => 0, 'return_count' => 0];
            $usageStmt->close();

            if ((int) $usage['purchase_count'] > 0 || (int) $usage['return_count'] > 0) {
                throw new RuntimeException('حذف این تامین‌کننده به دلیل وجود فاکتورهای ثبت‌شده امکان‌پذیر نیست.');
            }

            $deleteStmt = $conn->prepare('DELETE FROM Suppliers WHERE supplier_id = ?');
            $deleteStmt->bind_param('i', $supplierId);
            $deleteStmt->execute();
            $deleteStmt->close();

            add_flash_message('success', 'تامین‌کننده با موفقیت حذف شد.');
            header('Location: suppliers.php');
            exit();
        }
    } catch (Throwable $e) {
        add_flash_message('error', normalize_error_message($e));
        $redirectUrl = 'suppliers.php';
        if ($redirectSupplierId !== null) {
            $redirectUrl .= '?supplier_id=' . $redirectSupplierId;
        }
        header('Location: ' . $redirectUrl);
        exit();
    }
}

$flash_messages = get_flash_messages();

$suppliersSql = <<<SQL
SELECT
    s.supplier_id,
    s.name,
    s.phone,
    s.email,
    s.address,
    s.created_at,
    (SELECT COUNT(*) FROM Purchases pr WHERE pr.supplier_id = s.supplier_id) AS purchase_count,
    (SELECT COALESCE(SUM(pi.quantity), 0) FROM Purchases pr JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id WHERE pr.supplier_id = s.supplier_id) AS purchase_total_quantity,
    (SELECT COALESCE(SUM(pi.quantity * pi.buy_price), 0) FROM Purchases pr JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id WHERE pr.supplier_id = s.supplier_id) AS purchase_total_amount,
    (SELECT COUNT(*) FROM Purchase_Returns rr WHERE rr.supplier_id = s.supplier_id) AS return_count,
    (SELECT COALESCE(SUM(ri.quantity), 0) FROM Purchase_Returns rr JOIN Purchase_Return_Items ri ON rr.purchase_return_id = ri.purchase_return_id WHERE rr.supplier_id = s.supplier_id) AS return_total_quantity,
    (SELECT COALESCE(SUM(ri.quantity * ri.return_price), 0) FROM Purchase_Returns rr JOIN Purchase_Return_Items ri ON rr.purchase_return_id = ri.purchase_return_id WHERE rr.supplier_id = s.supplier_id) AS return_total_amount
FROM Suppliers s
ORDER BY s.name
SQL;

$suppliersResult = $conn->query($suppliersSql);
$suppliers = [];
$supplierMap = [];
if ($suppliersResult) {
    while ($row = $suppliersResult->fetch_assoc()) {
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['purchase_count'] = (int) $row['purchase_count'];
        $row['return_count'] = (int) $row['return_count'];
        $row['purchase_total_quantity'] = (float) $row['purchase_total_quantity'];
        $row['return_total_quantity'] = (float) $row['return_total_quantity'];
        $row['purchase_total_amount'] = (float) $row['purchase_total_amount'];
        $row['return_total_amount'] = (float) $row['return_total_amount'];
        $row['net_total_amount'] = $row['purchase_total_amount'] - $row['return_total_amount'];

        $suppliers[] = $row;
        $supplierMap[$row['supplier_id']] = $row;
    }
    $suppliersResult->free();
}

$selectedSupplierId = null;
if (isset($_GET['supplier_id'])) {
    try {
        $candidateId = validate_int($_GET['supplier_id'], 1);
        if (isset($supplierMap[$candidateId])) {
            $selectedSupplierId = $candidateId;
        }
    } catch (Throwable) {
        // Ignore invalid supplier id
    }
}

if ($selectedSupplierId === null && !empty($suppliers)) {
    $selectedSupplierId = $suppliers[0]['supplier_id'];
}

$selectedSupplier = $selectedSupplierId !== null ? ($supplierMap[$selectedSupplierId] ?? null) : null;
$selectedSupplierName = $selectedSupplier['name'] ?? 'تامین‌کننده انتخاب نشده است';

$selectedTotals = [
    'purchase_count' => $selectedSupplier['purchase_count'] ?? 0,
    'purchase_total_quantity' => $selectedSupplier['purchase_total_quantity'] ?? 0.0,
    'purchase_total_amount' => $selectedSupplier['purchase_total_amount'] ?? 0.0,
    'return_count' => $selectedSupplier['return_count'] ?? 0,
    'return_total_quantity' => $selectedSupplier['return_total_quantity'] ?? 0.0,
    'return_total_amount' => $selectedSupplier['return_total_amount'] ?? 0.0,
];
$selectedTotals['net_total_amount'] = $selectedTotals['purchase_total_amount'] - $selectedTotals['return_total_amount'];

$selectedCreatedAt = '';
if ($selectedSupplier && !empty($selectedSupplier['created_at'])) {
    $selectedCreatedAt = convert_gregorian_to_jalali_for_display((string) $selectedSupplier['created_at']);
}

$purchaseList = [];
$returnsList = [];
$monthlyReport = [];
$annualReport = [];

if ($selectedSupplierId !== null) {
    $purchaseListStmt = $conn->prepare('
        SELECT pr.purchase_id, pr.purchase_date,
               SUM(pi.quantity) AS total_quantity,
               SUM(pi.quantity * pi.buy_price) AS total_amount
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        WHERE pr.supplier_id = ?
        GROUP BY pr.purchase_id, pr.purchase_date
        ORDER BY pr.purchase_date DESC, pr.purchase_id DESC
        LIMIT 100
    ');
    $purchaseListStmt->bind_param('i', $selectedSupplierId);
    $purchaseListStmt->execute();
    $purchaseResult = $purchaseListStmt->get_result();
    while ($row = $purchaseResult->fetch_assoc()) {
        $purchaseList[] = [
            'purchase_id' => (int) $row['purchase_id'],
            'purchase_date' => convert_gregorian_to_jalali_for_display((string) $row['purchase_date']),
            'total_quantity' => (float) $row['total_quantity'],
            'total_amount' => (float) $row['total_amount'],
        ];
    }
    $purchaseListStmt->close();

    $returnsListStmt = $conn->prepare('
        SELECT r.purchase_return_id, r.return_date,
               COALESCE(SUM(ri.quantity), 0) AS total_quantity,
               COALESCE(SUM(ri.quantity * ri.return_price), 0) AS total_amount
        FROM Purchase_Returns r
        LEFT JOIN Purchase_Return_Items ri ON r.purchase_return_id = ri.purchase_return_id
        WHERE r.supplier_id = ?
        GROUP BY r.purchase_return_id, r.return_date
        ORDER BY r.return_date DESC, r.purchase_return_id DESC
        LIMIT 100
    ');
    $returnsListStmt->bind_param('i', $selectedSupplierId);
    $returnsListStmt->execute();
    $returnsResult = $returnsListStmt->get_result();
    while ($row = $returnsResult->fetch_assoc()) {
        $returnsList[] = [
            'purchase_return_id' => (int) $row['purchase_return_id'],
            'return_date' => convert_gregorian_to_jalali_for_display((string) $row['return_date']),
            'total_quantity' => (float) $row['total_quantity'],
            'total_amount' => (float) $row['total_amount'],
        ];
    }
    $returnsListStmt->close();

    $monthlyPurchasesStmt = $conn->prepare('
        SELECT YEAR(pr.purchase_date) AS gregorian_year,
               MONTH(pr.purchase_date) AS gregorian_month,
               SUM(pi.quantity) AS total_quantity,
               SUM(pi.quantity * pi.buy_price) AS total_amount
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        WHERE pr.supplier_id = ?
        GROUP BY YEAR(pr.purchase_date), MONTH(pr.purchase_date)
        ORDER BY YEAR(pr.purchase_date) DESC, MONTH(pr.purchase_date) DESC
        LIMIT 12
    ');
    $monthlyPurchasesStmt->bind_param('i', $selectedSupplierId);
    $monthlyPurchasesStmt->execute();
    $monthlyPurchasesResult = $monthlyPurchasesStmt->get_result();
    $monthlyPurchaseMap = [];
    while ($row = $monthlyPurchasesResult->fetch_assoc()) {
        $key = sprintf('%04d-%02d', (int) $row['gregorian_year'], (int) $row['gregorian_month']);
        $monthlyPurchaseMap[$key] = [
            'quantity' => (float) $row['total_quantity'],
            'amount' => (float) $row['total_amount'],
        ];
    }
    $monthlyPurchasesStmt->close();

    $monthlyReturnsStmt = $conn->prepare('
        SELECT YEAR(r.return_date) AS gregorian_year,
               MONTH(r.return_date) AS gregorian_month,
               SUM(ri.quantity) AS total_quantity,
               SUM(ri.quantity * ri.return_price) AS total_amount
        FROM Purchase_Returns r
        JOIN Purchase_Return_Items ri ON r.purchase_return_id = ri.purchase_return_id
        WHERE r.supplier_id = ?
        GROUP BY YEAR(r.return_date), MONTH(r.return_date)
        ORDER BY YEAR(r.return_date) DESC, MONTH(r.return_date) DESC
        LIMIT 12
    ');
    $monthlyReturnsStmt->bind_param('i', $selectedSupplierId);
    $monthlyReturnsStmt->execute();
    $monthlyReturnsResult = $monthlyReturnsStmt->get_result();
    $monthlyReturnMap = [];
    while ($row = $monthlyReturnsResult->fetch_assoc()) {
        $key = sprintf('%04d-%02d', (int) $row['gregorian_year'], (int) $row['gregorian_month']);
        $monthlyReturnMap[$key] = [
            'quantity' => (float) $row['total_quantity'],
            'amount' => (float) $row['total_amount'],
        ];
    }
    $monthlyReturnsStmt->close();

    $monthKeys = array_unique(array_merge(array_keys($monthlyPurchaseMap), array_keys($monthlyReturnMap)));
    rsort($monthKeys);
    foreach ($monthKeys as $key) {
        [$gy, $gm] = array_map('intval', explode('-', $key));
        [$jy, $jm] = gregorian_to_jalali($gy, $gm, 1);
        $label = format_jalali_date($jy, $jm, 1, 'Y F');

        $purchaseAmount = $monthlyPurchaseMap[$key]['amount'] ?? 0.0;
        $returnAmount = $monthlyReturnMap[$key]['amount'] ?? 0.0;
        $purchaseQty = $monthlyPurchaseMap[$key]['quantity'] ?? 0.0;
        $returnQty = $monthlyReturnMap[$key]['quantity'] ?? 0.0;

        $monthlyReport[] = [
            'label' => $label,
            'purchase_amount' => $purchaseAmount,
            'purchase_quantity' => $purchaseQty,
            'return_amount' => $returnAmount,
            'return_quantity' => $returnQty,
            'net_amount' => $purchaseAmount - $returnAmount,
        ];
    }

    $annualPurchasesStmt = $conn->prepare('
        SELECT YEAR(pr.purchase_date) AS gregorian_year,
               SUM(pi.quantity) AS total_quantity,
               SUM(pi.quantity * pi.buy_price) AS total_amount
        FROM Purchases pr
        JOIN Purchase_Items pi ON pr.purchase_id = pi.purchase_id
        WHERE pr.supplier_id = ?
        GROUP BY YEAR(pr.purchase_date)
        ORDER BY YEAR(pr.purchase_date) DESC
        LIMIT 5
    ');
    $annualPurchasesStmt->bind_param('i', $selectedSupplierId);
    $annualPurchasesStmt->execute();
    $annualPurchasesResult = $annualPurchasesStmt->get_result();
    $annualPurchaseMap = [];
    while ($row = $annualPurchasesResult->fetch_assoc()) {
        $year = (int) $row['gregorian_year'];
        $annualPurchaseMap[$year] = [
            'quantity' => (float) $row['total_quantity'],
            'amount' => (float) $row['total_amount'],
        ];
    }
    $annualPurchasesStmt->close();

    $annualReturnsStmt = $conn->prepare('
        SELECT YEAR(r.return_date) AS gregorian_year,
               SUM(ri.quantity) AS total_quantity,
               SUM(ri.quantity * ri.return_price) AS total_amount
        FROM Purchase_Returns r
        JOIN Purchase_Return_Items ri ON r.purchase_return_id = ri.purchase_return_id
        WHERE r.supplier_id = ?
        GROUP BY YEAR(r.return_date)
        ORDER BY YEAR(r.return_date) DESC
        LIMIT 5
    ');
    $annualReturnsStmt->bind_param('i', $selectedSupplierId);
    $annualReturnsStmt->execute();
    $annualReturnsResult = $annualReturnsStmt->get_result();
    $annualReturnMap = [];
    while ($row = $annualReturnsResult->fetch_assoc()) {
        $year = (int) $row['gregorian_year'];
        $annualReturnMap[$year] = [
            'quantity' => (float) $row['total_quantity'],
            'amount' => (float) $row['total_amount'],
        ];
    }
    $annualReturnsStmt->close();

    $yearKeys = array_unique(array_merge(array_keys($annualPurchaseMap), array_keys($annualReturnMap)));
    rsort($yearKeys);
    foreach ($yearKeys as $gy) {
        $jalaliYear = gregorian_to_jalali($gy, 1, 1)[0];
        $purchaseAmount = $annualPurchaseMap[$gy]['amount'] ?? 0.0;
        $returnAmount = $annualReturnMap[$gy]['amount'] ?? 0.0;
        $purchaseQty = $annualPurchaseMap[$gy]['quantity'] ?? 0.0;
        $returnQty = $annualReturnMap[$gy]['quantity'] ?? 0.0;

        $annualReport[] = [
            'label' => $jalaliYear,
            'purchase_amount' => $purchaseAmount,
            'purchase_quantity' => $purchaseQty,
            'return_amount' => $returnAmount,
            'return_quantity' => $returnQty,
            'net_amount' => $purchaseAmount - $returnAmount,
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت تامین‌کننده‌ها - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <link rel="stylesheet" href="libs/vazirmatn.css">
    <link href="css/global.css" rel="stylesheet">
</head>
<body class="bg-gray-50">
<div class="flex h-screen overflow-hidden">
    <?php include 'sidebar.php'; ?>

    <div class="flex-1 overflow-auto">
        <header class="bg-white border-b border-gray-200 p-6 flex flex-col gap-4 md:flex-row md:items-center md:justify-between shadow-sm">
            <div>
                <h1 class="text-2xl font-semibold text-gray-800">مدیریت تامین‌کننده‌ها</h1>
                <p class="text-sm text-gray-500 mt-1">ثبت، ویرایش و تحلیل عملکرد تامین‌کنندگان</p>
            </div>
            <div class="flex items-center gap-3">
                <button onclick="openModal('createSupplierModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm">
                    <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                    تامین‌کننده جدید
                </button>
            </div>
        </header>

        <main class="p-6 space-y-6">
            <?php if (!empty($flash_messages['success']) || !empty($flash_messages['error'])): ?>
                <div class="space-y-3">
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

            <?php if (empty($suppliers)): ?>
                <div class="bg-white border border-dashed border-gray-300 rounded-xl p-10 text-center shadow-sm">
                    <i data-feather="users" class="w-12 h-12 mx-auto text-gray-400 mb-4"></i>
                    <h2 class="text-xl font-semibold text-gray-700 mb-2">هنوز تامین‌کننده‌ای ثبت نشده است</h2>
                    <p class="text-gray-500 mb-6">برای شروع مدیریت تامین‌کنندگان، تامین‌کننده جدیدی ثبت کنید.</p>
                    <button onclick="openModal('createSupplierModal')" class="px-5 py-2.5 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        ثبت اولین تامین‌کننده
                    </button>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <section class="lg:col-span-1 space-y-4">
                        <div class="bg-white border border-gray-200 rounded-xl shadow-sm">
                            <div class="border-b border-gray-200 p-4 flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-gray-800">لیست تامین‌کننده‌ها</h2>
                                <span class="text-sm text-gray-500"><?php echo count($suppliers); ?> تامین‌کننده</span>
                            </div>
                            <div class="p-4 space-y-3 max-h-[70vh] overflow-y-auto">
                                <?php foreach ($suppliers as $supplier): ?>
                                    <?php
                                        $isActive = $selectedSupplierId === (int) $supplier['supplier_id'];
                                        $cardClasses = $isActive
                                            ? 'border-blue-400 bg-blue-50 shadow-sm'
                                            : 'border-gray-200 hover:border-blue-200 hover:shadow-sm';
                                        $netAmount = $supplier['net_total_amount'];
                                        $netColor = $netAmount >= 0 ? 'text-green-600' : 'text-red-600';
                                    ?>
                                    <div class="border rounded-xl p-4 transition-all cursor-pointer <?php echo $cardClasses; ?>" onclick="window.location='suppliers.php?supplier_id=<?php echo (int) $supplier['supplier_id']; ?>'">
                                        <div class="flex items-center justify-between mb-2">
                                            <div>
                                                <h3 class="text-base font-semibold text-gray-800"><?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                                <p class="text-xs text-gray-500 mt-1">از <?php echo htmlspecialchars(convert_gregorian_to_jalali_for_display((string) $supplier['created_at']), ENT_QUOTES, 'UTF-8'); ?></p>
                                            </div>
                                            <span class="text-xs font-medium px-2 py-1 bg-blue-100 text-blue-700 rounded-full">خرید: <?php echo number_format($supplier['purchase_count']); ?></span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 text-xs text-gray-600 mb-3">
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 text-center">
                                                <p class="font-medium text-gray-500">مبلغ خرید</p>
                                                <p class="text-sm text-gray-800"><?php echo number_format($supplier['purchase_total_amount']); ?></p>
                                            </div>
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 text-center">
                                                <p class="font-medium text-gray-500">مبلغ مرجوعی</p>
                                                <p class="text-sm text-gray-800"><?php echo number_format($supplier['return_total_amount']); ?></p>
                                            </div>
                                            <div class="bg-white border border-gray-200 rounded-lg p-2 text-center">
                                                <p class="font-medium text-gray-500">خالص</p>
                                                <p class="text-sm <?php echo $netColor; ?>"><?php echo number_format($netAmount); ?></p>
                                            </div>
                                        </div>
                                        <div class="flex items-center justify-end gap-2">
                                            <button type="button" onclick="event.stopPropagation(); openEditSupplierModal(this)" class="px-3 py-1 text-xs bg-white border border-blue-200 text-blue-600 rounded-lg hover:bg-blue-50" data-supplier-id="<?php echo (int) $supplier['supplier_id']; ?>" data-supplier-name="<?php echo htmlspecialchars($supplier['name'], ENT_QUOTES, 'UTF-8'); ?>" data-supplier-phone="<?php echo htmlspecialchars((string)($supplier['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-supplier-email="<?php echo htmlspecialchars((string)($supplier['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-supplier-address="<?php echo htmlspecialchars((string)($supplier['address'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                                ویرایش
                                            </button>
                                            <form method="post" onsubmit="event.stopPropagation(); return confirm('آیا از حذف این تامین‌کننده مطمئن هستید؟');">
                                                <input type="hidden" name="action" value="delete_supplier">
                                                <input type="hidden" name="supplier_id" value="<?php echo (int) $supplier['supplier_id']; ?>">
                                                <button type="submit" class="px-3 py-1 text-xs bg-white border border-red-200 text-red-600 rounded-lg hover:bg-red-50">حذف</button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </section>

                    <section class="lg:col-span-2 space-y-6">
                        <?php if ($selectedSupplier): ?>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm text-gray-500">مجموع خریدها</span>
                                        <i data-feather="shopping-bag" class="w-5 h-5 text-blue-500"></i>
                                    </div>
                                    <p class="text-xl font-semibold text-gray-800"><?php echo number_format($selectedTotals['purchase_total_amount']); ?> تومان</p>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($selectedTotals['purchase_total_quantity']); ?> عدد در <?php echo number_format($selectedTotals['purchase_count']); ?> فاکتور</p>
                                </div>
                                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm text-gray-500">مجموع مرجوعی‌ها</span>
                                        <i data-feather="corner-up-left" class="w-5 h-5 text-amber-500"></i>
                                    </div>
                                    <p class="text-xl font-semibold text-gray-800"><?php echo number_format($selectedTotals['return_total_amount']); ?> تومان</p>
                                    <p class="text-xs text-gray-500 mt-1"><?php echo number_format($selectedTotals['return_total_quantity']); ?> عدد در <?php echo number_format($selectedTotals['return_count']); ?> فاکتور</p>
                                </div>
                                <div class="bg-white border border-gray-100 rounded-xl p-4 shadow-sm">
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-sm text-gray-500">خالص همکاری</span>
                                        <i data-feather="activity" class="w-5 h-5 text-green-500"></i>
                                    </div>
                                    <?php $netClass = $selectedTotals['net_total_amount'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>
                                    <p class="text-xl font-semibold <?php echo $netClass; ?>"><?php echo number_format($selectedTotals['net_total_amount']); ?> تومان</p>
                                    <p class="text-xs text-gray-500 mt-1">خریدها منهای مرجوعی‌ها</p>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-xl shadow-sm">
                                <div class="border-b border-gray-200 p-4 flex justify-between items-center">
                                    <h2 class="text-lg font-semibold text-gray-800">اطلاعات تماس</h2>
                                    <?php if ($selectedCreatedAt): ?>
                                        <span class="text-sm text-gray-500">تاریخ ثبت: <?php echo htmlspecialchars($selectedCreatedAt, ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-600">
                                    <div>
                                        <span class="text-gray-500">نام تامین‌کننده</span>
                                        <p class="font-medium text-gray-800 mt-1"><?php echo htmlspecialchars($selectedSupplier['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">شماره تماس</span>
                                        <p class="font-medium text-gray-800 mt-1"><?php echo $selectedSupplier['phone'] ? htmlspecialchars($selectedSupplier['phone'], ENT_QUOTES, 'UTF-8') : 'ثبت نشده'; ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-500">ایمیل</span>
                                        <p class="font-medium text-gray-800 mt-1"><?php echo $selectedSupplier['email'] ? htmlspecialchars($selectedSupplier['email'], ENT_QUOTES, 'UTF-8') : 'ثبت نشده'; ?></p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <span class="text-gray-500">آدرس</span>
                                        <p class="font-medium text-gray-800 mt-1 leading-relaxed"><?php echo $selectedSupplier['address'] ? nl2br(htmlspecialchars($selectedSupplier['address'], ENT_QUOTES, 'UTF-8')) : 'ثبت نشده'; ?></p>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                    <div class="border-b border-gray-200 p-4 flex items-center justify-between">
                                        <h3 class="text-lg font-semibold text-gray-800">آخرین فاکتورهای خرید</h3>
                                        <span class="text-xs text-gray-500">نمایش حداکثر ۱۰۰ رکورد</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="px-4 py-3 text-right">شماره فاکتور</th>
                                                    <th class="px-4 py-3 text-right">تاریخ</th>
                                                    <th class="px-4 py-3 text-right">تعداد اقلام</th>
                                                    <th class="px-4 py-3 text-right">مبلغ</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                                <?php if (!empty($purchaseList)): ?>
                                                    <?php foreach ($purchaseList as $purchase): ?>
                                                        <tr class="hover:bg-gray-50">
                                                            <td class="px-4 py-3 font-medium text-gray-800">#<?php echo $purchase['purchase_id']; ?></td>
                                                            <td class="px-4 py-3"><?php echo htmlspecialchars($purchase['purchase_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                            <td class="px-4 py-3"><?php echo number_format($purchase['total_quantity']); ?></td>
                                                            <td class="px-4 py-3 font-semibold text-gray-900"><?php echo number_format($purchase['total_amount']); ?> تومان</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">هیچ خریدی برای این تامین‌کننده ثبت نشده است.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                    <div class="border-b border-gray-200 p-4 flex items-center justify-between">
                                        <h3 class="text-lg font-semibold text-gray-800">آخرین مرجوعی‌ها</h3>
                                        <span class="text-xs text-gray-500">نمایش حداکثر ۱۰۰ رکورد</span>
                                    </div>
                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-50 text-gray-600">
                                                <tr>
                                                    <th class="px-4 py-3 text-right">شماره مرجوعی</th>
                                                    <th class="px-4 py-3 text-right">تاریخ</th>
                                                    <th class="px-4 py-3 text-right">تعداد</th>
                                                    <th class="px-4 py-3 text-right">مبلغ</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 text-gray-700">
                                                <?php if (!empty($returnsList)): ?>
                                                    <?php foreach ($returnsList as $return): ?>
                                                        <tr class="hover:bg-gray-50">
                                                            <td class="px-4 py-3 font-medium text-gray-800">#<?php echo $return['purchase_return_id']; ?></td>
                                                            <td class="px-4 py-3"><?php echo htmlspecialchars($return['return_date'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                            <td class="px-4 py-3"><?php echo number_format($return['total_quantity']); ?></td>
                                                            <td class="px-4 py-3 font-semibold text-gray-900"><?php echo number_format($return['total_amount']); ?> تومان</td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">هیچ مرجوعی برای این تامین‌کننده ثبت نشده است.</td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                <div class="border-b border-gray-200 p-4 flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-800">گزارش ماهانه ۱۲ ماه اخیر</h3>
                                    <span class="text-xs text-gray-500">مبالغ به تومان</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50 text-gray-600">
                                            <tr>
                                                <th class="px-4 py-3 text-right">ماه</th>
                                                <th class="px-4 py-3 text-right">مبلغ خرید</th>
                                                <th class="px-4 py-3 text-right">مبلغ مرجوعی</th>
                                                <th class="px-4 py-3 text-right">خالص</th>
                                                <th class="px-4 py-3 text-right">تعداد خرید</th>
                                                <th class="px-4 py-3 text-right">تعداد مرجوعی</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 text-gray-700">
                                            <?php if (!empty($monthlyReport)): ?>
                                                <?php foreach ($monthlyReport as $month): ?>
                                                    <?php $netClass = $month['net_amount'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars($month['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($month['purchase_amount']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($month['return_amount']); ?></td>
                                                        <td class="px-4 py-3 font-semibold <?php echo $netClass; ?>"><?php echo number_format($month['net_amount']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($month['purchase_quantity']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($month['return_quantity']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">داده‌ای برای نمایش وجود ندارد.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden">
                                <div class="border-b border-gray-200 p-4 flex items-center justify-between">
                                    <h3 class="text-lg font-semibold text-gray-800">گزارش سالانه</h3>
                                    <span class="text-xs text-gray-500">آخرین ۵ سال</span>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead class="bg-gray-50 text-gray-600">
                                            <tr>
                                                <th class="px-4 py-3 text-right">سال</th>
                                                <th class="px-4 py-3 text-right">مبلغ خرید</th>
                                                <th class="px-4 py-3 text-right">مبلغ مرجوعی</th>
                                                <th class="px-4 py-3 text-right">خالص</th>
                                                <th class="px-4 py-3 text-right">تعداد خرید</th>
                                                <th class="px-4 py-3 text-right">تعداد مرجوعی</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 text-gray-700">
                                            <?php if (!empty($annualReport)): ?>
                                                <?php foreach ($annualReport as $year): ?>
                                                    <?php $netClass = $year['net_amount'] >= 0 ? 'text-green-600' : 'text-red-600'; ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-4 py-3 font-medium text-gray-800"><?php echo htmlspecialchars((string) $year['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($year['purchase_amount']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($year['return_amount']); ?></td>
                                                        <td class="px-4 py-3 font-semibold <?php echo $netClass; ?>"><?php echo number_format($year['net_amount']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($year['purchase_quantity']); ?></td>
                                                        <td class="px-4 py-3"><?php echo number_format($year['return_quantity']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">داده‌ای برای نمایش وجود ندارد.</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="bg-white border border-gray-200 rounded-xl p-8 text-center text-gray-600 shadow-sm">
                                <p>تامین‌کننده انتخاب نشده است. از لیست سمت راست یک تامین‌کننده را انتخاب کنید.</p>
                            </div>
                        <?php endif; ?>
                    </section>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Create Supplier Modal -->
<div id="createSupplierModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 overflow-hidden">
        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">ثبت تامین‌کننده جدید</h2>
            <button onclick="closeModal('createSupplierModal')" class="text-gray-500 hover:text-gray-700">
                <i data-feather="x"></i>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="create_supplier">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">نام تامین‌کننده <span class="text-red-500">*</span></label>
                <input type="text" name="name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: شرکت پارچه ایرانیان">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">شماره تماس</label>
                    <input type="text" name="phone" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: 0912xxxxxxx">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ایمیل</label>
                    <input type="email" name="email" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="مثال: supplier@example.com">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">آدرس</label>
                <textarea name="address" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="نشانی دقیق تامین‌کننده را وارد کنید..."></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeModal('createSupplierModal')" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">انصراف</button>
                <button type="submit" class="px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">ثبت تامین‌کننده</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Supplier Modal -->
<div id="editSupplierModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl mx-4 overflow-hidden">
        <div class="p-6 border-b border-gray-200 flex justify-between items-center">
            <h2 class="text-lg font-semibold text-gray-800">ویرایش اطلاعات تامین‌کننده</h2>
            <button onclick="closeModal('editSupplierModal')" class="text-gray-500 hover:text-gray-700">
                <i data-feather="x"></i>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_supplier">
            <input type="hidden" name="supplier_id" id="editSupplierId">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">نام تامین‌کننده <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editSupplierName" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">شماره تماس</label>
                    <input type="text" name="phone" id="editSupplierPhone" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">ایمیل</label>
                    <input type="email" name="email" id="editSupplierEmail" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">آدرس</label>
                <textarea name="address" rows="3" id="editSupplierAddress" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500"></textarea>
            </div>
            <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                <button type="button" onclick="closeModal('editSupplierModal')" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">انصراف</button>
                <button type="submit" class="px-5 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">ذخیره تغییرات</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function openEditSupplierModal(button) {
        const supplierId = button.getAttribute('data-supplier-id');
        const name = button.getAttribute('data-supplier-name') || '';
        const phone = button.getAttribute('data-supplier-phone') || '';
        const email = button.getAttribute('data-supplier-email') || '';
        const address = button.getAttribute('data-supplier-address') || '';

        document.getElementById('editSupplierId').value = supplierId;
        document.getElementById('editSupplierName').value = name;
        document.getElementById('editSupplierPhone').value = phone;
        document.getElementById('editSupplierEmail').value = email;
        document.getElementById('editSupplierAddress').value = address;

        openModal('editSupplierModal');
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal('createSupplierModal');
            closeModal('editSupplierModal');
        }
    });

    feather.replace();
</script>
</body>
</html>
