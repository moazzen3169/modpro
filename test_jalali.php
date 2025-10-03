<?php
require_once 'jalali_calendar.php';

// Test conversions
echo "Testing Jalali Calendar Functions\n\n";

// Test Gregorian to Jalali
list($jy, $jm, $jd) = gregorian_to_jalali(2023, 10, 15);
echo "Gregorian 2023-10-15 -> Jalali: $jy/$jm/$jd\n";

// Test Jalali to Gregorian
list($gy, $gm, $gd) = jalali_to_gregorian(1402, 7, 23);
echo "Jalali 1402/7/23 -> Gregorian: $gy-$gm-$gd\n";

// Test current date
list($cy, $cm, $cd) = get_current_jalali_date();
echo "Current Jalali date: $cy/$cm/$cd\n";

// Test formatting
$formatted = format_jalali_date(1402, 7, 23, 'Y/m/d');
echo "Formatted: $formatted\n";

$formatted2 = format_jalali_date(1402, 7, 23, 'd M Y');
echo "Formatted: $formatted2\n";

// Test validity
$valid = is_valid_jalali_date(1402, 7, 23);
echo "Is 1402/7/23 valid? " . ($valid ? 'Yes' : 'No') . "\n";

$invalid = is_valid_jalali_date(1402, 7, 32);
echo "Is 1402/7/32 valid? " . ($invalid ? 'Yes' : 'No') . "\n";

// Test month name
$monthName = get_jalali_month_name(7);
echo "Month 7: $monthName\n";

// Test timestamp conversion
$timestamp = time();
list($ty, $tm, $td) = timestamp_to_jalali($timestamp);
echo "Timestamp to Jalali: $ty/$tm/$td\n";

$ts = jalali_to_timestamp(1402, 7, 23);
echo "Jalali to timestamp: $ts\n";

// Test leap year (Esfand has 29 days in leap years)
$leap = is_valid_jalali_date(1403, 12, 30); // 1403 is leap year
echo "Is 1403/12/30 valid (leap year)? " . ($leap ? 'Yes' : 'No') . "\n";

$notLeap = is_valid_jalali_date(1402, 12, 30); // 1402 is not leap
echo "Is 1402/12/30 valid (non-leap)? " . ($notLeap ? 'Yes' : 'No') . "\n";

// Test edge cases
$edge1 = is_valid_jalali_date(1402, 0, 1);
echo "Is 1402/0/1 valid? " . ($edge1 ? 'Yes' : 'No') . "\n";

$edge2 = is_valid_jalali_date(1402, 13, 1);
echo "Is 1402/13/1 valid? " . ($edge2 ? 'Yes' : 'No') . "\n";

$edge3 = is_valid_jalali_date(1402, 6, 32);
echo "Is 1402/6/32 valid? " . ($edge3 ? 'Yes' : 'No') . "\n";

// Test round trip conversion
list($jy2, $jm2, $jd2) = gregorian_to_jalali(2024, 3, 20);
list($gy2, $gm2, $gd2) = jalali_to_gregorian($jy2, $jm2, $jd2);
echo "Round trip 2024-3-20 -> $jy2/$jm2/$jd2 -> $gy2-$gm2-$gd2\n";

// Test more formats
$format3 = format_jalali_date(1402, 1, 1, 'y-m-d');
echo "Format y-m-d: $format3\n";

$format4 = format_jalali_date(1402, 12, 29, 'd F Y');
echo "Format d F Y: $format4\n";
?>
