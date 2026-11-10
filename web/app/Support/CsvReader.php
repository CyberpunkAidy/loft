<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Reads the CSV exports other studio systems produce (and spreadsheets saved as CSV): UTF-8 with or
 * without a byte-order mark, UTF-16 from Excel's "Unicode text", or Windows-1252; separated by
 * commas, semicolons (European Excel) or tabs; with quoted fields that may span lines.
 */
final class CsvReader
{
    public const MAX_ROWS = 20000;

    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{headers: list<string>, rows: list<list<string>>} blank rows dropped; every row padded to the header's width
     *
     * @throws ImportException
     */
    public static function parse(string $content): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new ImportException('That file is larger than 10 MB. Split it into smaller files and import each one.');
        }
        $content = self::toUtf8($content);
        if (trim($content) === '') {
            throw new ImportException('That file is empty.');
        }
        if (str_contains(substr($content, 0, 1024), "\0") || preg_match('/^(PK\x03\x04|%PDF|\x{D0}\x{CF})/u', $content)) {
            throw new ImportException('That doesn’t look like a CSV file. In your old system or spreadsheet, export or “Save as” CSV.');
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);
        $delimiter = self::delimiter($content);

        $headers = null;
        $rows = [];
        while (($cells = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            $cells = array_map(fn ($cell) => trim((string) $cell), $cells);
            if (implode('', $cells) === '') {
                continue;
            }
            if ($headers === null) {
                $headers = $cells;

                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($stream);
                throw new ImportException('That file has more than '.number_format(self::MAX_ROWS).' rows. Split it into smaller files and import each one.');
            }
            $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, max(count($headers), 1));
        }
        fclose($stream);

        if (! $headers || ! $rows) {
            throw new ImportException('That file has no rows to import. The first row should be the column names, with one row per record below it.');
        }

        return ['headers' => array_values($headers), 'rows' => $rows];
    }

    /** Plain lowercase letters and digits only: "E-mail Address" → "emailaddress", "Prénom" → "prenom". */
    public static function normalize(string $header): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($header)));
    }

    /** The file as UTF-8 text with Unix line endings, whatever encoding it came in. */
    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        return str_replace(["\r\n", "\r"], "\n", $content);
    }

    /** Whichever of comma, semicolon or tab the first line uses most (outside quotes). */
    private static function delimiter(string $content): string
    {
        $line = strtok($content, "\n") ?: '';
        $line = (string) preg_replace('/"[^"]*"/', '', $line);
        $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }
}
