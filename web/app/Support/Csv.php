<?php

namespace App\Support;

/** Writing CSV files that spreadsheet apps open safely. */
class Csv
{
    /** Stop spreadsheet apps from treating a value as a formula. */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** @param iterable<array<int, mixed>> $rows */
    public static function write($handle, iterable $rows): void
    {
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), $row));
        }
    }
}
