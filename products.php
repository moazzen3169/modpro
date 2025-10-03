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

$total_products = $conn->query("SELECT COUNT(*) as count FROM Products")->fetch_assoc()['count'];
$total_variants = $conn->query("SELECT COUNT(*) as count FROM Product_Variants")->fetch_assoc()['count'];
$total_stock = $conn->query("SELECT SUM(stock) as total FROM Product_Variants")->fetch_assoc()['total'] ?: 0;
$low_stock_count = $conn->query("SELECT COUNT(*) as count FROM Product_Variants WHERE stock <= 5")->fetch_assoc()['count'];
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
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-l border-gray-200 flex flex-col sidebar-shadow">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800">SuitStore Pro</h1>
            </div>
            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li><a href="index.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                        <i data-feather="home" class="ml-2"></i>داشبورد</a></li>
                    <li><a href="products.php" class="flex items-center px-4 py-2 bg-blue-50 text-blue-700 rounded-lg transition-colors">
                        <i data-feather="package" class="ml-2"></i>محصولات</a></li>
                    <li><a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                        <i data-feather="shopping-cart" class="ml-2"></i>فروش‌ها</a></li>
                    <li><a href="purchases.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                        <i data-feather="shopping-bag" class="ml-2"></i>خریدها</a></li>
                    <li><a href="returns.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                        <i data-feather="refresh-ccw" class="ml-2"></i>مرجوعی‌ها</a></li>
                </ul>
            </nav>
            <div class="p-4 border-t border-gray-200">
                <a href="logout.php" class="flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition">
                    <i data-feather="log-out" class="ml-2 w-4 h-4"></i>
                    خروج از حساب
                </a>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto custom-scrollbar">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center header-shadow">
                <h2 class="text-xl font-semibold text-gray-800">مدیریت محصولات</h2>
                <div class="flex items-center space-x-4">
                    <button onclick="openModal('productModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i data-feather="plus" class="ml-2"></i>
                        محصول جدید
                    </button>
                </div>
            </header>

            <!-- Products Content -->
            <main class="p-6">
                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
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
                </div>

                <!-- Products List -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">لیست محصولات</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php
                        $productsResult = $conn->query("SELECT * FROM Products ORDER BY product_id DESC");
                        $variantStmt = $conn->prepare('SELECT * FROM Product_Variants WHERE product_id = ? ORDER BY variant_id DESC');
                        ?>

                        <?php if ($productsResult->num_rows > 0): ?>
                            <?php while ($product = $productsResult->fetch_assoc()): ?>
                                <?php
                                $product_id = (int) $product['product_id'];
                                $model_name = htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8');
                                $category = htmlspecialchars((string) ($product['category'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $editProductCallback = htmlspecialchars(sprintf('openEditModal("product", %d, %s, %s)', $product_id, json_encode($product['model_name']), json_encode($product['category'])), ENT_QUOTES, 'UTF-8');
                                $openVariantModal = htmlspecialchars(sprintf('openModal("variantModal", %d)', $product_id), ENT_QUOTES, 'UTF-8');
                                $deleteProductCallback = htmlspecialchars(sprintf('deleteProduct(%d)', $product_id), ENT_QUOTES, 'UTF-8');

                                $variantStmt->bind_param('i', $product_id);
                                $variantStmt->execute();
                                $variantsResult = $variantStmt->get_result();
                                ?>
                                <div class="p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex justify-between items-start mb-4">
                                        <div class="flex-1">
                                            <h4 class="text-lg font-semibold text-gray-800 mb-1"><?php echo $model_name; ?></h4>
                                            <p class="text-sm text-gray-500">دسته‌بندی: <?php echo $category !== '' ? $category : '—'; ?></p>
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <button onclick="<?php echo $editProductCallback; ?>" class="p-2 bg-yellow-50 text-yellow-600 rounded-lg hover:bg-yellow-100 transition-colors" title="ویرایش محصول">
                                                <i data-feather="edit-2" class="w-4 h-4"></i>
                                            </button>
                                            <button onclick="<?php echo $openVariantModal; ?>" class="p-2 bg-green-50 text-green-600 rounded-lg hover:bg-green-100 transition-colors" title="افزودن تنوع">
                                                <i data-feather="plus" class="w-4 h-4"></i>
                                            </button>
                                            <button onclick="<?php echo $deleteProductCallback; ?>" class="p-2 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition-colors" title="حذف محصول">
                                                <i data-feather="trash-2" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <?php if ($variantsResult->num_rows > 0): ?>
                                        <div class="overflow-x-auto">
                                            <table class="w-full text-sm">
                                                <thead class="bg-gray-50">
                                                    <tr>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">رنگ</th>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">سایز</th>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">قیمت</th>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">موجودی</th>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">وضعیت</th>
                                                        <th class="px-4 py-3 text-right font-medium text-gray-700">عملیات</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-100">
                                                    <?php while ($variant = $variantsResult->fetch_assoc()): ?>
                                                        <?php
                                                        $variant_id = (int) $variant['variant_id'];
                                                        $color = htmlspecialchars((string) $variant['color'], ENT_QUOTES, 'UTF-8');
                                                        $size = htmlspecialchars((string) $variant['size'], ENT_QUOTES, 'UTF-8');
                                                        $price_display = number_format((float) $variant['price'], 0);
                                                        $stock = (int) $variant['stock'];
                                                        if ($stock === 0) {
                                                            $rowClass = 'out-of-stock';
                                                            $status_text = 'تمام شده';
                                                            $status_color = 'bg-red-100 text-red-800';
                                                        } elseif ($stock <= 5) {
                                                            $rowClass = 'low-stock';
                                                            $status_text = 'کم‌موجود';
                                                            $status_color = 'bg-yellow-100 text-yellow-800';
                                                        } else {
                                                            $rowClass = '';
                                                            $status_text = 'موجود';
                                                            $status_color = 'bg-green-100 text-green-800';
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
                                                        <tr class="<?php echo $rowClass; ?>">
                                                            <td class="px-4 py-3 text-gray-800"><?php echo $color; ?></td>
                                                            <td class="px-4 py-3 text-gray-800"><?php echo $size; ?></td>
                                                            <td class="px-4 py-3 text-gray-800"><?php echo $price_display; ?> تومان</td>
                                                            <td class="px-4 py-3 text-gray-800"><?php echo $stock; ?></td>
                                                            <td class="px-4 py-3">
                                                                <span class="px-2 py-1 text-xs rounded-full <?php echo $status_color; ?>"><?php echo $status_text; ?></span>
                                                            </td>
                                                            <td class="px-4 py-3">
                                                                <div class="flex items-center space-x-2">
                                                                    <button onclick="<?php echo $editVariantCallback; ?>" class="p-1.5 bg-blue-50 text-blue-600 rounded hover:bg-blue-100 transition-colors" title="ویرایش">
                                                                        <i data-feather="edit-3" class="w-3.5 h-3.5"></i>
                                                                    </button>
                                                                    <button onclick="<?php echo $deleteVariantCallback; ?>" class="p-1.5 bg-red-50 text-red-600 rounded hover:bg-red-100 transition-colors" title="حذف">
                                                                        <i data-feather="trash" class="w-3.5 h-3.5"></i>
                                                                    </button>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endwhile; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="text-center py-8 text-gray-500">
                                            <i data-feather="package" class="w-12 h-12 mx-auto mb-3 opacity-50"></i>
                                            <p>هیچ تنوعی برای این محصول تعریف نشده است</p>
                                            <button onclick="<?php echo $openVariantModal; ?>" class="mt-3 px-4 py-2 bg-blue-500 text-white text-sm rounded-lg hover:bg-blue-600 transition-colors">
                                                افزودن اولین تنوع
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <div class="text-center py-16 text-gray-500">
                                <i data-feather="package" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ محصولی یافت نشد</h3>
                                <p class="mb-6">اولین محصول خود را اضافه کنید</p>
                                <button onclick="openModal('productModal')" class="px-6 py-3 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm hover:shadow-md">
                                    <i data-feather="plus" class="ml-2"></i>
                                    محصول جدید
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Product Modal -->
    <div id="productModal" class="fixed inset-0 modal-backdrop flex items-center justify-center z-50 hidden animate-fade-in">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 animate-slide-up">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">محصول جدید</h3>
                    <button onclick="closeModal('productModal')" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-feather="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">نام مدل</label>
                            <input type="text" name="model_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="مثال: کت و شلوار کلاسیک">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">دسته‌بندی</label>
                            <select name="category" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="">انتخاب دسته‌بندی</option>
                                <option value="کت و شلوار">کت و شلوار</option>
                                <option value="کت تک">کت تک</option>
                                <option value="شلوار">شلوار</option>
                                <option value="کراوات">کراوات</option>
                            </select>
                        </div>
                        <div class="flex space-x-3 pt-4">
                            <button type="button" onclick="closeModal('productModal')" class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                انصراف
                            </button>
                            <button type="submit" name="create_product" class="flex-1 px-4 py-2.5 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-sm hover:shadow-md">
                                ایجاد محصول
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Variant Modal -->
    <div id="variantModal" class="fixed inset-0 modal-backdrop flex items-center justify-center z-50 hidden animate-fade-in">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md mx-4 animate-slide-up">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">تنوع محصول جدید</h3>
                    <button onclick="closeModal('variantModal')" class="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition-colors">
                        <i data-feather="x" class="w-5 h-5"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="variant_product_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">رنگ</label>
                            <input type="text" name="color" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="مثال: آبی تیره">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">سایز</label>
                            <select name="size" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                                <option value="">انتخاب سایز</option>
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">قیمت (تومان)</label>
                            <input type="number" name="price" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="0" min="0">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">موجودی</label>
                            <input type="number" name="stock" required class="w-full border border-gray-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="0" min="0">
                        </div>
                        <div class="flex space-x-3 pt-4">
                            <button type="button" onclick="closeModal('variantModal')" class="flex-1 px-4 py-2.5 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors">
                                انصراف
                            </button>
                            <button type="submit" name="create_variant" class="flex-1 px-4 py-2.5 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors shadow-sm hover:shadow-md">
                                ایجاد تنوع
                            </button>
                        </div>
                    </div>
                </form>
            </div>
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
            if (productId && modalId === 'variantModal') {
                document.getElementById('variant_product_id').value = productId;
            }
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

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            const modals = ['productModal', 'variantModal', 'editProductModal', 'editVariantModal'];
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
                const modals = ['productModal', 'variantModal', 'editProductModal', 'editVariantModal'];
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
