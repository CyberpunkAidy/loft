<?php

namespace App\Console\Commands;

use App\Models\Studio;
use App\Support\BillingState;
use App\Support\SignupSource;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Which ways of finding Loft bring studios that stay (decision 43): new studios by their answer to
 * "How did you hear about Loft?", by referral code, and one by one with where their visit began.
 * Run it in Render's shell: php artisan loft:signups (the last 30 days) or --days=90.
 */
#[AsCommand(name: 'loft:signups', description: 'New studios: how they heard about Loft, referral codes, and who has subscribed')]
class SignupsCommand extends Command
{
    protected $signature = 'loft:signups {--days=30 : How many days back}';

    public function handle(): int
    {
        $days = (string) $this->option('days');
        if (! ctype_digit($days) || (int) $days < 1) {
            $this->error('--days must be a whole number of days, 1 or more.');

            return self::FAILURE;
        }
        $studios = Studio::with('referralCode.studio')->where('created_at', '>=', now()->subDays((int) $days))->latest()->get();
        $this->info("Studios started in the last {$days} days: {$studios->count()}");
        if ($studios->isEmpty()) {
            return self::SUCCESS;
        }
        $status = fn (Studio $s) => $s->billing()->status;
        $subscribed = fn (Collection $group) => $group->filter(fn (Studio $s) => $status($s) === BillingState::SUBSCRIBED)->count();

        $this->newLine();
        $this->line('<comment>How they heard about Loft</comment>');
        $this->table(['Answer', 'Studios', 'Subscribed'], $studios->groupBy(fn (Studio $s) => $s->heard_from ?? '')
            ->sortByDesc(fn (Collection $group) => $group->count())
            ->map(fn (Collection $group, string $answer) => [SignupSource::HEARD_FROM[$answer] ?? 'Not asked', $group->count(), $subscribed($group)])
            ->values()->all());

        $referred = $studios->filter(fn (Studio $s) => $s->referralCode);
        if ($referred->isNotEmpty()) {
            $this->line('<comment>Referral codes</comment>');
            $this->table(['Code', 'Whose', 'Studios', 'Subscribed', 'Rewarded'], $referred->groupBy(fn (Studio $s) => $s->referralCode->code)
                ->map(fn (Collection $group, string $code) => [
                    $code, $group->first()->referralCode->owner(), $group->count(), $subscribed($group),
                    $group->whereNotNull('referral_rewarded_at')->count(),
                ])->values()->all());
        }

        $this->line('<comment>Each studio</comment>');
        $this->table(['Started', 'Studio', 'Heard from', 'Code', 'Visit began', 'Now'], $studios->map(fn (Studio $s) => [
            $s->created_at->format('M j'),
            $s->name.' ('.$s->slug.')',
            trim((SignupSource::HEARD_FROM[$s->heard_from ?? ''] ?? '').($s->heard_from_detail ? ': '.$s->heard_from_detail : '')),
            $s->referralCode?->code ?? '',
            SignupSource::describe($s->signup_source),
            $status($s),
        ])->all());

        return self::SUCCESS;
    }
}
