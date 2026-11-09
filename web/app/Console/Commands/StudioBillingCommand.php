<?php

namespace App\Console\Commands;

use App\Models\Studio;
use App\Services\StudioBilling;
use App\Support\BillingState;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;

/**
 * For Loft's owner (there's no Loft-wide admin screen): see where a studio stands with its Loft
 * subscription, make it free (comp), end a comp, or set its trial. Run it in Render's shell:
 *
 *   php artisan loft:billing skyline                       where it stands
 *   php artisan loft:billing skyline --comp --note="Pilot"  free from now on
 *   php artisan loft:billing skyline --end-comp             pays from now on, after a fresh trial
 *   php artisan loft:billing skyline --trial-days=14        the trial ends 14 days from now
 */
#[AsCommand(name: 'loft:billing', description: 'Show or change a studio’s Loft subscription: comp it, end a comp, or set its trial')]
class StudioBillingCommand extends Command
{
    protected $signature = 'loft:billing
        {studio : The studio’s address (slug), as in loftstudio.app/<slug>}
        {--comp : Make the studio free from now on}
        {--note= : Why it’s comped, for the record}
        {--end-comp : End the comp; the studio gets a trial (--trial-days, default the usual trial) and then pays}
        {--trial-days= : Set the trial to end this many days from now (0 ends it now)}';

    public function handle(StudioBilling $billing): int
    {
        $studio = Studio::where('slug', (string) $this->argument('studio'))->first();
        if (! $studio) {
            $this->error('No studio at '.$this->argument('studio').'.');

            return self::FAILURE;
        }
        $days = $this->option('trial-days');
        if ($days !== null && (! ctype_digit((string) $days))) {
            $this->error('--trial-days must be a whole number of days.');

            return self::FAILURE;
        }
        if ($this->option('comp') && $this->option('end-comp')) {
            $this->error('Choose --comp or --end-comp, not both.');

            return self::FAILURE;
        }

        if ($this->option('comp')) {
            $billing->comp($studio, $this->option('note') ?: null);
            $this->info("{$studio->name} is free from now on.");
            if (in_array($studio->billing_status, [...StudioBilling::LIVE, ...StudioBilling::FAILING], true)) {
                $this->warn("It also has a live subscription ({$studio->billing_subscription_id}). Cancel it in the Stripe Dashboard if it shouldn’t be charged.");
            }
        } elseif ($this->option('end-comp')) {
            $billing->endComp($studio, $days !== null ? (int) $days : $billing->trialDays());
            $this->info("{$studio->name} is no longer free.");
        } elseif ($days !== null) {
            $billing->setTrial($studio, (int) $days);
            $this->info("{$studio->name}’s trial now ends ".$studio->local($studio->trial_ends_at)->format('M j, Y g:i A').'.');
        }

        $this->line($this->describe($studio->refresh(), $billing->state($studio)));

        return self::SUCCESS;
    }

    private function describe(Studio $studio, BillingState $state): string
    {
        $date = fn ($at) => $at ? $studio->local($at)->format('M j, Y') : '?';

        return "{$studio->name} ({$studio->slug}): ".match ($state->status) {
            BillingState::COMPED => 'comped'.($studio->comp_note ? " ({$studio->comp_note})" : '').'.',
            BillingState::SUBSCRIBED => 'subscribed'.($state->inStripeTrial ? ', first payment '.$date($state->periodEndsAt) : ', paid through '.$date($state->periodEndsAt))
                .($state->cancelsAt ? ', cancels '.$date($state->cancelsAt) : '').'.',
            BillingState::TRIAL => 'in the free trial until '.$date($state->trialEndsAt).'.',
            BillingState::GRACE => 'in grace ('.str_replace('_', ' ', (string) $state->reason).'), pauses '.$date($state->pausesAt).'.',
            default => 'paused since '.$date($state->pausesAt).' ('.str_replace('_', ' ', (string) $state->reason).').',
        };
    }
}
