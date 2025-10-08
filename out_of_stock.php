<?php
require_once __DIR__ . '/env/bootstrap.php';

$flash_messages = get_flash_messages();

$outOfStockQuery = "
    SELECT
        p.product_id,
        p.model_name,
        p.category,
        pv.color,
        pv.size,
        pv.stock
    FROM Products p
    JOIN Product_Variants pv ON p.product_id = pv.product_id
    WHERE pv.stock = 0
    ORDER BY p.model_name, pv.color, pv.size
";

$result = $conn->query($outOfStockQuery);

$outOfStockProducts = [];
$totalVariants = 0;
$colorStats = [];
$categories = [];

while ($row = $result->fetch_assoc()) {
    $productId = (int) $row['product_id'];
    $modelName = $row['model_name'] ?? 'نامشخص';
    $categoryName = $row['category'] ?? 'سایر';
    $colorName = ($row['color'] ?? '') !== '' ? $row['color'] : 'نامشخص';
    $sizeName = ($row['size'] ?? '') !== '' ? $row['size'] : 'نامشخص';

    if (!isset($outOfStockProducts[$productId])) {
        $outOfStockProducts[$productId] = [
            'product_id' => $productId,
            'model_name' => $modelName,
            'category' => $categoryName,
            'colors' => [],
            'variant_count' => 0,
        ];
    }

    if (!isset($outOfStockProducts[$productId]['colors'][$colorName])) {
        $outOfStockProducts[$productId]['colors'][$colorName] = [
            'sizes' => [],
        ];
    }

    if (!in_array($sizeName, $outOfStockProducts[$productId]['colors'][$colorName]['sizes'], true)) {
        $outOfStockProducts[$productId]['colors'][$colorName]['sizes'][] = $sizeName;
        $outOfStockProducts[$productId]['variant_count']++;
        $totalVariants++;

        if (!isset($colorStats[$colorName])) {
            $colorStats[$colorName] = 0;
        }
        $colorStats[$colorName]++;

        $categories[$categoryName] = true;
    }
}

$outOfStockProducts = array_values($outOfStockProducts);
usort($outOfStockProducts, function ($a, $b) {
    return strcmp($a['model_name'], $b['model_name']);
});

$categories = array_keys($categories);
sort($categories, SORT_NATURAL | SORT_FLAG_CASE);

