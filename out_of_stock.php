<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

// Get out of stock products
$outOfStockQuery = "
    SELECT
        p.product_id,
        p.model_name,
        p.category,
        GROUP_CONCAT(
            CONCAT(
                pv.color, ' - ', pv.size, ' (', pv.stock, ')'
            ) SEPARATOR '; '
        ) as out_of_stock_variants
    FROM Products p
    JOIN Product_Variants pv ON p.product_id = pv.product_id
    WHERE pv.stock = 0
    GROUP BY p.product_id, p.model_name, p.category
    ORDER BY p.model_name
";

$result = $conn->query($outOfStockQuery);
$outOfStockProducts = [];
while ($row = $result->fetch_assoc()) {
    $outOfStockProducts[] = $row;
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>محصولات تمام شده - SuitStore Manager Pro</title>
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
        <?php include 'sidebar.php'; ?>
        
        <!-- Main Content -->
        <div class="flex-1 overflow-auto custom-scrollbar">
            <!-- Header -->
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center header-shadow">
                <h2 class="text-xl font-semibold text-gray-800">محصولات تمام شده</h2>
                <div class="flex items-center space-x-4">
                    <a href="products.php" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i data-feather="package" class="ml-2"></i>
                        مدیریت محصولات
                    </a>
                </div>
            </header>

            <!-- Out of Stock Content -->
            <main class="p-6">
                <!-- Stats -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">محصولات تمام شده</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo count($outOfStockProducts); ?></p>
                            </div>
                            <div class="p-3 bg-red-50 rounded-lg">
                                <i data-feather="x-circle" class="w-6 h-6 text-red-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">کل تنوع‌های تمام شده</p>
                                <p class="text-2xl font-bold text-gray-800">
                                    <?php
                                    $totalVariants = 0;
                                    foreach ($outOfStockProducts as $product) {
                                        $variants = explode('; ', $product['out_of_stock_variants']);
                                        $totalVariants += count($variants);
                                    }
                                    echo $totalVariants;
                                    ?>
                                </p>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <i data-feather="layers" class="w-6 h-6 text-gray-600"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Products List -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="p-6 border-b border-gray-100">
                        <h3 class="text-lg font-semibold text-gray-800">لیست محصولات تمام شده</h3>
                    </div>
                    <div class="divide-y divide-gray-100">
                        <?php if (count($outOfStockProducts) > 0): ?>
                            <?php foreach ($outOfStockProducts as $product): ?>
                                <div class="p-6 hover:bg-gray-50 transition-colors">
                                    <div class="flex justify-between items-start mb-4">
                                        <div class="flex-1">
                                            <h4 class="text-lg font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                            <p class="text-sm text-gray-500">
                                                دسته‌بندی: <?php echo htmlspecialchars($product['category'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="overflow-x-auto">
                                        <table class="w-full text-sm">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th class="px-4 py-3 text-right font-medium text-gray-700">تنوع تمام شده</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100">
                                                <?php
                                                $variants = explode('; ', $product['out_of_stock_variants']);
                                                foreach ($variants as $variant):
                                                ?>
                                                    <tr class="out-of-stock">
                                                        <td class="px-4 py-3 text-gray-800"><?php echo htmlspecialchars($variant, ENT_QUOTES, 'UTF-8'); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-16 text-gray-500">
                                <i data-feather="check-circle" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                                <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ محصولی تمام نشده</h3>
                                <p class="mb-6">تمام محصولات موجودی دارند</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
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
    </script>
</body>
</html>
