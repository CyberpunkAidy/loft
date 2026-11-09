<?php

namespace App\Console\Commands;

use App\Models\MemberSnapshot;
use App\Models\Studio;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Member snapshots as a CSV for training a churn model later (roadmap step 9: "train a model once
 * there's labelled history"). Members appear as pseudonyms (a keyed hash, the same in every export
 * from this app), never names, emails or IDs. The label, churned_30d, is 1 when the member came to
 * no class in the 30 days after the snapshot, 0 when they did, and empty when that isn't known yet.
 */
#[AsCommand(name: 'loft:churn-export', description: 'Export member snapshots with pseudonymous IDs and a churn label, for training a model')]
class ChurnExport extends Command
{
    protected $signature = 'loft:churn-export {studio : The studio’s web address (slug)} {--days=365 : How many days back} {--output= : A file to write (default: standard output)}';

    public const HORIZON_DAYS = 30;

    public const COLUMNS = ['member', 'as_of', 'days_since_visit', 'visits_28d', 'visits_prev_28d', 'misses_28d', 'upcoming_bookings',
        'has_membership', 'credits_remaining', 'failed_payments_90d', 'attendees', 'tenure_days', 'churned_30d'];

    public function handle(): int
    {
        $studio = Studio::where('slug', (string) $this->argument('studio'))->first();
        if (! $studio) {
            $this->error('No studio at that web address.');

            return self::FAILURE;
        }
        $output = $this->option('output');
        $handle = fopen($output ?: 'php://output', 'w');
        fputcsv($handle, self::COLUMNS);

        $since = now()->subDays(max(1, (int) $this->option('days')))->toDateString();
        $dates = MemberSnapshot::where('studio_id', $studio->id)->whereDate('as_of', '>=', $since)->distinct()->orderBy('as_of')->pluck('as_of')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->values();
        $rows = 0;
        foreach ($dates as $date) {
            $later = MemberSnapshot::where('studio_id', $studio->id)->whereDate('as_of', Carbon::parse($date)->addDays(self::HORIZON_DAYS)->toDateString())
                ->get(['user_id', 'days_since_visit'])->keyBy('user_id');
            foreach (MemberSnapshot::where('studio_id', $studio->id)->whereDate('as_of', $date)->orderBy('user_id')->cursor() as $s) {
                $then = $later->get($s->user_id);
                fputcsv($handle, [
                    $this->pseudonym($studio, $s->user_id), $date, $s->days_since_visit, $s->visits_28d, $s->visits_prev_28d, $s->misses_28d,
                    $s->upcoming_bookings, (int) $s->has_membership, $s->credits_remaining, $s->failed_payments_90d, $s->attendees, $s->tenure_days,
                    $then ? (int) ($then->days_since_visit === null || $then->days_since_visit >= self::HORIZON_DAYS) : '',
                ]);
                $rows++;
            }
        }
        fclose($handle);
        if ($output) {
            $this->info("Wrote {$rows} rows to {$output}.");
        }

        return self::SUCCESS;
    }

    /** The same member always gets the same pseudonym, and it can't be turned back into an ID without the app key. */
    private function pseudonym(Studio $studio, int $userId): string
    {
        return substr(hash_hmac('sha256', "{$studio->id}|{$userId}", hash_hmac('sha256', 'loft-churn-export', (string) config('app.key'))), 0, 16);
    }
}
