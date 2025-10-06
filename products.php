<?php
require_once __DIR__ . '/env/bootstrap.php';

function sanitize_text_field(string $value, string $empty_message): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        throw new InvalidArgumentException($empty_message);
    }

    return $trimmed;
}

function validate_price(mixed $value): float
{
    if (!is_numeric($value)) {
        throw new InvalidArgumentException('قیمت وارد شده نامعتبر است.');
    }

    $price = (float) $value;
    if ($price <= 0) {
        throw new InvalidArgumentException('قیمت باید بزرگ‌تر از صفر باشد.');
    }

    return $price;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_product'])) {
        try {
            $model_name = sanitize_text_field((string) ($_POST['model_name'] ?? ''), 'نام مدل نمی‌تواند خالی باشد.');
            $category = sanitize_text_field((string) ($_POST['category'] ?? ''), 'دسته‌بندی نمی‌تواند خالی باشد.');

            $stmt = $conn->prepare('INSERT INTO Products (model_name, category) VALUES (?, ?)');
            $stmt->bind_param('ss', $model_name, $category);
            $stmt->execute();

            redirect_with_message('products.php', 'success', 'محصول جدید با موفقیت ایجاد شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }

    if (isset($_POST['create_variant'])) {
        try {
            $product_id = validate_int($_POST['product_id'] ?? null, 1);
            $color = sanitize_text_field((string) ($_POST['color'] ?? ''), 'رنگ نمی‌تواند خالی باشد.');
            $size = sanitize_text_field((string) ($_POST['size'] ?? ''), 'سایز نمی‌تواند خالی باشد.');
            $price = validate_price($_POST['price'] ?? null);
            $stock = validate_int($_POST['stock'] ?? null, 0);

            $stmt = $conn->prepare('INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES (?, ?, ?, ?, ?)');
            $stmt->bind_param('issdi', $product_id, $color, $size, $price, $stock);
            $stmt->execute();

            redirect_with_message('products.php', 'success', 'تنوع جدید با موفقیت اضافه شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }

    if (isset($_POST['edit_product'])) {
        try {
            $product_id = validate_int($_POST['product_id'] ?? null, 1);
            $model_name = sanitize_text_field((string) ($_POST['model_name'] ?? ''), 'نام مدل نمی‌تواند خالی باشد.');
            $category = sanitize_text_field((string) ($_POST['category'] ?? ''), 'دسته‌بندی نمی‌تواند خالی باشد.');

            $stmt = $conn->prepare('UPDATE Products SET model_name = ?, category = ? WHERE product_id = ?');
            $stmt->bind_param('ssi', $model_name, $category, $product_id);
            $stmt->execute();

            redirect_with_message('products.php', 'success', 'محصول با موفقیت به‌روزرسانی شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }

    if (isset($_POST['edit_variant'])) {
        try {
            $variant_id = validate_int($_POST['variant_id'] ?? null, 1);
            $color = sanitize_text_field((string) ($_POST['color'] ?? ''), 'رنگ نمی‌تواند خالی باشد.');
            $size = sanitize_text_field((string) ($_POST['size'] ?? ''), 'سایز نمی‌تواند خالی باشد.');
            $price = validate_price($_POST['price'] ?? null);
            $stock = validate_int($_POST['stock'] ?? null, 0);

            $stmt = $conn->prepare('UPDATE Product_Variants SET color = ?, size = ?, price = ?, stock = ? WHERE variant_id = ?');
            $stmt->bind_param('ssdii', $color, $size, $price, $stock, $variant_id);
            $stmt->execute();

            redirect_with_message('products.php', 'success', 'تنوع محصول با موفقیت به‌روزرسانی شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }

    if (isset($_POST['recharge_variant'])) {
        try {
            $variant_id = validate_int($_POST['variant_id'] ?? null, 1);
            $additional_stock = validate_int($_POST['additional_stock'] ?? null, 1);

            $stmt = $conn->prepare('UPDATE Product_Variants SET stock = stock + ? WHERE variant_id = ?');
            $stmt->bind_param('ii', $additional_stock, $variant_id);
            $stmt->execute();

            redirect_with_message('products.php', 'success', 'موجودی تنوع با موفقیت شارژ شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['delete_product'])) {
        $transactionStarted = false;

        try {
            $product_id = validate_int($_GET['delete_product'] ?? null, 1);

            $checkStmt = $conn->prepare('SELECT COUNT(*) as count FROM Sale_Items si JOIN Product_Variants pv ON si.variant_id = pv.variant_id WHERE pv.product_id = ?');
            $checkStmt->bind_param('i', $product_id);
            $checkStmt->execute();
            $hasSales = (int) $checkStmt->get_result()->fetch_assoc()['count'];

            if ($hasSales > 0) {
                throw new RuntimeException('نمی‌توان محصول دارای فروش ثبت شده را حذف کرد.');
            }

            $conn->begin_transaction();
            $transactionStarted = true;

            $deleteVariants = $conn->prepare('DELETE FROM Product_Variants WHERE product_id = ?');
            $deleteVariants->bind_param('i', $product_id);
            $deleteVariants->execute();

            $deleteProduct = $conn->prepare('DELETE FROM Products WHERE product_id = ?');
            $deleteProduct->bind_param('i', $product_id);
            $deleteProduct->execute();

            $conn->commit();
            redirect_with_message('products.php', 'success', 'محصول با موفقیت حذف شد.');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                $conn->rollback();
            }

            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }

    if (isset($_GET['delete_variant'])) {
        try {
            $variant_id = validate_int($_GET['delete_variant'] ?? null, 1);

            $checkStmt = $conn->prepare('SELECT COUNT(*) as count FROM Sale_Items WHERE variant_id = ?');
            $checkStmt->bind_param('i', $variant_id);
            $checkStmt->execute();
            $hasSales = (int) $checkStmt->get_result()->fetch_assoc()['count'];

            if ($hasSales > 0) {
                throw new RuntimeException('نمی‌توان تنوعی که فروش داشته است را حذف کرد.');
            }

            $deleteStmt = $conn->prepare('DELETE FROM Product_Variants WHERE variant_id = ?');
            $deleteStmt->bind_param('i', $variant_id);
            $deleteStmt->execute();

            redirect_with_message('products.php', 'success', 'تنوع محصول حذف شد.');
        } catch (Throwable $e) {
            redirect_with_message('products.php', 'error', normalize_error_message($e));
        }
    }
}

$flash_messages = get_flash_messages();

if (isset($_GET['ajax'])) {
    // Build search query
    $searchConditions = [];
    $searchParams = [];
    $searchTypes = '';

    if (!empty($_GET['search_name'])) {
        $searchConditions[] = 'p.model_name LIKE ?';
        $searchParams[] = '%' . $_GET['search_name'] . '%';
        $searchTypes .= 's';
    }

    if (!empty($_GET['search_category'])) {
        $searchConditions[] = 'p.category = ?';
        $searchParams[] = $_GET['search_category'];
        $searchTypes .= 's';
    }

    if (!empty($_GET['search_color'])) {
        $searchConditions[] = 'pv.color LIKE ?';
        $searchParams[] = '%' . $_GET['search_color'] . '%';
        $searchTypes .= 's';
    }

    if (!empty($_GET['search_size'])) {
        $searchConditions[] = 'pv.size = ?';
        $searchParams[] = $_GET['search_size'];
        $searchTypes .= 's';
    }

    $whereClause = !empty($searchConditions) ? 'WHERE ' . implode(' AND ', $searchConditions) : '';

    $query = "SELECT DISTINCT p.* FROM Products p LEFT JOIN Product_Variants pv ON p.product_id = pv.product_id $whereClause ORDER BY p.product_id DESC";

    $stmt = $conn->prepare($query);
    if (!empty($searchParams)) {
        $stmt->bind_param($searchTypes, ...$searchParams);
    }
    $stmt->execute();
    $productsResult = $stmt->get_result();

    $variantStmt = $conn->prepare('SELECT * FROM Product_Variants WHERE product_id = ? ORDER BY variant_id DESC');

    ob_start();
    if ($productsResult->num_rows > 0): ?>
        <table class="w-full text-sm bg-white rounded-lg shadow-sm border border-gray-200">
            <thead>
                <tr class="bg-gray-50">
                    <th class="px-4 py-3 text-right font-medium text-gray-700">نام مدل</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">دسته‌بندی</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">عملیات</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-700">تنوع‌ها</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($product = $productsResult->fetch_assoc()): ?>
                    <?php
                    $product_id = (int) $product['product_id'];
                    $model_name = htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8');
                    $category = htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $editProductCallback = htmlspecialchars(sprintf('openEditModal("product", %d, %s, %s)', $product_id, json_encode($product['model_name']), json_encode($product['category'])), ENT_QUOTES, 'UTF-8');
                    $deleteProductCallback = htmlspecialchars(sprintf('deleteProduct(%d)', $product_id), ENT_QUOTES, 'UTF-8');

                    $variantStmt->bind_param('i', $product_id);
                    $variantStmt->execute();
                    $variantsResult = $variantStmt->get_result();
                    ?>
                    <tr class="border-b border-gray-100">
                        <td class="px-4 py-3 text-gray-800"><?php echo $model_name; ?></td>
                        <td class="px-4 py-3 text-gray-800"><?php echo $category !== '' ? $category : '—'; ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center space-x-2">
                                <button onclick="<?php echo $editProductCallback; ?>" class="p-1.5 bg-yellow-50 text-yellow-600 rounded hover:bg-yellow-100" title="ویرایش محصول">
                                    <i data-feather="edit-2" class="w-3.5 h-3.5"></i>
                                </button>
                                <button onclick="<?php echo $deleteProductCallback; ?>" class="p-1.5 bg-red-50 text-red-600 rounded hover:bg-red-100" title="حذف محصول">
                                    <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
                                </button>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <button onclick="toggleVariants(<?php echo $product_id; ?>)" class="p-1.5 bg-blue-50 text-blue-600 rounded hover:bg-blue-100" title="نمایش تنوع‌ها">
                                <i data-feather="chevron-down" class="w-3.5 h-3.5"></i>
                            </button>
                        </td>
                    </tr>
                    <tr id="variants-<?php echo $product_id; ?>" class="hidden bg-gray-50">
                        <td colspan="4" class="px-4 py-3">
                            <?php if ($variantsResult->num_rows > 0): ?>
                                <table class="w-full text-sm border border-gray-200 rounded">
                                    <thead class="bg-gray-100">
                                        <tr>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">رنگ</th>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">سایز</th>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">قیمت</th>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">موجودی</th>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">وضعیت</th>
                                            <th class="px-3 py-2 text-right font-medium text-gray-700">عملیات</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php while ($variant = $variantsResult->fetch_assoc()): ?>
                                            <?php
                                            $variant_id = (int) $variant['variant_id'];
                                            $color = htmlspecialchars((string) $variant['color'], ENT_QUOTES, 'UTF-8');
                                            $size = htmlspecialchars((string) $variant['size'], ENT_QUOTES, 'UTF-8');
                                            $price_display = number_format((float) $variant['price'], 0);
                                            $stock = (int) $variant['stock'];
                                            if ($stock > 0) {
                                                $status_text = 'موجود';
                                                $status_color = 'bg-green-100 text-green-800';
                                            } else {
                                                $status_text = 'ناموجود';
                                                $status_color = 'bg-red-100 text-red-800';
                                            }

                                            $editVariantCallback = htmlspecialchars(sprintf(
                                                'openEditModal("variant", %d, %s, %s, %s, %d)',
                                                $variant_id,
                                                json_encode($variant['color']),
                                                json_encode($variant['size']),
                                                json_encode((float) $variant['price']),
                                                $stock
                                            ), ENT_QUOTES, 'UTF-8');
                                            $deleteVariantCallback = htmlspecialchars(sprintf('deleteVariant(%d)', $variant_id), ENT_QUOTES, 'UTF-8');
                                            ?>
                                            <tr>
                                                <td class="px-3 py-2 text-gray-800"><?php echo $color; ?></td>
                                                <td class="px-3 py-2 text-gray-800"><?php echo $size; ?></td>
                                                <td class="px-3 py-2 text-gray-800"><?php echo $price_display; ?> تومان</td>
                                                <td class="px-3 py-2 text-gray-800"><?php echo $stock; ?></td>
                                                <td class="px-3 py-2">
                                                    <span class="px-2 py-1 text-xs rounded-full <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                                </td>
                                                <td class="px-3 py-2">
                                                    <div class="flex items-center space-x-2">
                                                        <button onclick="openRechargeModal(<?php echo $variant_id; ?>, '<?php echo $color; ?>', '<?php echo $size; ?>')" class="p-1 bg-green-50 text-green-600 rounded hover:bg-green-100" title="شارژ موجودی">
                                                            <i data-feather="plus" class="w-3 h-3"></i>
                                                        </button>
                                                        <button onclick="<?php echo $editVariantCallback; ?>" class="p-1 bg-blue-50 text-blue-600 rounded hover:bg-blue-100" title="ویرایش">
                                                            <i data-feather="edit-3" class="w-3 h-3"></i>
                                                        </button>
                                                        <button onclick="<?php echo $deleteVariantCallback; ?>" class="p-1 bg-red-50 text-red-600 rounded hover:bg-red-100" title="حذف">
                                                            <i data-feather="trash-2" class="w-3 h-3"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <p class="text-gray-500">هیچ تنوعی تعریف نشده</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="text-center py-16 text-gray-500">
            <i data-feather="package" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
            <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ محصولی یافت نشد</h3>
            <p class="mb-6">محصولات از طریق خرید اضافه می‌شوند</p>
        </div>
    <?php endif;
    $html = ob_get_clean();
    echo json_encode(['html' => $html]);
    exit;
}

$total_products = $conn->query("SELECT COUNT(*) as count FROM Products")->fetch_assoc()['count'];
$total_variants = $conn->query("SELECT COUNT(*) as count FROM Product_Variants")->fetch_assoc()['count'];
$total_stock = $conn->query("SELECT SUM(stock) as total FROM Product_Variants")->fetch_assoc()['total'] ?: 0;
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM Product_Variants WHERE stock <= 5")->fetch_assoc()['count'];
$zero_stock_count = $conn->query("SELECT COUNT(DISTINCT p.product_id) as count FROM Products p LEFT JOIN Product_Variants pv ON p.product_id = pv.product_id WHERE pv.stock = 0 OR pv.variant_id IS NULL")->fetch_assoc()['count'];
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت محصولات - SuitStore Manager Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100..900&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
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

        .modal-backdrop {
            backdrop-filter: blur(4px);
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

        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }

        .variant-row:hover {
            background-color: #f9fafb;
        }

        .low-stock {
            background-color: #fef2f2;
            border-left: 4px solid #ef4444;
        }

        .out-of-stock {
            background-color: #fef2f2;
            border-left: 4px solid #dc2626;
        }
    </style>
</head>
<body class="bg-gray-50">
    <?php if (!empty($flash_messages['success']) || !empty($flash_messages['error'])): ?>
        <div class="fixed top-4 left-4 right-4 md:right-auto md:max-w-sm space-y-3 z-50">
            <?php foreach ($flash_messages['success'] as $message): ?>
                <div class="bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slide-up flex items-center">
                    <i data-feather="check-circle" class="ml-2"></i>
                    <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($flash_messages['error'] as $message): ?>
                <div class="bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg animate-slide-up flex items-center">
                    <i data-feather="alert-circle" class="ml-2"></i>
                    <span><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="flex h-screen overflow-hidden">
    <?php include 'sidebar.php'; ?>


        <!-- Main Content -->
        <div class="flex-1 overflow-auto custom-scrollbar">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center header-shadow">
                <h2 class="text-xl font-semibold text-gray-800">مدیریت محصولات</h2>
                <div class="flex items-center space-x-4">
                    <a href="purchases.php" class="flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i data-feather="shopping-bag" class="ml-2"></i>
                        خریدها
                    </a>
                </div>
            </header>

            <!-- Products Content -->
            <main class="p-6">
                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">کل محصولات</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $total_products; ?></p>
                            </div>
                            <div class="p-3 bg-blue-50 rounded-lg">
                                <i data-feather="package" class="w-6 h-6 text-blue-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">کل تنوع‌ها</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $total_variants; ?></p>
                            </div>
                            <div class="p-3 bg-green-50 rounded-lg">
                                <i data-feather="layers" class="w-6 h-6 text-green-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">کل موجودی</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($total_stock); ?></p>
                            </div>
                            <div class="p-3 bg-yellow-50 rounded-lg">
                                <i data-feather="archive" class="w-6 h-6 text-yellow-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">کم‌موجود</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $low_stock_count; ?></p>
                            </div>
                            <div class="p-3 bg-red-50 rounded-lg">
                                <i data-feather="alert-triangle" class="w-6 h-6 text-red-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">تمام شده</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $zero_stock_count; ?></p>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <i data-feather="x-circle" class="w-6 h-6 text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Search Form -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">جستجو در محصولات</h3>
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">نام محصول</label>
                            <input type="text" id="search_name" value="<?php echo htmlspecialchars($_GET['search_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="نام مدل محصول..." class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">دسته‌بندی</label>
                            <select id="search_category" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="">همه دسته‌بندی‌ها</option>
                                <option value="کت و شلوار" <?php echo ($_GET['search_category'] ?? '') === 'کت و شلوار' ? 'selected' : ''; ?>>کت و شلوار</option>
                                <option value="کت تک" <?php echo ($_GET['search_category'] ?? '') === 'کت تک' ? 'selected' : ''; ?>>کت تک</option>
                                <option value="شلوار" <?php echo ($_GET['search_category'] ?? '') === 'شلوار' ? 'selected' : ''; ?>>شلوار</option>
                                <option value="کراوات" <?php echo ($_GET['search_category'] ?? '') === 'کراوات' ? 'selected' : ''; ?>>کراوات</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رنگ تنوع</label>
                            <input type="text" id="search_color" value="<?php echo htmlspecialchars($_GET['search_color'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="رنگ..." class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">سایز تنوع</label>
                            <select id="search_size" class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="">همه سایزها</option>
                                <option value="S" <?php echo ($_GET['search_size'] ?? '') === 'S' ? 'selected' : ''; ?>>S</option>
                                <option value="M" <?php echo ($_GET['search_size'] ?? '') === 'M' ? 'selected' : ''; ?>>M</option>
                                <option value="L" <?php echo ($_GET['search_size'] ?? '') === 'L' ? 'selected' : ''; ?>>L</option>
                                <option value="XL" <?php echo ($_GET['search_size'] ?? '') === 'XL' ? 'selected' : ''; ?>>XL</option>
                                <option value="XXL" <?php echo ($_GET['search_size'] ?? '') === 'XXL' ? 'selected' : ''; ?>>XXL</option>
                            </select>
                        </div>
                        <div class=" flex ">
                            <a href="products.php" class="px-6 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors flex items-center">
                                <i data-feather="x" class="ml-2"></i>
                                پاک کردن جستجو
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Products List -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">لیست محصولات</h3>
                    </div>
                    <div class="p-6" id="products-list">
                        <?php
                        // Build search query
                        $searchConditions = [];
                        $searchParams = [];
                        $searchTypes = '';

                        if (!empty($_GET['search_name'])) {
                            $searchConditions[] = 'p.model_name LIKE ?';
                            $searchParams[] = '%' . $_GET['search_name'] . '%';
                            $searchTypes .= 's';
                        }

                        if (!empty($_GET['search_category'])) {
                            $searchConditions[] = 'p.category = ?';
                            $searchParams[] = $_GET['search_category'];
                            $searchTypes .= 's';
                        }

                        if (!empty($_GET['search_color'])) {
                            $searchConditions[] = 'pv.color LIKE ?';
                            $searchParams[] = '%' . $_GET['search_color'] . '%';
                            $searchTypes .= 's';
                        }

                        if (!empty($_GET['search_size'])) {
                            $searchConditions[] = 'pv.size = ?';
                            $searchParams[] = $_GET['search_size'];
                            $searchTypes .= 's';
                        }

                        $whereClause = !empty($searchConditions) ? 'WHERE ' . implode(' AND ', $searchConditions) : '';

                        $query = "SELECT DISTINCT p.* FROM Products p LEFT JOIN Product_Variants pv ON p.product_id = pv.product_id $whereClause ORDER BY p.product_id DESC";

                        $stmt = $conn->prepare($query);
                        if (!empty($searchParams)) {
                            $stmt->bind_param($searchTypes, ...$searchParams);
                        }
                        $stmt->execute();
                        $productsResult = $stmt->get_result();

                        $variantStmt = $conn->prepare('SELECT * FROM Product_Variants WHERE product_id = ? ORDER BY variant_id DESC');
                        ?>

                        <?php if ($productsResult->num_rows > 0): ?>
                            <table class="w-full text-sm bg-white rounded-lg shadow-sm border border-gray-200">
                                <thead>
                                    <tr class="bg-gray-50">
                                        <th class="px-4 py-3 text-right font-medium text-gray-700">نام مدل</th>
                                        <th class="px-4 py-3 text-right font-medium text-gray-700">دسته‌بندی</th>
                                        <th class="px-4 py-3 text-right font-medium text-gray-700">عملیات</th>
                                        <th class="px-4 py-3 text-right font-medium text-gray-700">تنوع‌ها</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($product = $productsResult->fetch_assoc()): ?>
                                        <?php
                                        $product_id = (int) $product['product_id'];
                                        $model_name = htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8');
                                        $category = htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES, 'UTF-8');
                                        $editProductCallback = htmlspecialchars(sprintf('openEditModal("product", %d, %s, %s)', $product_id, json_encode($product['model_name']), json_encode($product['category'])), ENT_QUOTES, 'UTF-8');
                                        $deleteProductCallback = htmlspecialchars(sprintf('deleteProduct(%d)', $product_id), ENT_QUOTES, 'UTF-8');

                                        $variantStmt->bind_param('i', $product_id);
                                        $variantStmt->execute();
                                        $variantsResult = $variantStmt->get_result();
                                        ?>
                                        <tr class="border-b border-gray-100">
                                            <td class="px-4 py-3 text-gray-800"><?php echo $model_name; ?></td>
                                            <td class="px-4 py-3 text-gray-800"><?php echo $category !== '' ? $category : '—'; ?></td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center space-x-2">
                                                    <button onclick="<?php echo $editProductCallback; ?>" class="p-1.5 bg-yellow-50 text-yellow-600 rounded hover:bg-yellow-100" title="ویرایش محصول">
                                                        <i data-feather="edit-2" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                    <button onclick="<?php echo $deleteProductCallback; ?>" class="p-1.5 bg-red-50 text-red-600 rounded hover:bg-red-100" title="حذف محصول">
                                                        <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
                                                    </button>
                                                </div>
                                            </td>
                                            <td class="px-4 py-3">
                                                <button onclick="toggleVariants(<?php echo $product_id; ?>)" class="p-1.5 bg-blue-50 text-blue-600 rounded hover:bg-blue-100" title="نمایش تنوع‌ها">
                                                    <i data-feather="chevron-down" class="w-3.5 h-3.5"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <tr id="variants-<?php echo $product_id; ?>" class="hidden bg-gray-50">
                                            <td colspan="4" class="px-4 py-3">
                                                <?php if ($variantsResult->num_rows > 0): ?>
                                                    <table class="w-full text-sm border border-gray-200 rounded">
                                                        <thead class="bg-gray-100">
                                                            <tr>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">رنگ</th>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">سایز</th>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">قیمت</th>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">موجودی</th>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">وضعیت</th>
                                                                <th class="px-3 py-2 text-right font-medium text-gray-700">عملیات</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php while ($variant = $variantsResult->fetch_assoc()): ?>
                                                                <?php
                                                                $variant_id = (int) $variant['variant_id'];
                                                                $color = htmlspecialchars((string) $variant['color'], ENT_QUOTES, 'UTF-8');
                                                                $size = htmlspecialchars((string) $variant['size'], ENT_QUOTES, 'UTF-8');
                                                                $price_display = number_format((float) $variant['price'], 0);
                                                                $stock = (int) $variant['stock'];
                                                                if ($stock > 0) {
                                                                    $status_text = 'موجود';
                                                                    $status_color = 'bg-green-100 text-green-800';
                                                                } else {
                                                                    $status_text = 'ناموجود';
                                                                    $status_color = 'bg-red-100 text-red-800';
                                                                }

                                                                $editVariantCallback = htmlspecialchars(sprintf(
                                                                    'openEditModal("variant", %d, %s, %s, %s, %d)',
                                                                    $variant_id,
                                                                    json_encode($variant['color']),
                                                                    json_encode($variant['size']),
                                                                    json_encode((float) $variant['price']),
                                                                    $stock
                                                                ), ENT_QUOTES, 'UTF-8');
                                                                $deleteVariantCallback = htmlspecialchars(sprintf('deleteVariant(%d)', $variant_id), ENT_QUOTES, 'UTF-8');
                                                                ?>
                                                                <tr>
                                                                    <td class="px-3 py-2 text-gray-800"><?php echo $color; ?></td>
                                                                    <td class="px-3 py-2 text-gray-800"><?php echo $size; ?></td>
                                                                    <td class="px-3 py-2 text-gray-800"><?php echo $price_display; ?> تومان</td>
                                                                    <td class="px-3 py-2 text-gray-800"><?php echo $stock; ?></td>
                                                                    <td class="px-3 py-2">
                                                                        <span class="px-2 py-1 text-xs rounded-full <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                                                    </td>
                                                                    <td class="px-3 py-2">
                                                                        <div class="flex items-center space-x-2">
                                                                            <button onclick="openRechargeModal(<?php echo $variant_id; ?>, '<?php echo $color; ?>', '<?php echo $size; ?>')" class="p-1 bg-green-50 text-green-600 rounded hover:bg-green-100" title="شارژ موجودی">
                                                                                <i data-feather="plus" class="w-3 h-3"></i>
                                                                            </button>
                                                                            <button onclick="<?php echo $editVariantCallback; ?>" class="p-1 bg-blue-50 text-blue-600 rounded hover:bg-blue-100" title="ویرایش">
                                                                                <i data-feather="edit-3" class="w-3 h-3"></i>
                                                                            </button>
                                                                            <button onclick="<?php echo $deleteVariantCallback; ?>" class="p-1 bg-red-50 text-red-600 rounded hover:bg-red-100" title="حذف">
                                                                                <i data-feather="trash-2" class="w-3 h-3"></i>
                                                                            </button>
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                            <?php endwhile; ?>
                                                        </tbody>
                                                    </table>
                                                <?php else: ?>
                                                    <p class="text-gray-500">هیچ تنوعی تعریف نشده</p>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="text-center py-16 text-gray-500">
                                <i data-feather="package" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ محصولی یافت نشد</h3>
                                <p class="mb-6">محصولات از طریق خرید اضافه می‌شوند</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>





    <!-- Edit Product Modal -->
    <div id="editProductModal" class="fixed inset-0 modal-backdrop flex items-center justify-center z-50 hidden animate-fade-in">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 animate-slide-up">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">ویرایش محصول</h3>
                    <button onclick="closeModal('editProductModal')" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-feather="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">نام مدل</label>
                            <input type="text" name="model_name" id="edit_model_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">دسته‌بندی</label>
                            <select name="category" id="edit_category" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="کت و شلوار">کت و شلوار</option>
                                <option value="کت تک">کت تک</option>
                                <option value="شلوار">شلوار</option>
                                <option value="کراوات">کراوات</option>
                            </select>
                        </div>
                        <div class="flex space-x-3 pt-4">
                            <button type="button" onclick="closeModal('editProductModal')" class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                انصراف
                            </button>
                            <button type="submit" name="edit_product" class="flex-1 px-4 py-2.5 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors shadow-sm hover:shadow-md">
                                ویرایش محصول
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Variant Modal -->
    <div id="editVariantModal" class="fixed inset-0 modal-backdrop flex items-center justify-center z-50 hidden animate-fade-in">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 animate-slide-up">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">ویرایش تنوع محصول</h3>
                    <button onclick="closeModal('editVariantModal')" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-feather="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="variant_id" id="edit_variant_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رنگ</label>
                            <input type="text" name="color" id="edit_color" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">سایز</label>
                            <select name="size" id="edit_size" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">قیمت (تومان)</label>
                            <input type="number" name="price" id="edit_price" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" min="0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">موجودی</label>
                            <input type="number" name="stock" id="edit_stock" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" min="0">
                        </div>
                        <div class="flex space-x-3 pt-4">
                            <button type="button" onclick="closeModal('editVariantModal')" class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                انصراف
                            </button>
                            <button type="submit" name="edit_variant" class="flex-1 px-4 py-2.5 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-colors shadow-sm hover:shadow-md">
                                ویرایش تنوع
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Recharge Variant Modal -->
    <div id="rechargeVariantModal" class="fixed inset-0 modal-backdrop flex items-center justify-center z-50 hidden animate-fade-in">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 animate-slide-up">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">شارژ موجودی تنوع</h3>
                    <button onclick="closeModal('rechargeVariantModal')" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-feather="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="variant_id" id="recharge_variant_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">تنوع</label>
                            <p id="recharge_variant_info" class="text-sm text-gray-600 bg-gray-50 p-3 rounded-lg"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">مقدار اضافه شده به موجودی</label>
                            <input type="number" name="additional_stock" id="recharge_additional_stock" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" min="1">
                        </div>
                        <div class="flex space-x-3 pt-4">
                            <button type="button" onclick="closeModal('rechargeVariantModal')" class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                انصراف
                            </button>
                            <button type="submit" name="recharge_variant" class="flex-1 px-4 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors shadow-sm hover:shadow-md">
                                شارژ موجودی
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        // Auto-hide messages after 5 seconds
        setTimeout(() => {
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            if (successMessage) successMessage.style.display = 'none';
            if (errorMessage) errorMessage.style.display = 'none';
        }, 5000);

        function openModal(modalId, productId = null) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.style.overflow = 'hidden'; // Prevent background scrolling
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.style.overflow = 'auto'; // Restore scrolling
        }

        function openEditModal(type, id, ...args) {
            if (type === 'product') {
                document.getElementById('edit_product_id').value = id;
                document.getElementById('edit_model_name').value = args[0];
                document.getElementById('edit_category').value = args[1];
                document.getElementById('editProductModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            } else if (type === 'variant') {
                document.getElementById('edit_variant_id').value = id;
                document.getElementById('edit_color').value = args[0];
                document.getElementById('edit_size').value = args[1];
                document.getElementById('edit_price').value = args[2];
                document.getElementById('edit_stock').value = args[3];
                document.getElementById('editVariantModal').classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }

        function openRechargeModal(variantId, color, size) {
            document.getElementById('recharge_variant_id').value = variantId;
            document.getElementById('recharge_variant_info').textContent = `رنگ: ${color} - سایز: ${size}`;
            document.getElementById('recharge_additional_stock').value = '';
            document.getElementById('rechargeVariantModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function deleteProduct(productId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این محصول را حذف کنید؟\n\nتوجه: تمام تنوع‌های این محصول نیز حذف خواهند شد.')) {
                window.location.href = `?delete_product=${productId}`;
            }
        }

        function deleteVariant(variantId) {
            if (confirm('آیا مطمئن هستید که می‌خواهید این تنوع محصول را حذف کنید؟')) {
                window.location.href = `?delete_variant=${variantId}`;
            }
        }

        function toggleVariants(productId) {
            const row = document.getElementById(`variants-${productId}`);
            const icon = event.target.closest('button').querySelector('i');
            if (row.classList.contains('hidden')) {
                row.classList.remove('hidden');
                icon.classList.remove('feather-chevron-down');
                icon.classList.add('feather-chevron-up');
            } else {
                row.classList.add('hidden');
                icon.classList.remove('feather-chevron-up');
                icon.classList.add('feather-chevron-down');
            }
        }

        function performSearch() {
            const search_name = document.getElementById('search_name').value;
            const search_category = document.getElementById('search_category').value;
            const search_color = document.getElementById('search_color').value;
            const search_size = document.getElementById('search_size').value;

            const params = new URLSearchParams();
            if (search_name) params.append('search_name', search_name);
            if (search_category) params.append('search_category', search_category);
            if (search_color) params.append('search_color', search_color);
            if (search_size) params.append('search_size', search_size);
            params.append('ajax', '1');

            fetch('products.php?' + params.toString())
                .then(response => response.json())
                .then(data => {
                    document.getElementById('products-list').innerHTML = data.html;
                    feather.replace(); // Reinitialize feather icons
                })
                .catch(error => console.error('Error:', error));
        }

        // Add event listeners for search inputs
        document.getElementById('search_name').addEventListener('input', performSearch);
        document.getElementById('search_category').addEventListener('change', performSearch);
        document.getElementById('search_color').addEventListener('input', performSearch);
        document.getElementById('search_size').addEventListener('change', performSearch);

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modals = ['productModal', 'editProductModal', 'editVariantModal', 'rechargeVariantModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target === modal) {
                    closeModal(modalId);
                }
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = ['productModal', 'editProductModal', 'editVariantModal', 'rechargeVariantModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (!modal.classList.contains('hidden')) {
                        closeModal(modalId);
                    }
                });
            }
        });
    </script>
</body>
</html>
