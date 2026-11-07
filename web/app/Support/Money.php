<?php

namespace App\Support;

class Money
{
    private const SYMBOLS = ['usd' => '$', 'cad' => 'CA$', 'eur' => '€', 'gbp' => '£', 'aud' => 'A$', 'nzd' => 'NZ$'];

    public static function format(?int $cents, string $currency = 'usd'): string
    {
        $symbol = self::SYMBOLS[strtolower($currency)] ?? strtoupper($currency).' ';

        return $symbol.number_format(((int) $cents) / 100, 2);
    }

    /** "12.50" or "12" → 1250. Blank → null. */
    public static function toCents(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    public static function toAmount(?int $cents): string
    {
        return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
    }
}
