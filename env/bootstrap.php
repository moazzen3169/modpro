<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/db.php';

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
    $date = DateTime::createFromFormat('Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('تاریخ نامعتبر است.');
    }

    return $value;
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
