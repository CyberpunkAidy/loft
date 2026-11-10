<?php

namespace App\Services\Imports;

/**
 * What an import would do, worked out from the file without changing anything: the records to
 * create ($items, which each importer shapes), rows it will skip and why, things it will change
 * on the way in (an unreadable birthday left blank), and counts for the preview.
 */
final class ImportPlan
{
    /** Skipped rows and warnings kept in a report: enough to fix a file, not a copy of it. */
    public const REPORT_LIMIT = 1000;

    /** @var list<array<string, mixed>> */
    public array $items = [];

    /** @var list<array{line: int, name: string, reason: string}> */
    public array $skipped = [];

    /** @var list<array{line: int, name: string, message: string}> */
    public array $warnings = [];

    /** @var array<string, int> */
    public array $counts = [];

    public function skip(int $line, string $name, string $reason): void
    {
        $this->skipped[] = ['line' => $line, 'name' => $name, 'reason' => $reason];
    }

    public function warn(int $line, string $name, string $message): void
    {
        $this->warnings[] = ['line' => $line, 'name' => $name, 'message' => $message];
    }

    /** @return array{skipped: list<array<string, mixed>>, warnings: list<array<string, mixed>>, more_skipped: int, more_warnings: int} */
    public function report(): array
    {
        return [
            'skipped' => array_slice($this->skipped, 0, self::REPORT_LIMIT),
            'warnings' => array_slice($this->warnings, 0, self::REPORT_LIMIT),
            'more_skipped' => max(0, count($this->skipped) - self::REPORT_LIMIT),
            'more_warnings' => max(0, count($this->warnings) - self::REPORT_LIMIT),
        ];
    }
}
