<?php
include 'env/db.php';

// Handle creating new product
if (isset($_POST['create_product'])) {
    $model_name = $_POST['model_name'];
    $category = $_POST['category'];
    $conn->query("INSERT INTO Products (model_name, category) VALUES ('$model_name', '$category')");
    header('Location: products.php');
    exit();
}

// Handle creating new product variant
if (isset($_POST['create_variant'])) {
    $product_id = $_POST['product_id'];
    $color = $_POST['color'];
    $size = $_POST['size'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $conn->query("INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES ($product_id, '$color', '$size', $price, $stock)");
    header('Location: products.php');
    exit();
}

// Handle editing product
if (isset($_POST['edit_product'])) {
    $product_id = $_POST['product_id'];
    $model_name = $_POST['model_name'];
    $category = $_POST['category'];
    $conn->query("UPDATE Products SET model_name='$model_name', category='$category' WHERE product_id=$product_id");
    header('Location: products.php');
    exit();
}

// Handle editing variant
if (isset($_POST['edit_variant'])) {
    $variant_id = $_POST['variant_id'];
    $color = $_POST['color'];
    $size = $_POST['size'];
    $price = $_POST['price'];
    $stock = $_POST['stock'];
    $conn->query("UPDATE Product_Variants SET color='$color', size='$size', price=$price, stock=$stock WHERE variant_id=$variant_id");
    header('Location: products.php');
    exit();
}

// Handle deleting product
if (isset($_GET['delete_product'])) {
    $product_id = $_GET['delete_product'];
    $conn->query("DELETE FROM Product_Variants WHERE product_id=$product_id");
    $conn->query("DELETE FROM Products WHERE product_id=$product_id");
    echo "<script>alert('محصول و تمام تنوع‌های آن حذف شد!');</script>";
}

// Handle deleting variant
if (isset($_GET['delete_variant'])) {
    $variant_id = $_GET['delete_variant'];
    $conn->query("DELETE FROM Product_Variants WHERE variant_id=$variant_id");
    echo "<script>alert('تنوع محصول حذف شد!');</script>";
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت محصولات - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        .variant-row:hover {
            background-color: #f9fafb;
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-l border-gray-200 flex flex-col">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800">SuitStore Pro</h1>
            </div>
            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li><a href="index.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100"><i data-feather="home" class="ml-2"></i>داشبورد</a></li>
                    <li><a href="products.php" class="flex items-center px-4 py-2 bg-blue-50 text-blue-700 rounded-lg"><i data-feather="package" class="ml-2"></i>محصولات</a></li>
                    <li><a href="sales.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100"><i data-feather="shopping-cart" class="ml-2"></i>فروش‌ها</a></li>
                    <li><a href="returns.php" class="flex items-center px-4 py-2 text-gray-700 rounded-lg hover:bg-gray-100"><i data-feather="refresh-ccw" class="ml-2"></i>مرجوعی‌ها</a></li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center">
                <h2 class="text-xl font-semibold text-gray-800">مدیریت محصولات</h2>
                <div class="flex items-center space-x-4">
                    <button onclick="openModal('productModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                        <i data-feather="plus" class="ml-2"></i>
                        محصول جدید
                    </button>
                </div>
            </header>

            <!-- Products Content -->
            <main class="p-6">
                <!-- Stats -->
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
                    <div class="flex items-center space-x-4">
                        <div class="p-3 bg-blue-50 rounded-lg">
                            <div class="text-sm text-gray-500">کل محصولات</div>
                            <div class="text-xl font-bold text-gray-800">
                                <?php echo $conn->query("SELECT COUNT(*) as count FROM Products")->fetch_assoc()['count']; ?>
                            </div>
                        </div>
                        <div class="p-3 bg-green-50 rounded-lg">
                            <div class="text-sm text-gray-500">کل تنوع‌ها</div>
                            <div class="text-xl font-bold text-gray-800">
                                <?php echo $conn->query("SELECT COUNT(*) as count FROM Product_Variants")->fetch_assoc()['count']; ?>
                            </div>
                        </div>
                        <div class="p-3 bg-yellow-50 rounded-lg">
                            <div class="text-sm text-gray-500">کل موجودی</div>
                            <div class="text-xl font-bold text-gray-800">
                                <?php echo $conn->query("SELECT SUM(stock) as total FROM Product_Variants")->fetch_assoc()['total']; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products List -->
                <div class="space-y-6">
                    <?php
                    $products = $conn->query("SELECT * FROM Products ORDER BY product_id DESC");
                    while($product = $products->fetch_assoc()){
                        echo "<div class='product-card bg-white p-6 rounded-xl shadow-sm border border-gray-100 transition-all'>
                                <div class='flex justify-between items-center mb-4'>
                                    <div>
                                        <h3 class='text-lg font-semibold text-gray-800'>{$product['model_name']}</h3>
                                        <p class='text-sm text-gray-500'>دسته‌بندی: {$product['category']}</p>
                                    </div>
                                    <div class='flex space-x-2'>
                                        <button onclick='openEditModal(\"product\", {$product['product_id']}, \"{$product['model_name']}\", \"{$product['category']}\")' class='p-2 bg-yellow-100 rounded-lg text-yellow-600 hover:bg-yellow-200'>
                                            <i data-feather='edit' class='w-4 h-4'></i>
                                        </button>
                                        <a href='?delete_product={$product['product_id']}' onclick='return confirm(\"آیا مطمئن هستید؟\")' class='p-2 bg-red-100 rounded-lg text-red-600 hover:bg-red-200'>
                                            <i data-feather='trash-2' class='w-4 h-4'></i>
                                        </a>
                                        <button onclick='openModal(\"variantModal\", {$product['product_id']})' class='p-2 bg-green-100 rounded-lg text-green-600 hover:bg-green-200'>
                                            <i data-feather='plus' class='w-4 h-4'></i>
                                        </button>
                                    </div>
                                </div>
                                <div class='overflow-x-auto'>
                                    <table class='w-full text-sm'>
                                        <thead class='bg-gray-50'>
                                            <tr>
                                                <th class='px-4 py-2 text-right'>رنگ</th>
                                                <th class='px-4 py-2 text-right'>سایز</th>
                                                <th class='px-4 py-2 text-right'>قیمت</th>
                                                <th class='px-4 py-2 text-right'>موجودی</th>
                                                <th class='px-4 py-2 text-right'>عملیات</th>
                                            </tr>
                                        </thead>
                                        <tbody>";
                        $variants = $conn->query("SELECT * FROM Product_Variants WHERE product_id={$product['product_id']}");
                        while($variant = $variants->fetch_assoc()){
                            echo "<tr class='variant-row border-t border-gray-100'>
                                    <td class='px-4 py-2'>{$variant['color']}</td>
                                    <td class='px-4 py-2'>{$variant['size']}</td>
                                    <td class='px-4 py-2'>".number_format($variant['price'], 0)." تومان</td>
                                    <td class='px-4 py-2'>{$variant['stock']}</td>
                                    <td class='px-4 py-2'>
                                        <button onclick='openEditModal(\"variant\", {$variant['variant_id']}, \"{$variant['color']}\", \"{$variant['size']}\", {$variant['price']}, {$variant['stock']})' class='p-1 bg-yellow-100 rounded text-yellow-600 hover:bg-yellow-200'>
                                            <i data-feather='edit-2' class='w-3 h-3'></i>
                                        </button>
                                        <a href='?delete_variant={$variant['variant_id']}' onclick='return confirm(\"آیا مطمئن هستید؟\")' class='p-1 bg-red-100 rounded text-red-600 hover:bg-red-200 ml-1'>
                                            <i data-feather='trash' class='w-3 h-3'></i>
                                        </a>
                                    </td>
                                </tr>";
                        }
                        echo "</tbody></table></div></div>";
                    }
                    ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Product Modal -->
    <div id="productModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">محصول جدید</h3>
                    <button onclick="closeModal('productModal')" class="text-gray-500 hover:text-gray-700">
                        <i data-feather="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">نام مدل</label>
                            <input type="text" name="model_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">دسته‌بندی</label>
                            <select name="category" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="کت و شلوار">کت و شلوار</option>
                                <option value="کت تک">کت تک</option>
                                <option value="شلوار">شلوار</option>
                                <option value="کراوات">کراوات</option>
                            </select>
                        </div>
                        <button type="submit" name="create_product" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                            ایجاد محصول
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Variant Modal -->
    <div id="variantModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">تنوع محصول جدید</h3>
                    <button onclick="closeModal('variantModal')" class="text-gray-500 hover:text-gray-700">
                        <i data-feather="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="variant_product_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">رنگ</label>
                            <input type="text" name="color" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">سایز</label>
                            <select name="size" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">قیمت (تومان)</label>
                            <input type="number" name="price" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">موجودی</label>
                            <input type="number" name="stock" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button type="submit" name="create_variant" class="w-full bg-green-500 text-white py-2 rounded-lg hover:bg-green-600 transition-colors">
                            ایجاد تنوع
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Product Modal -->
    <div id="editProductModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">ویرایش محصول</h3>
                    <button onclick="closeModal('editProductModal')" class="text-gray-500 hover:text-gray-700">
                        <i data-feather="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="product_id" id="edit_product_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">نام مدل</label>
                            <input type="text" name="model_name" id="edit_model_name" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">دسته‌بندی</label>
                            <select name="category" id="edit_category" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="کت و شلوار">کت و شلوار</option>
                                <option value="کت تک">کت تک</option>
                                <option value="شلوار">شلوار</option>
                                <option value="کراوات">کراوات</option>
                            </select>
                        </div>
                        <button type="submit" name="edit_product" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors">
                            ویرایش محصول
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Variant Modal -->
    <div id="editVariantModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md">
            <div class="p-6">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">ویرایش تنوع محصول</h3>
                    <button onclick="closeModal('editVariantModal')" class="text-gray-500 hover:text-gray-700">
                        <i data-feather="x"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="variant_id" id="edit_variant_id">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">رنگ</label>
                            <input type="text" name="color" id="edit_color" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">سایز</label>
                            <select name="size" id="edit_size" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="S">S</option>
                                <option value="M">M</option>
                                <option value="L">L</option>
                                <option value="XL">XL</option>
                                <option value="XXL">XXL</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">قیمت (تومان)</label>
                            <input type="number" name="price" id="edit_price" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">موجودی</label>
                            <input type="number" name="stock" id="edit_stock" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button type="submit" name="edit_variant" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors">
                            ویرایش تنوع
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        feather.replace();

        function openModal(modalId, productId = null) {
            document.getElementById(modalId).classList.remove('hidden');
            if (productId && modalId === 'variantModal') {
                document.getElementById('variant_product_id').value = productId;
            }
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function openEditModal(type, id, ...args) {
            if (type === 'product') {
                document.getElementById('edit_product_id').value = id;
                document.getElementById('edit_model_name').value = args[0];
                document.getElementById('edit_category').value = args[1];
                document.getElementById('editProductModal').classList.remove('hidden');
            } else if (type === 'variant') {
                document.getElementById('edit_variant_id').value = id;
                document.getElementById('edit_color').value = args[0];
                document.getElementById('edit_size').value = args[1];
                document.getElementById('edit_price').value = args[2];
                document.getElementById('edit_stock').value = args[3];
                document.getElementById('editVariantModal').classList.remove('hidden');
            }
        }
    </script>
</body>
</html>
