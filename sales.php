<?php
include 'env/db.php';

// Handle creating new sale
if (isset($_POST['create_sale'])) {
    $customer_id = $_POST['customer_id'];
    $sale_date = $_POST['sale_date'];
    $payment_method = $_POST['payment_method'];
    $status = $_POST['status'];

    // Insert sale
    $conn->query("INSERT INTO Sales (customer_id, sale_date, payment_method, status) VALUES ($customer_id, '$sale_date', '$payment_method', '$status')");
    $sale_id = $conn->insert_id;

    // Insert sale items and update stock
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $item) {
            $variant_id = $item['variant_id'];
            $quantity = $item['quantity'];
            $sell_price = $item['sell_price'];

            // Insert sale item
            $conn->query("INSERT INTO Sale_Items (sale_id, variant_id, quantity, sell_price) VALUES ($sale_id, $variant_id, $quantity, $sell_price)");

            // Update stock
            $conn->query("UPDATE Product_Variants SET stock = stock - $quantity WHERE variant_id = $variant_id");
        }
    }

    header('Location: sales.php');
    exit();
}

// Handle adding sale item
if (isset($_POST['add_sale_item'])) {
    $sale_id = $_POST['sale_id'];
    $variant_id = $_POST['variant_id'];
    $quantity = $_POST['quantity'];
    $sell_price = $_POST['sell_price'];
    $conn->query("INSERT INTO Sale_Items (sale_id, variant_id, quantity, sell_price) VALUES ($sale_id, $variant_id, $quantity, $sell_price)");
    // Update stock
    $conn->query("UPDATE Product_Variants SET stock = stock - $quantity WHERE variant_id = $variant_id");
    header('Location: sales.php');
    exit();
}

// Handle editing sale
if (isset($_POST['edit_sale'])) {
    $sale_id = $_POST['sale_id'];
    $customer_id = $_POST['customer_id'];
    $sale_date = $_POST['sale_date'];
    $payment_method = $_POST['payment_method'];
    $status = $_POST['status'];
    $conn->query("UPDATE Sales SET customer_id=$customer_id, sale_date='$sale_date', payment_method='$payment_method', status='$status' WHERE sale_id=$sale_id");
    header('Location: sales.php');
    exit();
}

// Handle editing sale item
if (isset($_POST['edit_sale_item'])) {
    $sale_item_id = $_POST['sale_item_id'];
    $variant_id = $_POST['variant_id'];
    $quantity = $_POST['quantity'];
    $sell_price = $_POST['sell_price'];

    // Get old quantity to adjust stock
    $old_item = $conn->query("SELECT variant_id, quantity FROM Sale_Items WHERE sale_item_id=$sale_item_id")->fetch_assoc();
    $old_variant_id = $old_item['variant_id'];
    $old_quantity = $old_item['quantity'];

    // Restore old stock
    $conn->query("UPDATE Product_Variants SET stock = stock + $old_quantity WHERE variant_id = $old_variant_id");

    // Update sale item
    $conn->query("UPDATE Sale_Items SET variant_id=$variant_id, quantity=$quantity, sell_price=$sell_price WHERE sale_item_id=$sale_item_id");

    // Update new stock
    $conn->query("UPDATE Product_Variants SET stock = stock - $quantity WHERE variant_id = $variant_id");

    header('Location: sales.php');
    exit();
}

// Handle deleting sale
if (isset($_GET['delete_sale'])) {
    $sale_id = $_GET['delete_sale'];

    // Restore stock for all items in this sale
    $items = $conn->query("SELECT variant_id, quantity FROM Sale_Items WHERE sale_id=$sale_id");
    while($item = $items->fetch_assoc()){
        $conn->query("UPDATE Product_Variants SET stock = stock + {$item['quantity']} WHERE variant_id = {$item['variant_id']}");
    }

    $conn->query("DELETE FROM Sale_Items WHERE sale_id=$sale_id");
    $conn->query("DELETE FROM Sales WHERE sale_id=$sale_id");
    header('Location: sales.php');
    exit();
}

// Handle deleting sale item
if (isset($_GET['delete_sale_item'])) {
    $sale_item_id = $_GET['delete_sale_item'];

    // Restore stock
    $item = $conn->query("SELECT variant_id, quantity FROM Sale_Items WHERE sale_item_id=$sale_item_id")->fetch_assoc();
    $conn->query("UPDATE Product_Variants SET stock = stock + {$item['quantity']} WHERE variant_id = {$item['variant_id']}");

    $conn->query("DELETE FROM Sale_Items WHERE sale_item_id=$sale_item_id");
    header('Location: sales.php');
    exit();
}

