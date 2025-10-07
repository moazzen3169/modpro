<?php
/**
 * Jalali (Persian) Calendar Functions
 * Provides conversion between Gregorian and Jalali dates and helpers for
 * consistently working with formatted date strings across the application.
 */

/**
 * Convert Jalali date to Gregorian date
 *
 * @param int $jy Jalali year
 * @param int $jm Jalali month
 * @param int $jd Jalali day
 * @return array [year, month, day]
 */
function jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (((int)($jy / 33)) * 8) + ((int)((($jy % 33) + 3) / 4)) + $jd + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0, 31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13; $gm++) {
        $v = $sal_a[$gm];
        if ($gd <= $v) break;
        $gd -= $v;
    }
    return [$gy, $gm, $gd];
}

/**
 * Convert Gregorian date to Jalali date
 *
 * @param int $gy Gregorian year
 * @param int $gm Gregorian month
 * @param int $gd Gregorian day
 * @return array [year, month, day]
 */
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

/**
 * Format Jalali date
 *
 * @param int $jy Year
 * @param int $jm Month
 * @param int $jd Day
 * @param string $format Format string (Y for year, m for month, d for day, M for month name, F for full month name)
 * @return string Formatted date
 */
function format_jalali_date($jy, $jm, $jd, $format = 'Y/m/d') {
    $jy = (int) $jy;
    $jm = (int) $jm;
    $jd = (int) $jd;

    $months = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    ];

    $replacements = [
        'Y' => sprintf('%04d', $jy),
        'y' => substr(sprintf('%04d', $jy), -2),
        'm' => sprintf('%02d', $jm),
        'd' => sprintf('%02d', $jd),
        'M' => $months[$jm] ?? '',
        'F' => $months[$jm] ?? '',
    ];

    return strtr($format, $replacements);
}

/**
 * Get current Jalali date
 *
 * @return array [year, month, day]
 */
function get_current_jalali_date() {
    $now = getdate();
    return gregorian_to_jalali($now['year'], $now['mon'], $now['mday']);
}

/**
 * Get current Jalali date as formatted string (Y/m/d)
 */
function get_current_jalali_date_string(): string
{
    [$jy, $jm, $jd] = get_current_jalali_date();

    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/**
 * Check if a Jalali date is valid
 *
 * @param int $jy Year
 * @param int $jm Month
 * @param int $jd Day
 * @return bool
 */
function is_valid_jalali_date($jy, $jm, $jd) {
    $jy = (int) $jy;
    $jm = (int) $jm;
    $jd = (int) $jd;

    if ($jm < 1 || $jm > 12 || $jd < 1) {
        return false;
    }

    return $jd <= get_jalali_month_days($jy, $jm);
}

/**
 * Determine if provided Jalali year is leap year.
 */
function is_jalali_leap_year(int $jy): bool
{
    $jy = (int) $jy;
    $adjustedYear = $jy > 0 ? $jy : $jy - 1;
    $cycle = ($adjustedYear - 474) % 2820;
    if ($cycle < 0) {
        $cycle += 2820;
    }

    return ((($cycle + 474 + 38) * 682) % 2816) < 682;
}

/**
 * Get total number of days in a Jalali month.
 */
function get_jalali_month_days(int $jy, int $jm): int
{
    $jy = (int) $jy;
    $jm = (int) $jm;

    $days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, is_jalali_leap_year($jy) ? 30 : 29];

    if ($jm < 1 || $jm > 12) {
        throw new InvalidArgumentException('شماره ماه جلالی نامعتبر است.');
    }

    return $days_in_month[$jm - 1];
}

/**
 * Get Jalali month name
 *
 * @param int $month Month number (1-12)
 * @return string Month name
 */
function get_jalali_month_name($month) {
    $months = [
        1 => 'فروردین',
        2 => 'اردیبهشت',
        3 => 'خرداد',
        4 => 'تیر',
        5 => 'مرداد',
        6 => 'شهریور',
        7 => 'مهر',
        8 => 'آبان',
        9 => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند'
    ];
    return $months[$month] ?? '';
}

/**
 * Convert timestamp to Jalali date
 *
 * @param int $timestamp Unix timestamp
 * @return array [year, month, day]
 */
function timestamp_to_jalali($timestamp) {
    $date = getdate($timestamp);
    
    return gregorian_to_jalali($date['year'], $date['mon'], $date['mday']);
}

/**
 * Convert Jalali date to timestamp
 *
 * @param int $jy Year
 * @param int $jm Month
 * @param int $jd Day
 * @return int Unix timestamp
 */
function jalali_to_timestamp($jy, $jm, $jd) {
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);
    return mktime(0, 0, 0, $gm, $gd, $gy);
}

/**
 * Parse a Jalali date string (format Y/m/d) and return its components.
 *
 * @throws InvalidArgumentException
 */
function parse_jalali_date_string(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        throw new InvalidArgumentException('تاریخ نمی‌تواند خالی باشد.');
    }

    if (!preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/u', $value, $matches)) {
        throw new InvalidArgumentException('قالب تاریخ باید به صورت YYYY/MM/DD باشد.');
    }

    $jy = (int) $matches[1];
    $jm = (int) $matches[2];
    $jd = (int) $matches[3];

    if (!is_valid_jalali_date($jy, $jm, $jd)) {
        throw new InvalidArgumentException('تاریخ نامعتبر است.');
    }

    return [$jy, $jm, $jd];
}

/**
 * Convert a Jalali date string (Y/m/d) to Gregorian string (Y-m-d)
 */
function jalali_to_gregorian_string(string $jalaliDate): string
{
    [$jy, $jm, $jd] = parse_jalali_date_string($jalaliDate);
    [$gy, $gm, $gd] = jalali_to_gregorian($jy, $jm, $jd);

    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/**
 * Convert a Gregorian date string (Y-m-d) to Jalali string (Y/m/d)
 */
function gregorian_to_jalali_string(string $gregorianDate): string
{
    $gregorianDate = trim($gregorianDate);

    $date = DateTime::createFromFormat('Y-m-d', $gregorianDate);
    if (!$date || $date->format('Y-m-d') !== $gregorianDate) {
        throw new InvalidArgumentException('تاریخ نامعتبر است.');
    }

    [$jy, $jm, $jd] = gregorian_to_jalali((int) $date->format('Y'), (int) $date->format('m'), (int) $date->format('d'));

    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

/**
 * Convert a Gregorian date string (Y-m-d or Y-m-d H:i:s) to a Jalali
 * display string. Invalid inputs are returned unchanged.
 */
function convert_gregorian_to_jalali_for_display(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $datePart = substr($value, 0, 10);

    try {
        return gregorian_to_jalali_string($datePart);
    } catch (Throwable) {
        return $value;
    }
}

/**
 * Convert a Jalali formatted string to Unix timestamp (start of day).
 */
function jalali_string_to_timestamp(string $jalaliDate): int
{
    [$jy, $jm, $jd] = parse_jalali_date_string($jalaliDate);

    return jalali_to_timestamp($jy, $jm, $jd);
}
?>
