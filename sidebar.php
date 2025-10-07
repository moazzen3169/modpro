<?php
// Determine active page based on current script name
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>
<!-- Sidebar -->
<aside class="w-64 bg-white border-l border-gray-200 flex flex-col sidebar-shadow no-print">
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
                <a href="index.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'index' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="home" class="ml-2 w-5 h-5"></i>
                    <span>داشبورد</span>
                </a>
            </li>

            <li>
                <a href="sales.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'sales' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="shopping-cart" class="ml-2 w-5 h-5"></i>
                    <span>فروش‌ها</span>
                </a>
            </li>

            <li>
                <a href="purchases.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'purchases' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="list" class="ml-2 w-5 h-5"></i>
                    <span> خریدها</span>
                </a>
            </li>

            <li>
                <a href="suppliers.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'suppliers' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="users" class="ml-2 w-5 h-5"></i>
                    <span>تامین‌کننده‌ها</span>
                </a>
            </li>

            <li>
                <a href="monthly_purchases.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'monthly_purchases' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="file-text" class="ml-2 w-5 h-5"></i>
                    <span>فاکتور ماهانه</span>
                </a>
            </li>

            <li>
                <a href="products.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'products' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="package" class="ml-2 w-5 h-5"></i>
                    <span>مدیریت موجودی</span>
                </a>
            </li>



            <li>
                <a href="returns.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'returns' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="refresh-ccw" class="ml-2 w-5 h-5"></i>
                    <span>مرجوعی‌ها</span>
                </a>
            </li>

            <li>
                <a href="out_of_stock.php" class="flex items-center px-4 py-3 <?php echo $current_page === 'out_of_stock' ? 'bg-blue-50 text-blue-700 rounded-lg border-r border-blue-500' : 'text-gray-700 rounded-lg hover:bg-gray-100 transition-colors'; ?>">
                    <i data-feather="x-circle" class="ml-2 w-5 h-5"></i>
                    <span>تمام شده</span>
                </a>
            </li>



        </ul>
    </nav>
    <div class="p-4 border-t border-gray-200">
        <a href="logout.php" class="flex items-center justify-center px-4 py-2 text-sm font-medium text-red-600 border border-red-200 rounded-lg hover:bg-red-50 transition">
            <i data-feather="log-out" class="ml-2 w-4 h-4"></i>
            خروج از حساب
        </a>
    </div>
</aside>
