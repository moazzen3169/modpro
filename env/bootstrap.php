<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../jalali_calendar.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if (!isset($_SESSION['flash_messages'])) {
    $_SESSION['flash_messages'] = [
        'success' => [],
        'error' => [],
    ];
}

/**
 * @param 'success'|'error' $type
 */
function add_flash_message(string $type, string $message): void
{
    if (!in_array($type, ['success', 'error'], true)) {
        $type = 'error';
    }

    $_SESSION['flash_messages'][$type][] = $message;
}

function get_flash_messages(): array
{
    $messages = $_SESSION['flash_messages'] ?? ['success' => [], 'error' => []];
    $_SESSION['flash_messages'] = ['success' => [], 'error' => []];

    return $messages;
}

function redirect_with_message(string $location, string $type, string $message): void
{
    add_flash_message($type, $message);
    header("Location: {$location}");
    exit();
}

function validate_date(string $value): string
{
    try {
        return jalali_to_gregorian_string($value);
    } catch (Throwable $e) {
        throw new InvalidArgumentException('تاریخ نامعتبر است.');
    }
}

function validate_int(mixed $value, int $min = 0): int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('مقدار عددی نامعتبر است.');
    }

    $int = (int) $value;
    if ($int < $min) {
        throw new InvalidArgumentException('مقدار عددی خارج از بازه مجاز است.');
    }

    return $int;
}

function validate_price(mixed $value): float
{
    if (is_string($value)) {
        $value = str_replace([',', ' '], '', $value);
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('قیمت وارد شده نامعتبر است.');
    }

    $price = (float) $value;
    if ($price <= 0) {
        throw new InvalidArgumentException('قیمت باید بزرگتر از صفر باشد.');
    }

    return round($price, 2);
}

function validate_enum(string $value, array $allowed): string
{
    if (!in_array($value, $allowed, true)) {
        throw new InvalidArgumentException('مقدار انتخابی نامعتبر است.');
    }

    return $value;
}

function normalize_error_message(Throwable $e): string
{
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
        return $e->getMessage();
    }

    return 'خطای غیرمنتظره‌ای رخ داد. لطفاً دوباره تلاش کنید.';
}
