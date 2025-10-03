<?php
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value, string $empty_message): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new InvalidArgumentException($empty_message);
        }

        return $trimmed;
    }
}

if (!function_exists('validate_price')) {
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
}

if (!function_exists('ensure_product_variant')) {
    function ensure_product_variant(mysqli $conn, int $product_id, string $color, string $size, float $price): int
    {
        $color = trim($color);
        $size = trim($size);

        if ($color === '' || $size === '') {
            throw new InvalidArgumentException('اطلاعات رنگ یا سایز برای ایجاد تنوع جدید کافی نیست.');
        }

        $selectStmt = $conn->prepare('SELECT variant_id FROM Product_Variants WHERE product_id = ? AND color = ? AND size = ? FOR UPDATE');
        $selectStmt->bind_param('iss', $product_id, $color, $size);
        $selectStmt->execute();
        $existing = $selectStmt->get_result()->fetch_assoc();

        if ($existing) {
            $variant_id = (int) $existing['variant_id'];

            if ($price > 0) {
                $updatePriceStmt = $conn->prepare('UPDATE Product_Variants SET price = ? WHERE variant_id = ?');
                $updatePriceStmt->bind_param('di', $price, $variant_id);
                $updatePriceStmt->execute();
                $updatePriceStmt->close();
            }

            $selectStmt->close();
            return $variant_id;
        }

        $selectStmt->close();

        $insertStmt = $conn->prepare('INSERT INTO Product_Variants (product_id, color, size, price, stock) VALUES (?, ?, ?, ?, 0)');
        $insertStmt->bind_param('issd', $product_id, $color, $size, $price);
        $insertStmt->execute();
        $variant_id = (int) $conn->insert_id;
        $insertStmt->close();

        return $variant_id;
    }
}
