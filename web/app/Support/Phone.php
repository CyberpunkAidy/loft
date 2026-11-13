<?php

namespace App\Support;

/**
 * Phone numbers as people type them, made into E.164 (+13035550123) for texting and for matching
 * replies. Numbers without a country code are taken as US or Canadian, which is where Loft's
 * studios text from (decision 48); any other country needs its + code.
 */
final class Phone
{
    /** E.164 for $input, or null if it isn't a number we could text. */
    public static function e164(?string $input): ?string
    {
        $input = trim((string) $input);
        if ($input === '') {
            return null;
        }
        $input = (string) preg_replace('/\s*(?:ext\.?|extension|x|#)\s*\d+$/i', '', $input); // drop an extension
        if (preg_match('/[^0-9+\s().\-\/]/', $input)) {
            return null; // letters, or anything else that isn't a number
        }
        $digits = (string) preg_replace('/\D/', '', $input);
        $international = str_starts_with(ltrim($input), '+');
        if (! $international && str_starts_with($digits, '011')) {
            [$digits, $international] = [substr($digits, 3), true]; // dialled from the US: 011 then the country
        }

        if ($international) {
            if (str_starts_with($digits, '1')) {
                return self::nanp(substr($digits, 1));
            }

            return preg_match('/^[2-9]\d{6,13}$/', $digits) ? '+'.$digits : null;
        }
        if (strlen($digits) === 11 && $digits[0] === '1') {
            $digits = substr($digits, 1);
        }

        return self::nanp($digits);
    }

    /** "(303) 555-0123" for US and Canadian numbers; others as stored. */
    public static function display(?string $e164): string
    {
        if ($e164 === null || $e164 === '') {
            return '';
        }
        if (preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $e164, $m)) {
            return "({$m[1]}) {$m[2]}-{$m[3]}";
        }

        return $e164;
    }

    /** A 10-digit US or Canadian number: area code and exchange can't start with 0 or 1. */
    private static function nanp(string $digits): ?string
    {
        return preg_match('/^[2-9]\d{2}[2-9]\d{6}$/', $digits) ? '+1'.$digits : null;
    }
}
