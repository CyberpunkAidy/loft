<?php

namespace App\Services\Imports;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Dates as other systems export them: 2017-03-04, 3/4/2017, 04.03.17, "Mar 4, 2017". Numeric
 * dates are read month first (US) or day first; the order is worked out from the file where it
 * can be (a 13 or more in the first place means day first), and staff can change it.
 */
final class Dates
{
    public const ORDERS = ['mdy' => 'Month/Day/Year (3/14/2017)', 'dmy' => 'Day/Month/Year (14/3/2017)'];

    /** The calendar date written in $value, or null if it isn't one. */
    public static function parse(string $value, string $order = 'mdy'): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // 2017-03-04, 2017/3/4, optionally with a time after it.
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})(?:[ T].*)?$/', $value, $m)) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        // 3/4/2017, 04.03.17, 3-4-2017, optionally with a time after it.
        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})(?:[ T].*)?$/', $value, $m)) {
            [$first, $second] = [(int) $m[1], (int) $m[2]];
            [$month, $day] = $order === 'dmy' ? [$second, $first] : [$first, $second];

            return self::make(self::year($m[3]), $month, $day);
        }
        // Spelled-out months: "Mar 4, 2017", "4 March 2017".
        if (preg_match('/[a-z]/i', $value) && preg_match('/\d/', $value)) {
            try {
                $date = CarbonImmutable::parse($value);

                return $date->year > 1900 ? $date->startOfDay() : null;
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * Which order a column's dates are in: day first if any first number is over 12, month first
     * if any second number is, otherwise $default.
     *
     * @param  iterable<string>  $values
     */
    public static function detectOrder(iterable $values, string $default = 'mdy'): string
    {
        foreach ($values as $value) {
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2}|\d{4})/', trim($value), $m)) {
                if ((int) $m[1] > 12) {
                    return 'dmy';
                }
                if ((int) $m[2] > 12) {
                    return 'mdy';
                }
            }
        }

        return $default;
    }

    private static function year(string $digits): int
    {
        if (strlen($digits) === 4) {
            return (int) $digits;
        }
        // Two digits: this century unless that would be in the future.
        $year = 2000 + (int) $digits;

        return $year > (int) date('Y') + 5 ? $year - 100 : $year;
    }

    private static function make(int $year, int $month, int $day): ?CarbonImmutable
    {
        if ($year < 1900 || $year > 2200 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
