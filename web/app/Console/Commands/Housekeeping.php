<?php

namespace App\Console\Commands;

use App\Listeners\StudioEmails;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Services\Automations;
use App\Services\ChurnScores;
use App\Services\Demo\DemoStudios;
use App\Services\DomainEvents;
use App\Services\Imports\Imports;
use App\Services\MemberInvitations;
use App\Services\MemberSnapshots;
use App\Services\Messages;
use App\Services\ReaderPayments;
use App\Services\SeriesSignups;
use App\Services\Shop;
use App\Services\StudioBilling;
use App\Services\Videos;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hourly: class reminders, credit-expiry warnings, expiring entitlements, each studio's nightly member
 * snapshots (once its local time passes 2 AM) and churn scores from them, birthday, win-back and
 * churn-risk automations (from 10 AM),
 * settling online shop orders whose checkout has ended and card reader sales nobody finished, following
 * videos still on their way through Mux, catching up studios' Loft subscriptions with Stripe and
 * emailing owners whose trial is ending or whose studio is about to pause (decision 36), asking
 * members to carry on memberships imported from another system before they run out, clearing
 * import drafts nobody finished (decision 37), and relaying any domain events still waiting. Port of
 * the plugin's Cron, plus the event backbone's chores. Settling asks Stripe, so the cron service needs
 * STRIPE_SECRET too (render.yaml); following videos needs the Mux keys (the loft-shared group).
 * Run hourly by the loft-housekeeping cron job; each email has a dedupe key in message_deliveries,
 * so it goes out once however often this runs.
 */
#[AsCommand(name: 'loft:housekeeping', description: 'Send class reminders and credit warnings; expire credits; take member snapshots; relay events')]
class Housekeeping extends Command
{
    protected $signature = 'loft:housekeeping';

    public function handle(DomainEvents $events, MemberSnapshots $snapshots, ChurnScores $churn, Automations $automations, Shop $shop, SeriesSignups $series, ReaderPayments $readers, Videos $videos, StudioBilling $billing, MemberInvitations $invitations, Imports $imports, DemoStudios $demos): int
    {
        $reminders = 0;
        $snapshotted = 0;
        $automated = 0;
        $scored = 0;
        Studio::query()->chunkById(100, function ($studios) use (&$reminders, &$snapshotted, &$scored, &$automated, $snapshots, $churn, $automations) {
            foreach ($studios as $studio) {
                $reminders += $this->reminders($studio);
                $snapshotted += $snapshots->takeIfDue($studio);
                $scored += $churn->scoreLatest($studio);
                $automated += $automations->runScheduled($studio);
            }
        });
        $expired = $this->expire($events);
        $warnings = $this->creditWarnings();
        $shopOrders = $shop->sweep();
        $seriesSignups = $series->sweep();
        $readerSales = $readers->sweep();
        $videosDone = $videos->sweep();
        $subscriptions = $billing->sweep();
        $billingEmails = $billing->remind();
        $renewals = $invitations->remindRenewals();
        $pruned = $imports->prune();
        $demosRemoved = $demos->cleanup();
        $demosBuilt = $demos->refill();
        $relayed = $events->relay();

        $this->info("Reminders: {$reminders}. Expired: {$expired}. Credit warnings: {$warnings}. Snapshots: {$snapshotted}. Risk scores: {$scored}. Automated emails: {$automated}. Shop orders settled: {$shopOrders}. Series sign-ups settled: {$seriesSignups}. Reader sales settled: {$readerSales}. Videos finished: {$videosDone}. Subscriptions synced: {$subscriptions}. Billing emails: {$billingEmails}. Renewal reminders: {$renewals}. Import drafts cleared: {$pruned}. Events relayed: {$relayed}. Demo studios removed: {$demosRemoved}. Spare demos built: {$demosBuilt}.");

        return self::SUCCESS;
    }

    /** Booked attendees of sessions starting in the hour ending reminder_hours_before from now. */
    private function reminders(Studio $studio): int
    {
        $hours = (int) $studio->setting('reminder_hours_before');
        if ($hours <= 0) {
            return 0;
        }
        $from = now()->addHours($hours)->subHour();
        $to = now()->addHours($hours)->addMinutes(5);

        $sent = 0;
        $studio->bookings()
            ->where('status', 'booked')
            ->whereHas('classSession', fn ($q) => $q->where('status', 'scheduled')->where('starts_at', '>=', $from)->where('starts_at', '<', $to))
            ->with(['classSession.program', 'attendee', 'user', 'studio'])
            ->each(function (Booking $b) use (&$sent) {
                if (app(Messages::class)->email($b->user, StudioEmails::reminder($b), "reminder:booking:{$b->id}")) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Credits past their expiry, and comped memberships past their end date (Stripe-billed
     * memberships end when Stripe says so, via customer.subscription.deleted).
     */
    private function expire(DomainEvents $events): int
    {
        $expired = 0;
        Entitlement::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where(fn ($q) => $q->where('kind', 'credits')->orWhereNull('stripe_subscription_id'))
            ->with('plan')
            ->chunkById(200, function ($entitlements) use ($events, &$expired) {
                foreach ($entitlements as $entitlement) {
                    DB::transaction(function () use ($entitlement, $events) {
                        $entitlement->update(['status' => 'expired']);
                        $events->forEntitlement('entitlement.ended', $entitlement, ['reason' => 'expired']);
                    });
                    $expired++;
                }
            });

        return $expired;
    }

    private function creditWarnings(): int
    {
        $sent = 0;
        Entitlement::query()
            ->where('kind', 'credits')
            ->where('status', 'active')
            ->where('credits_remaining', '>', 0)
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->with(['studio', 'user'])
            ->each(function (Entitlement $e) use (&$sent) {
                if (app(Messages::class)->email($e->user, StudioEmails::creditsExpiring($e), "credits_expiring:entitlement:{$e->id}")) {
                    $sent++;
                }
            });

        return $sent;
    }
}
