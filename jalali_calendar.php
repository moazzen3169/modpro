<?php
/**
 * Jalali (Persian) Calendar Functions
 * Provides conversion between Gregorian and Jalali dates
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

    $formatted = $format;
    $formatted = str_replace('Y', $jy, $formatted);
    $formatted = str_replace('y', substr($jy, -2), $formatted);
    $formatted = str_replace('m', str_pad($jm, 2, '0', STR_PAD_LEFT), $formatted);
    $formatted = str_replace('d', str_pad($jd, 2, '0', STR_PAD_LEFT), $formatted);
    $formatted = str_replace('M', $months[$jm], $formatted);
    $formatted = str_replace('F', $months[$jm], $formatted); // F is same as M for full name

    return $formatted;
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
 * Check if a Jalali date is valid
 *
 * @param int $jy Year
 * @param int $jm Month
 * @param int $jd Day
 * @return bool
 */
function is_valid_jalali_date($jy, $jm, $jd) {
    if ($jm < 1 || $jm > 12 || $jd < 1) return false;
    $days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, ($jy % 33 % 4 - 1 == ($jy % 33 % 4 > 1 ? 1 : 0) ? 30 : 29)];
    return $jd <= $days_in_month[$jm - 1];
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
    list($gy, $gm, $gd) = jalali_to_gregorian($jy, $jm, $jd);
    return mktime(0, 0, 0, $gm, $gd, $gy);
}
?>