$uniqueColors = count($colorStats);
$topColorName = null;
$topColorCount = 0;
if (!empty($colorStats)) {
    arsort($colorStats, SORT_NUMERIC);
    $topColorName = array_key_first($colorStats);
    $topColorCount = $colorStats[$topColorName];
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

        .chip {
            display: inline-flex;
            align-items: center;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            background-color: #f1f5f9;
            color: #475569;
            font-size: 0.75rem;
            font-weight: 500;
            line-height: 1;
            gap: 0.25rem;
        }

        .chip i,
        .chip svg {
            width: 0.75rem;
            height: 0.75rem;
        }

        .color-panel {
            border: 1px solid rgba(148, 163, 184, 0.4);
            border-radius: 0.75rem;
            background-color: rgba(241, 245, 249, 0.7);
            padding: 1rem;
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .color-panel:hover {
            border-color: rgba(148, 163, 184, 0.8);
            background-color: #fff;
            box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
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

        <div class="flex-1 overflow-auto custom-scrollbar">
            <header class="bg-white border-b border-gray-200 p-4 flex justify-between items-center header-shadow">
                <h2 class="text-xl font-semibold text-gray-800">محصولات تمام شده</h2>
                <div class="flex flex-wrap gap-3 items-center">
                    <button id="exportCsv" class="flex items-center px-4 py-2 bg-emerald-500 text-white rounded-lg hover:bg-emerald-600 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i data-feather="download" class="ml-2"></i>
                        خروجی CSV
                    </button>
                    <a href="products.php" class="flex items-center px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition-all duration-200 shadow-sm hover:shadow-md">
                        <i data-feather="package" class="ml-2"></i>
                        مدیریت محصولات
                    </a>
                </div>
            </header>

            <main class="p-6 space-y-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
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
                                <p class="text-2xl font-bold text-gray-800"><?php echo $totalVariants; ?></p>
                            </div>
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <i data-feather="layers" class="w-6 h-6 text-gray-600"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 card-hover">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-500 mb-1">تنوع رنگ‌های بحرانی</p>
                                <?php if ($uniqueColors > 0): ?>
                                    <p class="text-2xl font-bold text-gray-800"><?php echo $uniqueColors; ?></p>
                                    <p class="text-xs text-gray-500 mt-1">
                                        بیشترین کمبود: <?php echo htmlspecialchars($topColorName, ENT_QUOTES, 'UTF-8'); ?> (<?php echo $topColorCount; ?> سایز)
                                    </p>
                                <?php else: ?>
                                    <p class="text-lg font-semibold text-emerald-500">هیچ موردی نیست</p>
                                <?php endif; ?>
                            </div>
                            <div class="p-3 bg-orange-50 rounded-lg">
                                <i data-feather="alert-triangle" class="w-6 h-6 text-orange-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (count($outOfStockProducts) > 0): ?>
                    <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 space-y-4">
                        <div class="flex flex-col lg:flex-row items-stretch lg:items-center gap-4">
                            <div class="flex-1 relative">
                                <i data-feather="search" class="w-5 h-5 text-gray-400 absolute right-4 top-1/2 -translate-y-1/2"></i>
                                <input id="searchInput" type="text" placeholder="جستجو بر اساس مدل، رنگ یا سایز" class="w-full pr-12 pl-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all" />
                            </div>
                            <div class="w-full lg:w-52">
                                <select id="categoryFilter" class="w-full py-3 px-4 border border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                                    <option value="">همه دسته‌بندی‌ها</option>
                                    <?php foreach ($categories as $categoryOption): ?>
                                        <option value="<?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($categoryOption, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-center gap-3">
                                <button id="resetFilters" class="px-4 py-2 border border-gray-200 rounded-lg text-gray-600 hover:bg-gray-100 transition-all">ریست فیلترها</button>
                                <button id="expandAll" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all">باز کردن همه</button>
                                <button id="collapseAll" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-all">بستن همه</button>
                            </div>
                        </div>
                        <div class="flex items-center text-xs text-gray-500 gap-2">
                            <span class="chip"><i data-feather="info"></i> برای هر مدل، رنگ‌ها و سایزهای ناموجود تفکیک شده‌اند.</span>
                        </div>
                    </div>

                    <div id="productsWrapper" class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div id="productsContainer" class="divide-y divide-gray-100">
                            <?php foreach ($outOfStockProducts as $product): ?>
                                <?php
                                $colorKeywords = array_keys($product['colors']);
                                $sizeKeywords = [];
                                $exportPayload = [
                                    'model' => $product['model_name'],
                                    'category' => $product['category'],
                                    'colors' => [],
                                ];
                                foreach ($product['colors'] as $colorName => $colorData) {
                                    $sizeKeywords = array_merge($sizeKeywords, $colorData['sizes']);
                                    $exportPayload['colors'][] = [
                                        'color' => $colorName,
                                        'sizes' => $colorData['sizes'],
                                    ];
                                }
                                $copyLines = [
                                    'مدل: ' . $product['model_name'],
                                    'دسته‌بندی: ' . $product['category'],
                                ];
                                foreach ($product['colors'] as $colorName => $colorData) {
                                    $copyLines[] = 'رنگ ' . $colorName . ': ' . implode(', ', $colorData['sizes']);
                                }
                                $copyText = implode("\n", $copyLines);
                                ?>
                                <div class="p-6 space-y-5 transition-colors hover:bg-gray-50" data-product-card
                                     data-model="<?php echo htmlspecialchars(mb_strtolower($product['model_name']), ENT_QUOTES, 'UTF-8'); ?>"
                                     data-category="<?php echo htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8'); ?>"
                                     data-colors="<?php echo htmlspecialchars(mb_strtolower(implode(' ', $colorKeywords)), ENT_QUOTES, 'UTF-8'); ?>"
                                     data-sizes="<?php echo htmlspecialchars(mb_strtolower(implode(' ', $sizeKeywords)), ENT_QUOTES, 'UTF-8'); ?>"
                                     data-export="<?php echo htmlspecialchars(json_encode($exportPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                                    <div class="flex flex-col md:flex-row justify-between gap-4">
                                        <div>
                                            <h4 class="text-lg font-semibold text-gray-800 mb-1"><?php echo htmlspecialchars($product['model_name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                                            <p class="text-sm text-gray-500">
                                                دسته‌بندی: <?php echo htmlspecialchars($product['category'], ENT_QUOTES, 'UTF-8'); ?>
                                            </p>
                                            <div class="mt-3 flex flex-wrap gap-2 text-xs text-gray-600">
                                                <span class="chip">
                                                    <i data-feather="grid"></i>
                                                    <?php echo count($product['colors']); ?> رنگ
                                                </span>
                                                <span class="chip">
                                                    <i data-feather="hash"></i>
                                                    <?php echo $product['variant_count']; ?> تنوع
                                                </span>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            <button data-copy-button data-copy-text="<?php echo htmlspecialchars($copyText, ENT_QUOTES, 'UTF-8'); ?>" class="px-4 py-2 bg-white border border-gray-200 rounded-lg text-sm text-gray-700 hover:bg-gray-100 transition-all flex items-center gap-2">
                                                <i data-feather="clipboard"></i>
                                                کپی جزئیات
                                            </button>
                                            <span class="hidden text-sm text-emerald-600" data-copy-feedback>کپی شد!</span>
                                        </div>
                                    </div>

                                    <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-4">
                                        <?php foreach ($product['colors'] as $colorName => $colorData): ?>
                                            <details class="color-panel" open>
                                                <summary class="cursor-pointer flex items-center justify-between gap-2 text-sm font-semibold text-gray-700">
                                                    <span><?php echo htmlspecialchars($colorName, ENT_QUOTES, 'UTF-8'); ?></span>
                                                    <span class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 bg-white px-2 py-1 rounded-full border border-gray-200">
                                                        <i data-feather="tag" class="w-3 h-3"></i>
                                                        <?php echo count($colorData['sizes']); ?> سایز
                                                    </span>
                                                </summary>
                                                <div class="mt-3 flex flex-wrap gap-2">
                                                    <?php foreach ($colorData['sizes'] as $size): ?>
                                                        <span class="px-3 py-1 rounded-full bg-white border border-gray-200 text-xs font-medium text-gray-600 shadow-sm">
                                                            <?php echo htmlspecialchars($size, ENT_QUOTES, 'UTF-8'); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </details>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="noResults" class="hidden text-center py-16 text-gray-500">
                            <i data-feather="filter" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">نتیجه‌ای برای فیلترهای انتخابی یافت نشد</h3>
                            <p class="mb-6">برای مشاهده نتایج، فیلترها را تغییر دهید یا ریست کنید.</p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                        <div class="text-center py-16 text-gray-500">
                            <i data-feather="check-circle" class="w-16 h-16 mx-auto mb-4 opacity-50"></i>
                            <h3 class="text-lg font-medium text-gray-900 mb-2">هیچ محصولی تمام نشده</h3>
                            <p class="mb-6">تمام محصولات موجودی دارند</p>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script>
        feather.replace();

        const productCards = Array.from(document.querySelectorAll('[data-product-card]'));
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        const resetFiltersBtn = document.getElementById('resetFilters');
        const expandAllBtn = document.getElementById('expandAll');
        const collapseAllBtn = document.getElementById('collapseAll');
        const noResults = document.getElementById('noResults');
        const productsContainer = document.getElementById('productsContainer');
        const exportButton = document.getElementById('exportCsv');

        function filterProducts() {
            const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const selectedCategory = categoryFilter ? categoryFilter.value : '';
            let visibleCount = 0;

            productCards.forEach((card) => {
                const model = card.dataset.model || '';
                const category = card.dataset.category || '';
                const colors = card.dataset.colors || '';
                const sizes = card.dataset.sizes || '';
                const haystack = `${model} ${category} ${colors} ${sizes}`;

                const matchesSearch = haystack.includes(searchTerm);
                const matchesCategory = selectedCategory === '' || category === selectedCategory;

                if (matchesSearch && matchesCategory) {
                    card.classList.remove('hidden');
                    visibleCount++;
                } else {
                    card.classList.add('hidden');
                }
            });

            if (noResults && productsContainer) {
                if (visibleCount === 0) {
                    noResults.classList.remove('hidden');
                    productsContainer.classList.add('hidden');
                } else {
                    noResults.classList.add('hidden');
                    productsContainer.classList.remove('hidden');
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', filterProducts);
        }

        if (categoryFilter) {
            categoryFilter.addEventListener('change', filterProducts);
        }

        if (resetFiltersBtn) {
            resetFiltersBtn.addEventListener('click', () => {
                if (searchInput) {
                    searchInput.value = '';
                }
                if (categoryFilter) {
                    categoryFilter.value = '';
                }
                filterProducts();
            });
        }

        if (expandAllBtn) {
            expandAllBtn.addEventListener('click', () => {
                document.querySelectorAll('#productsContainer details').forEach((details) => {
                    details.open = true;
                });
            });
        }

        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', () => {
                document.querySelectorAll('#productsContainer details').forEach((details) => {
                    details.open = false;
                });
            });
        }

        document.querySelectorAll('[data-copy-button]').forEach((button) => {
            button.addEventListener('click', async () => {
                const text = button.getAttribute('data-copy-text') || '';
                const feedback = button.parentElement?.querySelector('[data-copy-feedback]');
                try {
                    await navigator.clipboard.writeText(text);
                    if (feedback) {
                        feedback.classList.remove('hidden');
                        setTimeout(() => feedback.classList.add('hidden'), 2000);
                    }
                } catch (error) {
                    alert('امکان کپی جزئیات فراهم نشد.');
                }
            });
        });

        if (exportButton) {
            exportButton.addEventListener('click', () => {
                if (!productCards.length) {
                    return;
                }

                const rows = [['مدل', 'دسته‌بندی', 'رنگ', 'سایزهای ناموجود', 'تعداد سایزها']];
                productCards.forEach((card) => {
                    if (card.classList.contains('hidden')) {
                        return;
                    }
                    const payloadRaw = card.getAttribute('data-export');
                    if (!payloadRaw) {
                        return;
                    }
                    try {
                        const payload = JSON.parse(payloadRaw);
                        payload.colors.forEach((color) => {
                            rows.push([
                                payload.model,
                                payload.category,
                                color.color,
                                color.sizes.join('، '),
                                color.sizes.length.toString(),
                            ]);
                        });
                    } catch (error) {
                        console.error('خطا در آماده‌سازی خروجی', error);
                    }
                });

                if (rows.length <= 1) {
                    alert('موردی برای خروجی گرفتن وجود ندارد.');
                    return;
                }

                const csvContent = rows.map((row) => row.map((value) => `"${value.replace(/"/g, '""')}"`).join(',')).join('\n');
                const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'out-of-stock-report.csv';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                URL.revokeObjectURL(url);
            });
        }

        setTimeout(() => {
            const successMessage = document.getElementById('successMessage');
            const errorMessage = document.getElementById('errorMessage');
            if (successMessage) successMessage.style.display = 'none';
            if (errorMessage) errorMessage.style.display = 'none';
        }, 5000);
    </script>
</body>
</html>