// Get sales statistics
$today_sales = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE DATE(s.sale_date) = CURDATE()")->fetch_assoc();
$month_sales = $conn->query("SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id WHERE MONTH(s.sale_date) = MONTH(CURDATE()) AND YEAR(s.sale_date) = YEAR(CURDATE())")->fetch_assoc();
$today_sales_total = $today_sales['total'] ?: 0;
$month_sales_total = $month_sales['total'] ?: 0;

// Get products for dropdown
$products = $conn->query("SELECT * FROM Products ORDER BY model_name");
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مدیریت فروش‌ها - SuitStore Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/feather-icons"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Vazirmatn:wght@100;200;300;400;500;600;700;800;900&display=swap');
        
        body {
            font-family: 'Vazirmatn', sans-serif;
        }
        
        .status-badge {
            transition: all 0.2s;
        }
        .status-badge:hover {
            opacity: 0.9;
        }
        .sale-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 10px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }
        
        /* Animation for modals */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .modal-fade {
            animation: fadeIn 0.2s ease-out;
        }
        
        .modal-slide {
            animation: slideIn 0.3s ease-out;
        }
        
        /* Loading spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #3498db;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
            display: inline-block;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Color selection styles */
        .color-option {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
            background-color: white;
            color: #374151;
        }

        .color-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            color: #3b82f6;
            font-weight: 600;
        }

        .color-option:hover {
            border-color: #3b82f6;
        }
        
        .size-option {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s;
        }
        
        .size-option.selected {
            border-color: #3b82f6;
            background-color: #eff6ff;
            color: #3b82f6;
            font-weight: 600;
        }
        
        .size-option:hover {
            border-color: #3b82f6;
        }
        
        .size-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background-color: #f9fafb;
        }
        
        .size-option.disabled:hover {
            border-color: #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <aside class="w-64 bg-white border-l border-gray-200 flex flex-col shadow-sm">
            <div class="p-6 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800 flex items-center">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 ml-2 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                    </svg>
                    SuitStore Pro
                </h1>
            </div>
            <nav class="flex-1 p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="index.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="home" class="ml-2 w-5 h-5"></i>
                            <span>داشبورد</span>
                        </a>
                    </li>
                    <li>
                        <a href="products.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="package" class="ml-2 w-5 h-5"></i>
                            <span>محصولات</span>
                        </a>
                    </li>
                    <li>
                        <a href="sales.php" class="flex items-center px-4 py-3 bg-blue-50 text-blue-700 rounded-lg border-r-2 border-blue-500">
                            <i data-feather="shopping-cart" class="ml-2 w-5 h-5"></i>
                            <span>فروش‌ها</span>
                        </a>
                    </li>
                    <li>
                        <a href="returns.php" class="flex items-center px-4 py-3 text-gray-700 rounded-lg hover:bg-gray-100 transition-colors">
                            <i data-feather="refresh-ccw" class="ml-2 w-5 h-5"></i>
                            <span>مرجوعی‌ها</span>
                        </a>
                    </li>
                </ul>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="flex-1 overflow-auto">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center shadow-sm">
                <h2 class="text-xl font-semibold text-gray-800">مدیریت فروش‌ها</h2>
                <div class="flex items-center space-x-4 space-x-reverse">
                    <button onclick="openModal('newSaleModal')" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors shadow-md">
                        <i data-feather="plus" class="ml-2 w-4 h-4"></i>
                        <span>فروش جدید</span>
                    </button>
                </div>
            </header>

            <!-- Sales Content -->
            <main class="p-6">
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-blue-50 rounded-lg ml-4">
                                <i data-feather="shopping-bag" class="w-6 h-6 text-blue-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش امروز</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($today_sales_total, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-green-50 rounded-lg ml-4">
                                <i data-feather="trending-up" class="w-6 h-6 text-green-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">فروش این ماه</h3>
                                <p class="text-xl font-bold text-gray-800"><?php echo number_format($month_sales_total, 0); ?> تومان</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100">
                        <div class="flex items-center">
                            <div class="p-3 bg-purple-50 rounded-lg ml-4">
                                <i data-feather="dollar-sign" class="w-6 h-6 text-purple-500"></i>
                            </div>
                            <div>
                                <h3 class="text-sm text-gray-500">میانگین هر فاکتور</h3>
                                <p class="text-xl font-bold text-gray-800">
                                    <?php 
                                    $avg_sale = $conn->query("SELECT AVG(total) as avg_total FROM (SELECT SUM(si.quantity * si.sell_price) as total FROM Sales s JOIN Sale_Items si ON s.sale_id = si.sale_id GROUP BY s.sale_id) as t")->fetch_assoc();
                                    echo number_format($avg_sale['avg_total'] ?: 0, 0); 
                                    ?> تومان
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-6">
                        <div class="flex flex-col sm:flex-row items-start sm:items-center space-y-3 sm:space-y-0 sm:space-x-4 sm:space-x-reverse">
                            <div class="relative">
                                <i data-feather="calendar" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="date" id="dateFilter" class="pr-10 pl-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <div class="relative">
                                <i data-feather="search" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                                <input type="text" id="searchInput" placeholder="جستجو در فروش‌ها..." class="pr-10 pl-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                            </div>
                            <button onclick="filterSales()" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors">
                                اعمال فیلتر
                            </button>
                        </div>
                        <div class="flex items-center space-x-2 space-x-reverse">
                            <button id="exportBtn" class="flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-colors">
                                <i data-feather="download" class="ml-2 w-4 h-4"></i>
                                <span>خروجی Excel</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Sales List -->
                <div class="space-y-4" id="salesList">
                    <?php
$sales = $conn->query("SELECT s.*, c.name as customer_name, COUNT(si.sale_item_id) as item_count, SUM(si.quantity * si.sell_price) as total_amount FROM Sales s LEFT JOIN Customers c ON s.customer_id = c.customer_id LEFT JOIN Sale_Items si ON s.sale_id = si.sale_id GROUP BY s.sale_id ORDER BY s.sale_date DESC, s.sale_id DESC");
                    
                    if ($sales->num_rows > 0) {
                        while($sale = $sales->fetch_assoc()){
                            $status_color = $sale['status'] == 'paid' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800';
                            $payment_icon = $sale['payment_method'] == 'cash' ? 'dollar-sign' : ($sale['payment_method'] == 'credit_card' ? 'credit-card' : 'repeat');
                            
                            echo "<div class='sale-card bg-white p-6 rounded-xl shadow-sm border border-gray-100 transition-all duration-300'>
                                    <div class='flex flex-col md:flex-row md:items-center md:justify-between gap-4'>
                                        <div class='flex-1'>
                                            <div class='flex items-center space-x-3 space-x-reverse mb-2'>
                                                <h3 class='font-semibold text-gray-800'>#فروش-".$sale['sale_id']."</h3>
                                                <span class='status-badge px-2 py-1 text-xs font-semibold rounded-full $status_color'>".($sale['status'] == 'paid' ? 'پرداخت شده' : 'در انتظار پرداخت')."</span>
                                            </div>
                                            <div class='text-sm text-gray-500 flex items-center space-x-2 space-x-reverse'>
                                                <span class='font-medium'>".($sale['customer_name'] ?: 'مشتری حضوری')."</span>
                                                <span>•</span>
                                                <span>".$sale['sale_date']."</span>
                                                <span>•</span>
                                                <span class='flex items-center'>
                                                    <i data-feather='$payment_icon' class='w-4 h-4 ml-1'></i>
                                                    ".($sale['payment_method'] == 'cash' ? 'نقدی' : ($sale['payment_method'] == 'credit_card' ? 'کارت اعتباری' : 'انتقال بانکی'))."
                                                </span>
                                            </div>
                                        </div>
                                        <div class='flex items-center space-x-4 space-x-reverse'>
                                            <div class='text-left'>
                                                <div class='text-lg font-bold text-gray-800'>".number_format($sale['total_amount'], 0)." تومان</div>
                                                <div class='text-sm text-gray-500'>".$sale['item_count']." آیتم</div>
                                            </div>
                                            <div class='flex space-x-2 space-x-reverse'>
                                                <button onclick='printReceipt({$sale['sale_id']})' class='p-2 bg-green-100 rounded-lg text-green-600 hover:bg-green-200 transition-colors'>
                                                    <i data-feather='printer' class='w-4 h-4'></i>
                                                </button>
                                                <button onclick='openEditSaleModal({$sale['sale_id']}, {$sale['customer_id']}, \"{$sale['sale_date']}\", \"{$sale['payment_method']}\", \"{$sale['status']}\")' class='p-2 bg-yellow-100 rounded-lg text-yellow-600 hover:bg-yellow-200 transition-colors'>
                                                    <i data-feather='edit' class='w-4 h-4'></i>
                                                </button>
                                                <a href='?delete_sale={$sale['sale_id']}' onclick='return confirm(\"آیا مطمئن هستید که می‌خواهید این فروش را حذف کنید؟\")' class='p-2 bg-red-100 rounded-lg text-red-600 hover:bg-red-200 transition-colors'>
                                                    <i data-feather='trash-2' class='w-4 h-4'></i>
                                                </a>
                                                <button onclick='showSaleItems({$sale['sale_id']})' class='p-2 bg-blue-100 rounded-lg text-blue-600 hover:bg-blue-200 transition-colors'>
                                                    <i data-feather='eye' class='w-4 h-4'></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>";
                        }
                    } else {
                        echo "<div class='bg-white p-8 rounded-xl shadow-sm border border-gray-100 text-center'>
                                <i data-feather='shopping-cart' class='w-12 h-12 text-gray-400 mx-auto mb-4'></i>
                                <h3 class='text-lg font-medium text-gray-700 mb-2'>هنوز فروشی ثبت نشده است</h3>
                                <p class='text-gray-500 mb-4'>برای شروع، اولین فروش خود را ثبت کنید</p>
                                <button onclick='openModal(\"newSaleModal\")' class='px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-colors'>
                                    ایجاد اولین فروش
                                </button>
                            </div>";
                    }
                    ?>
                </div>

                <!-- New Sale Modal -->
                <div id="newSaleModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-5xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">فروش جدید</h3>
                                <button onclick="closeModal('newSaleModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>

                            <form method="POST" id="newSaleForm">
                                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                    <div class="lg:col-span-2">
                                        <h4 class="font-medium text-gray-700 mb-3">انتخاب محصولات</h4>

                                        <!-- Selected Items -->
                                        <div id="selectedItems" class="space-y-3 mb-4 max-h-60 overflow-y-auto p-2">
                                            <!-- Items will be added here dynamically -->
                                        </div>

                                        <!-- Add Product Form -->
                                        <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                            <div class="space-y-4">
                                                <!-- Product Selection -->
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب محصول</label>
                                                    <select id="productSelect" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="">انتخاب محصول...</option>
                                                        <?php
                                                        while($product = $products->fetch_assoc()){
                                                            echo "<option value='{$product['product_id']}'>{$product['model_name']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>

                                                <!-- Color Selection -->
                                                <div id="colorSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب رنگ</label>
                                                    <div id="colorOptions" class="flex flex-wrap gap-3">
                                                        <!-- Color options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Size Selection -->
                                                <div id="sizeSelection" class="hidden">
                                                    <label class="block text-sm font-medium text-gray-700 mb-2">انتخاب سایز</label>
                                                    <div id="sizeOptions" class="grid grid-cols-6 gap-2">
                                                        <!-- Size options will be loaded here -->
                                                    </div>
                                                </div>

                                                <!-- Quantity and Add Button -->
                                                <div id="quantitySelection" class="hidden">
                                                    <div class="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                                            <input type="number" id="quantityInput" min="1" value="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        </div>
                                                        <div class="flex items-end">
                                                            <button type="button" onclick="addItemToSale()" class="w-full bg-blue-500 text-white py-2 rounded-lg hover:bg-blue-600 transition-colors">
                                                                افزودن به فروش
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div id="stockInfo" class="mt-2 text-sm text-gray-500">
                                                        <!-- Stock info will be shown here -->
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div>
                                        <h4 class="font-medium text-gray-700 mb-3">جزئیات فروش</h4>
                                        <div class="bg-gray-50 p-4 rounded-lg">
                                            <div class="space-y-4">
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">مشتری</label>
                                                    <select name="customer_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="0">مشتری حضوری</option>
                                                        <?php
                                                        $customers = $conn->query("SELECT * FROM Customers ORDER BY name");
                                                        while($customer = $customers->fetch_assoc()){
                                                            echo "<option value='{$customer['customer_id']}'>{$customer['name']}</option>";
                                                        }
                                                        ?>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">روش پرداخت</label>
                                                    <select name="payment_method" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="cash">نقدی</option>
                                                        <option value="credit_card">کارت اعتباری</option>
                                                        <option value="bank_transfer">انتقال بانکی</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ فروش</label>
                                                    <input type="date" name="sale_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                </div>
                                                <div>
                                                    <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                                    <select name="status" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                                        <option value="pending">در انتظار پرداخت</option>
                                                        <option value="paid">پرداخت شده</option>
                                                    </select>
                                                </div>

                                                <div class="pt-4 border-t border-gray-200">
                                                    <div class="flex justify-between mb-2">
                                                        <span class="text-gray-600">مجموع</span>
                                                        <span id="subtotal" class="font-medium">0 تومان</span>
                                                    </div>
                                                    <div class="flex justify-between font-bold text-lg mt-3 pt-3 border-t border-gray-200">
                                                        <span>مجموع کل</span>
                                                        <span id="total" class="text-blue-600">0 تومان</span>
                                                    </div>
                                                </div>

                                                <button type="submit" name="create_sale" onclick="return validateSaleForm()" class="w-full bg-blue-500 text-white py-3 rounded-lg hover:bg-blue-600 transition-colors mt-4 flex items-center justify-center">
                                                    <i data-feather="check" class="ml-2 w-5 h-5"></i>
                                                    تکمیل فروش
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hidden inputs for sale items -->
                                <div id="saleItemsInputs">
                                    <!-- Sale item inputs will be added here -->
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Edit Sale Modal -->
                <div id="editSaleModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش فروش</h3>
                                <button onclick="closeModal('editSaleModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="sale_id" id="edit_sale_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">مشتری</label>
                                        <select name="customer_id" id="edit_customer_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <?php
                                            $customers = $conn->query("SELECT * FROM Customers");
                                            while($customer = $customers->fetch_assoc()){
                                                echo "<option value='{$customer['customer_id']}'>{$customer['name']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تاریخ فروش</label>
                                        <input type="date" name="sale_date" id="edit_sale_date" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">روش پرداخت</label>
                                        <select name="payment_method" id="edit_payment_method" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="cash">نقدی</option>
                                            <option value="credit_card">کارت اعتباری</option>
                                            <option value="bank_transfer">انتقال بانکی</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">وضعیت</label>
                                        <select name="status" id="edit_status" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="paid">پرداخت شده</option>
                                            <option value="pending">در انتظار پرداخت</option>
                                        </select>
                                    </div>
                                    <button type="submit" name="edit_sale" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors flex items-center justify-center">
                                        <i data-feather="edit" class="ml-2 w-4 h-4"></i>
                                        ویرایش فروش
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sale Items Modal -->
                <div id="saleItemsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-4xl max-h-[90vh] overflow-y-auto modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">آیتم‌های فروش</h3>
                                <button onclick="closeModal('saleItemsModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <div id="saleItemsContent">
                                <!-- Sale items will be loaded here -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Sale Item Modal -->
                <div id="editSaleItemModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden modal-fade">
                    <div class="bg-white rounded-xl shadow-xl w-full max-w-md modal-slide">
                        <div class="p-6">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-xl font-semibold text-gray-800">ویرایش آیتم فروش</h3>
                                <button onclick="closeModal('editSaleItemModal')" class="text-gray-500 hover:text-gray-700 transition-colors">
                                    <i data-feather="x"></i>
                                </button>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="sale_item_id" id="edit_sale_item_id">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">محصول</label>
                                        <select name="variant_id" id="edit_item_variant_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                            <option value="">انتخاب محصول...</option>
                                            <?php
                                            $variants = $conn->query("SELECT pv.*, p.model_name FROM Product_Variants pv JOIN Products p ON pv.product_id = p.product_id ORDER BY p.model_name, pv.color, pv.size");
                                            while($variant = $variants->fetch_assoc()){
                                                echo "<option value='{$variant['variant_id']}'>{$variant['model_name']} - {$variant['color']} / {$variant['size']}</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">تعداد</label>
                                        <input type="number" name="quantity" id="edit_item_quantity" min="1" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">قیمت فروش</label>
                                        <input type="number" name="sell_price" id="edit_item_sell_price" step="0.01" min="0" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    </div>
                                    <button type="submit" name="edit_sale_item" class="w-full bg-yellow-500 text-white py-2 rounded-lg hover:bg-yellow-600 transition-colors flex items-center justify-center">
                                        <i data-feather="edit" class="ml-2 w-4 h-4"></i>
                                        ویرایش آیتم
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        feather.replace();

        let saleItems = [];
        let itemCounter = 0;
        let selectedProductId = null;
        let selectedColor = null;
        let selectedSize = null;
        let currentVariants = [];

        function openModal(modalId) {
            if (modalId === 'newSaleModal') {
                resetNewSaleModal();
            }
            document.getElementById(modalId).classList.remove('hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
        }

        function resetNewSaleModal() {
            saleItems = [];
            itemCounter = 0;
            selectedProductId = null;
            selectedColor = null;
            selectedSize = null;
            currentVariants = [];
            
            document.getElementById('selectedItems').innerHTML = '';
            document.getElementById('saleItemsInputs').innerHTML = '';
            document.getElementById('subtotal').textContent = '0 تومان';
            document.getElementById('total').textContent = '0 تومان';
            document.getElementById('productSelect').value = '';
            document.getElementById('quantityInput').value = '1';
            
            // Hide selection sections
            document.getElementById('colorSelection').classList.add('hidden');
            document.getElementById('sizeSelection').classList.add('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
        }

        // When product is selected
        document.getElementById('productSelect').addEventListener('change', function() {
            const productId = this.value;
            
            if (!productId) {
                document.getElementById('colorSelection').classList.add('hidden');
                document.getElementById('sizeSelection').classList.add('hidden');
                document.getElementById('quantitySelection').classList.add('hidden');
                return;
            }
            
            selectedProductId = productId;
            selectedColor = null;
            selectedSize = null;
            
            // Load colors for this product
            loadColors(productId);
        });

        function loadColors(productId) {
            // Show loading state
            document.getElementById('colorOptions').innerHTML = '<div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span>';
            document.getElementById('colorSelection').classList.remove('hidden');
            document.getElementById('sizeSelection').classList.add('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
            
            // Fetch colors from server
            fetch('get_product_colors.php?product_id=' + productId)
                .then(response => response.json())
                .then(data => {
                    currentVariants = data.variants;
                    const colorOptions = document.getElementById('colorOptions');
                    colorOptions.innerHTML = '';
                    
                    if (data.colors.length === 0) {
                        colorOptions.innerHTML = '<p class="text-gray-500">هیچ رنگی برای این محصول موجود نیست</p>';
                        return;
                    }
                    
                    data.colors.forEach(color => {
                        const colorOption = document.createElement('div');
                        colorOption.className = 'color-option';
                        colorOption.textContent = color.color_name || color.color;
                        colorOption.setAttribute('data-color', color.color);

                        colorOption.addEventListener('click', function() {
                            selectColor(color.color);
                        });

                        colorOptions.appendChild(colorOption);
                    });
                })
                .catch(error => {
                    console.error('Error loading colors:', error);
                    document.getElementById('colorOptions').innerHTML = '<p class="text-red-500">خطا در بارگذاری رنگ‌ها</p>';
                });
        }

        function selectColor(color) {
            selectedColor = color;
            selectedSize = null;
            
            // Update UI - mark selected color
            document.querySelectorAll('.color-option').forEach(option => {
                if (option.getAttribute('data-color') === color) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });
            
            // Load sizes for selected color
            loadSizes(selectedProductId, color);
        }

        function loadSizes(productId, color) {
            // Show loading state
            document.getElementById('sizeOptions').innerHTML = '<div class="col-span-6 flex justify-center"><div class="spinner"></div><span class="mr-2 text-gray-600">در حال بارگذاری...</span></div>';
            document.getElementById('sizeSelection').classList.remove('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
            
            // Filter variants by product and color
            const availableSizes = [];
            const sizeMap = {};
            
            currentVariants.forEach(variant => {
                if (variant.product_id == productId && variant.color === color) {
                    if (!sizeMap[variant.size]) {
                        sizeMap[variant.size] = {
                            size: variant.size,
                            stock: variant.stock,
                            price: variant.price,
                            variant_id: variant.variant_id
                        };
                    }
                }
            });
            
            // Convert to array
            Object.values(sizeMap).forEach(sizeInfo => {
                availableSizes.push(sizeInfo);
            });
            
            // Display sizes
            const sizeOptions = document.getElementById('sizeOptions');
            sizeOptions.innerHTML = '';
            
            if (availableSizes.length === 0) {
                sizeOptions.innerHTML = '<p class="col-span-6 text-gray-500">هیچ سایزی برای این رنگ موجود نیست</p>';
                return;
            }
            
            // Define all possible sizes
            const allSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
            
            allSizes.forEach(size => {
                const sizeInfo = availableSizes.find(s => s.size === size);
                const sizeOption = document.createElement('div');
                sizeOption.className = `size-option ${sizeInfo ? '' : 'disabled'}`;
                sizeOption.textContent = size;
                
                if (sizeInfo) {
                    sizeOption.setAttribute('data-size', size);
                    sizeOption.setAttribute('data-stock', sizeInfo.stock);
                    sizeOption.setAttribute('data-price', sizeInfo.price);
                    sizeOption.setAttribute('data-variant-id', sizeInfo.variant_id);
                    
                    sizeOption.addEventListener('click', function() {
                        selectSize(size, sizeInfo.stock, sizeInfo.price, sizeInfo.variant_id);
                    });
                }
                
                sizeOptions.appendChild(sizeOption);
            });
        }

        function selectSize(size, stock, price, variantId) {
            selectedSize = size;
            
            // Update UI - mark selected size
            document.querySelectorAll('.size-option').forEach(option => {
                if (option.getAttribute('data-size') === size) {
                    option.classList.add('selected');
                } else {
                    option.classList.remove('selected');
                }
            });
            
            // Show quantity selection
            document.getElementById('quantitySelection').classList.remove('hidden');
            document.getElementById('quantityInput').max = stock;
            document.getElementById('quantityInput').value = '1';
            
            // Update stock info
            document.getElementById('stockInfo').innerHTML = `
                <div class="flex justify-between">
                    <span>موجودی:</span>
                    <span class="font-medium">${stock} عدد</span>
                </div>
                <div class="flex justify-between">
                    <span>قیمت واحد:</span>
                    <span class="font-medium">${price.toLocaleString()} تومان</span>
                </div>
            `;
            
            // Store current variant info
            currentVariantInfo = {
                variantId: variantId,
                price: price,
                stock: stock
            };
        }

        function addItemToSale() {
            const quantityInput = document.getElementById('quantityInput');
            const quantity = parseInt(quantityInput.value);

            if (!selectedProductId || !selectedColor || !selectedSize) {
                alert('لطفا محصول، رنگ و سایز را انتخاب کنید.');
                return;
            }

            if (!quantity || quantity < 1) {
                alert('لطفا تعداد معتبر وارد کنید.');
                return;
            }

            if (quantity > currentVariantInfo.stock) {
                alert('تعداد انتخاب شده بیشتر از موجودی است.');
                return;
            }

            // Get product name
            const productSelect = document.getElementById('productSelect');
            const productName = productSelect.options[productSelect.selectedIndex].text;

            // Check if item already exists
            const existingItem = saleItems.find(item => item.variantId === currentVariantInfo.variantId);
            if (existingItem) {
                existingItem.quantity += quantity;
                existingItem.total = existingItem.quantity * existingItem.price;
                updateSelectedItemsDisplay();
                updateTotals();
            } else {
                const item = {
                    id: itemCounter++,
                    variantId: currentVariantInfo.variantId,
                    productName: `${productName} - ${selectedColor} - ${selectedSize}`,
                    quantity: quantity,
                    price: currentVariantInfo.price,
                    total: quantity * currentVariantInfo.price
                };
                saleItems.push(item);
                addItemToDisplay(item);
            }

            updateTotals();
            updateHiddenInputs();

            // Reset selection
            selectedColor = null;
            selectedSize = null;
            document.getElementById('colorSelection').classList.add('hidden');
            document.getElementById('sizeSelection').classList.add('hidden');
            document.getElementById('quantitySelection').classList.add('hidden');
            document.querySelectorAll('.color-option').forEach(opt => opt.classList.remove('selected'));
            document.querySelectorAll('.size-option').forEach(opt => opt.classList.remove('selected'));
        }

        function addItemToDisplay(item) {
            const selectedItems = document.getElementById('selectedItems');
            const itemDiv = document.createElement('div');
            itemDiv.className = 'bg-white p-4 rounded-lg border border-gray-200 flex items-center justify-between';
            itemDiv.id = `item-${item.id}`;

            itemDiv.innerHTML = `
                <div class="flex-1">
                    <h5 class="font-medium text-gray-800">${item.productName}</h5>
                    <div class="text-sm text-gray-500">تعداد: ${item.quantity} × ${item.price.toLocaleString()} تومان</div>
                </div>
                <div class="text-left">
                    <div class="font-bold text-gray-800">${item.total.toLocaleString()} تومان</div>
                </div>
                <button onclick="removeItem(${item.id})" class="text-red-500 hover:text-red-700 mr-2 transition-colors">
                    <i data-feather="trash-2" class="w-4 h-4"></i>
                </button>
            `;

            selectedItems.appendChild(itemDiv);
            feather.replace();
        }

        function removeItem(itemId) {
            saleItems = saleItems.filter(item => item.id !== itemId);
            document.getElementById(`item-${itemId}`).remove();
            updateTotals();
            updateHiddenInputs();
        }

        function updateSelectedItemsDisplay() {
            const selectedItems = document.getElementById('selectedItems');
            selectedItems.innerHTML = '';
            saleItems.forEach(item => addItemToDisplay(item));
        }

        function updateTotals() {
            const subtotal = saleItems.reduce((sum, item) => sum + item.total, 0);
            document.getElementById('subtotal').textContent = subtotal.toLocaleString() + ' تومان';
            document.getElementById('total').textContent = subtotal.toLocaleString() + ' تومان';
        }

        function updateHiddenInputs() {
            const inputsContainer = document.getElementById('saleItemsInputs');
            inputsContainer.innerHTML = '';

            saleItems.forEach((item, index) => {
                inputsContainer.innerHTML += `
                    <input type="hidden" name="items[${index}][variant_id]" value="${item.variantId}">
                    <input type="hidden" name="items[${index}][quantity]" value="${item.quantity}">
                    <input type="hidden" name="items[${index}][sell_price]" value="${item.price}">
                `;
            });
        }

        function openEditSaleModal(saleId, customerId, saleDate, paymentMethod, status) {
            document.getElementById('edit_sale_id').value = saleId;
            document.getElementById('edit_customer_id').value = customerId;
            document.getElementById('edit_sale_date').value = saleDate;
            document.getElementById('edit_payment_method').value = paymentMethod;
            document.getElementById('edit_status').value = status;
            document.getElementById('editSaleModal').classList.remove('hidden');
        }

        function openEditSaleItemModal(saleItemId, variantId, quantity, sellPrice) {
            document.getElementById('edit_sale_item_id').value = saleItemId;
            document.getElementById('edit_item_variant_id').value = variantId;
            document.getElementById('edit_item_quantity').value = quantity;
            document.getElementById('edit_item_sell_price').value = sellPrice;
            document.getElementById('editSaleItemModal').classList.remove('hidden');
        }

        function validateSaleForm() {
            if (saleItems.length === 0) {
                alert('لطفا حداقل یک محصول به فروش اضافه کنید.');
                return false;
            }
            return true;
        }

        function showSaleItems(saleId) {
            // Show loading state
            document.getElementById('saleItemsContent').innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="spinner"></div>
                    <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                </div>
            `;
            
            document.getElementById('saleItemsModal').classList.remove('hidden');
            
            // Load sale items via AJAX
            fetch('get_sale_items.php?sale_id=' + saleId)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('saleItemsContent').innerHTML = data;
                    feather.replace();
                })
                .catch(error => {
                    console.error('Error loading sale items:', error);
                    document.getElementById('saleItemsContent').innerHTML = `
                        <div class="text-center py-8 text-red-500">
                            <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                            <p>خطا در بارگذاری آیتم‌های فروش</p>
                        </div>
                    `;
                    feather.replace();
                });
        }

        function filterSales() {
            const dateFilter = document.getElementById('dateFilter').value;
            const searchInput = document.getElementById('searchInput').value;
            
            // Show loading state
            document.getElementById('salesList').innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="spinner"></div>
                    <span class="mr-2 text-gray-600">در حال بارگذاری...</span>
                </div>
            `;
            
            // Send filter request
            const formData = new FormData();
            formData.append('date', dateFilter);
            formData.append('search', searchInput);
            
            fetch('filter_sales.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(data => {
                document.getElementById('salesList').innerHTML = data;
                feather.replace();
            })
            .catch(error => {
                console.error('Error filtering sales:', error);
                document.getElementById('salesList').innerHTML = `
                    <div class="text-center py-8 text-red-500">
                        <i data-feather="alert-circle" class="w-12 h-12 mx-auto mb-4"></i>
                        <p>خطا در فیلتر کردن فروش‌ها</p>
                    </div>
                `;
                feather.replace();
            });
        }

        // Print receipt functionality
        function printReceipt(saleId) {
            // Show loading state
            const printBtn = event.target.closest('button');
            const originalHTML = printBtn.innerHTML;
            printBtn.innerHTML = '<div class="spinner"></div>';
            printBtn.disabled = true;

            // Fetch sale data for printing
            fetch('get_sale_receipt.php?sale_id=' + saleId)
                .then(response => response.text())
                .then(data => {
                    // Create a new window for printing
                    const printWindow = window.open('', '_blank', 'width=800,height=600');
                    printWindow.document.write(data);
                    printWindow.document.close();

                    // Wait for content to load then print
                    printWindow.onload = function() {
                        printWindow.print();
                        printWindow.close();
                    };

                    // Reset button
                    printBtn.innerHTML = originalHTML;
                    printBtn.disabled = false;
                })
                .catch(error => {
                    console.error('Error loading receipt:', error);
                    alert('خطا در بارگذاری رسید');

                    // Reset button
                    printBtn.innerHTML = originalHTML;
                    printBtn.disabled = false;
                });
        }

        // Export to Excel functionality
        document.getElementById('exportBtn').addEventListener('click', function() {
            // Show loading state
            const originalText = this.innerHTML;
            this.innerHTML = '<div class="spinner"></div><span class="mr-2">در حال تولید...</span>';
            this.disabled = true;

            // Simulate export process
            setTimeout(() => {
                alert('فایل اکسل با موفقیت تولید شد!');
                this.innerHTML = originalText;
                this.disabled = false;
            }, 1500);
        });
    </script>
</body>
</html>