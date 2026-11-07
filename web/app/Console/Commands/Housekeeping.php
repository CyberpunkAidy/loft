<?php

namespace App\Console\Commands;

use App\Listeners\StudioEmails;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\SentNotification;
use App\Models\Studio;
use Illuminate\Console\Attributes\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Hourly: class reminders, credit-expiry warnings, and expiring entitlements. Port of the plugin's Cron.
 * Scheduled in routes/console.php; each email is recorded in sent_notifications so it goes out once.
 */
#[AsCommand(name: 'loft:housekeeping', description: 'Send class reminders and credit warnings; expire credits')]
class Housekeeping extends Command
{
    protected $signature = 'loft:housekeeping';

    public function handle(): int
    {
        $reminders = 0;
        Studio::query()->chunkById(100, function ($studios) use (&$reminders) {
            foreach ($studios as $studio) {
                $reminders += $this->reminders($studio);
            }
        });
        $expired = $this->expire();
        $warnings = $this->creditWarnings();

        $this->info("Reminders: {$reminders}. Expired: {$expired}. Credit warnings: {$warnings}.");

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
                if (SentNotification::once('reminder', $b->id)) {
                    Mail::to($b->user->email, $b->user->name)->send(StudioEmails::reminder($b));
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Credits past their expiry, and comped memberships past their end date (Stripe-billed
     * memberships end when Stripe says so, via customer.subscription.deleted).
     */
    private function expire(): int
    {
        return Entitlement::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where(fn ($q) => $q->where('kind', 'credits')->orWhereNull('stripe_subscription_id'))
            ->update(['status' => 'expired', 'updated_at' => now()]);
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
                if (SentNotification::once('credits_expiring', $e->id)) {
                    Mail::to($e->user->email, $e->user->name)->send(StudioEmails::creditsExpiring($e));
                    $sent++;
                }
            });

        return $sent;
    }
}
