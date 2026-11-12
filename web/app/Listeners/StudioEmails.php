<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\CheckoutPaymentFailed;
use App\Events\EntitlementGranted;
use App\Events\MemberJoined;
use App\Events\PaymentFailed;
use App\Events\SessionBookingCancelled;
use App\Events\WaitlistPromoted;
use App\Mail\StudioMessage;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Models\User;
use App\Services\Messages;
use Illuminate\Events\Dispatcher;

/**
 * The plugin's Emails class: one listener per event, same wording, studio-branded.
 * Each goes through App\Services\Messages under a dedupe key, so it's logged and sent once.
 */
class StudioEmails
{
    public function __construct(private readonly Messages $messages) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            BookingCreated::class => 'bookingCreated',
            BookingCancelled::class => 'bookingCancelled',
            WaitlistPromoted::class => 'waitlistPromoted',
            SessionBookingCancelled::class => 'sessionCancelled',
            EntitlementGranted::class => 'entitlementGranted',
            PaymentFailed::class => 'paymentFailed',
            CheckoutPaymentFailed::class => 'checkoutPaymentFailed',
            MemberJoined::class => 'welcome',
        ];
    }

    public function bookingCreated(BookingCreated $event): void
    {
        $b = $event->booking;
        if ($b->source === 'series') {
            return; // a series sign-up sends one email for all its classes (SeriesSignups)
        }
        $studio = $b->studio;
        if ($event->waitlisted) {
            $this->send($b->user, new StudioMessage($studio, "You're on the waitlist",
                ["That class is full, so we've added you to the waitlist (position #{$b->position}). We'll email you the moment a spot opens."],
                $this->details($b), [], route('studio.account', $studio), 'View your classes', 'waitlisted'), "waitlisted:booking:{$b->id}");

            return;
        }
        $hours = (int) $studio->setting('late_cancel_hours');
        $this->send($b->user, new StudioMessage($studio, "You're booked!", ['See you in class.'], $this->details($b),
            ["Need to cancel? Do it from your account at least {$hours} hours before class to keep your credit."],
            route('studio.account', $studio), 'View your classes', 'booked'), "booked:booking:{$b->id}");
    }

    public function bookingCancelled(BookingCancelled $event): void
    {
        $b = $event->booking;
        $line = $event->late ? 'Your booking was cancelled inside the late-cancellation window.' : 'Your booking has been cancelled.';
        $this->send($b->user, new StudioMessage($b->studio, 'Booking cancelled', [$line], $this->details($b), [], null, null, 'cancelled'), "cancelled:booking:{$b->id}");
    }

    public function waitlistPromoted(WaitlistPromoted $event): void
    {
        $b = $event->booking;
        $this->send($b->user, new StudioMessage($b->studio, "A spot opened up — you're in!",
            ["Good news: a spot opened and you've been moved off the waitlist."], $this->details($b),
            ['If you can no longer make it, please cancel from your account so someone else can have the spot.'],
            route('studio.account', $b->studio), 'View your classes', 'promoted'), "promoted:booking:{$b->id}");
    }

    public function sessionCancelled(SessionBookingCancelled $event): void
    {
        $b = $event->booking;
        $this->send($b->user, new StudioMessage($b->studio, 'Class cancelled',
            ["We're sorry — this class has been cancelled by the studio. Any credit used has been returned to your account."],
            $this->details($b), [], route('studio.schedule', $b->studio), 'Find another class', 'session_cancelled'), "session_cancelled:booking:{$b->id}");
    }

    public function entitlementGranted(EntitlementGranted $event): void
    {
        $e = $event->entitlement;
        $what = $e->isCredits() ? "{$e->credits_total} class ".($e->credits_total === 1 ? 'credit' : 'credits') : 'your '.($e->plan?->name ?? 'membership');
        // Paid at the front desk: the email doubles as a receipt.
        $payment = $e->payment;
        $receipt = $payment && ! $payment->isComp()
            ? ['Paid' => $payment->amountLabel().' · '.$payment->methodLabel(), 'Date' => $payment->paid_on->format('M j, Y')]
            : [];
        $this->send($e->user, new StudioMessage($e->studio, 'Thanks for your purchase',
            ["You now have {$what}. Head to the schedule to book your classes."], $receipt, [],
            route('studio.schedule', $e->studio), 'Book a class', 'purchase'), "purchase:entitlement:{$e->id}");
    }

    public function paymentFailed(PaymentFailed $event): void
    {
        $e = $event->entitlement;
        $this->send($e->user, new StudioMessage($e->studio, 'Payment failed — action needed',
            ["We couldn't process your latest membership payment. Please update your card from the billing page of your account. Bookings are paused until payment goes through."],
            [], [], route('studio.account.billing', $e->studio), 'Update your card', 'payment_failed'), "payment_failed:entitlement:{$e->id}:".now()->format('Y-m-d'));
    }

    public function checkoutPaymentFailed(CheckoutPaymentFailed $event): void
    {
        $what = $event->plan ? "for {$event->plan->name} " : '';
        $lines = ["Your payment {$what}didn't go through, so nothing was added to your account and you haven't been charged."];
        if ($event->hadBooking) {
            $lines[] = "The class you were booking wasn't booked either.";
        }
        $this->send($event->user, new StudioMessage($event->studio, "Your payment didn't go through", $lines, [],
            ['You can try again with a card or another payment method.'], route('studio.plans', $event->studio), 'Try again', 'checkout_failed'));
    }

    public function welcome(MemberJoined $event): void
    {
        $this->send($event->user, new StudioMessage($event->studio, 'Welcome!', [
            'Your account is ready. Next steps:',
            "1. Add family members who'll be attending (or yourself).",
            '2. Sign the waiver for each attendee.',
            '3. Pick a plan and book your first class.',
        ], [], [], route('studio.account.family', $event->studio), 'Set up your family', 'welcome'), "welcome:studio:{$event->studio->id}:user:{$event->user->id}");
    }

    /** Reminder (sent by the hourly housekeeping command). */
    public static function reminder(Booking $b): StudioMessage
    {
        return new StudioMessage($b->studio, 'Reminder: class coming up', ['Just a reminder about your upcoming class.'],
            self::details($b), [], route('studio.account', $b->studio), 'View your classes', 'reminder');
    }

    public static function creditsExpiring(Entitlement $e): StudioMessage
    {
        $date = $e->studio->local($e->expires_at)->format('M j');

        return new StudioMessage($e->studio, 'Your class credits expire soon',
            ["You have {$e->credits_remaining} ".($e->credits_remaining === 1 ? 'credit' : 'credits')." expiring on {$date}. Book a class to use them!"],
            [], [], route('studio.schedule', $e->studio), 'Book a class', 'credits_expiring');
    }

    /** @return array<string, string> */
    public static function details(Booking $b): array
    {
        $session = $b->classSession;
        $studio = $b->studio;
        $start = $studio->local($session->starts_at);
        $end = $studio->local($session->ends_at);

        return [
            'Class' => $session->program->title,
            'When' => $start->format('D, M j').' · '.$start->format('g:i').' – '.$end->format('g:i A'),
            'Attendee' => $b->attendee->name,
        ] + ($b->attendsOnline() ? ['Where' => 'Online. The link to join is in your account from '.ClassSession::JOIN_OPENS_MINUTES.' minutes before class.'] : []);
    }

    private function send(?User $user, StudioMessage $message, ?string $dedupeKey = null): void
    {
        $this->messages->email($user, $message, $dedupeKey);
    }
}
