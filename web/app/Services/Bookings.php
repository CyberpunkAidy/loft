<?php

namespace App\Services;

use App\Events\BookingCancelled;
use App\Events\BookingCreated;
use App\Events\SessionBookingCancelled;
use App\Events\WaitlistPromoted;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Support\BookingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Booking rules. Every guard lives here; controllers, webhooks and staff screens all call in.
 * Port of the plugin's Services\Booking, plus row locking so two people can't take the last seat.
 */
class Bookings
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Waivers $waivers,
    ) {}

    /**
     * Book an attendee into a session, or waitlist them when it's full.
     *
     * $source 'web' is a member booking (waiver and booking-window rules apply); 'admin' is staff.
     * $preferred is used when it can pay for this session (e.g. the plan just bought at checkout).
     * $skipEntitlement books without using a membership or credit (staff comp).
     *
     * @throws BookingException
     */
    public function book(
        ClassSession $session,
        Attendee $attendee,
        int $userId,
        string $source = 'web',
        ?Entitlement $preferred = null,
        bool $skipEntitlement = false,
    ): Booking {
        $studio = $session->studio;

        if ((int) $attendee->user_id !== $userId || (int) $attendee->studio_id !== (int) $studio->id || $attendee->status !== 'active') {
            throw new BookingException('forbidden', 'That attendee is not on your account.');
        }
        $this->assertOpen($session, $source);
        if ($this->existing($session, $attendee)) {
            throw new BookingException('duplicate', "{$attendee->first_name} is already booked for this class.");
        }
        if ($source === 'web' && ! $this->waivers->isSigned($studio, $attendee)) {
            throw new BookingException('waiver', "{$attendee->first_name} needs a signed waiver before booking.", ['attendee' => $attendee]);
        }
        $this->assertAge($session, $attendee);

        $entitlement = null;
        if (! $skipEntitlement) {
            $entitlement = $this->pickEntitlement($studio, $userId, $session, $preferred);
            if (! $entitlement) {
                throw $this->needsPlan($studio, $session);
            }
        }

        [$booking, $waitlisted] = DB::transaction(function () use ($studio, $session, $attendee, $userId, $source, $entitlement) {
            $locked = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($this->existing($locked, $attendee)) {
                throw new BookingException('duplicate', "{$attendee->first_name} is already booked for this class.");
            }

            $full = $this->seatsTaken($locked) >= $locked->capacity;
            $creditConsumed = false;
            if (! $full && $entitlement?->isCredits() && ! ($creditConsumed = $this->entitlements->consumeCredit($entitlement))) {
                // The last credit went to another booking a moment ago; look again.
                $entitlement = $this->entitlements->usableFor($studio, $userId, $locked);
                if (! $entitlement || ($entitlement->isCredits() && ! ($creditConsumed = $this->entitlements->consumeCredit($entitlement)))) {
                    throw $this->needsPlan($studio, $locked);
                }
            }

            $booking = $studio->bookings()->create([
                'class_session_id' => $locked->id,
                'attendee_id' => $attendee->id,
                'user_id' => $userId,
                'entitlement_id' => $entitlement?->id,
                'status' => $full ? 'waitlisted' : 'booked',
                'position' => $full ? $this->nextWaitlistPosition($locked) : null,
                'credit_consumed' => $creditConsumed,
                'source' => $source,
                'booked_at' => now(),
            ]);

            return [$booking, $full];
        }, 3);

        BookingCreated::dispatch($booking, $waitlisted);

        return $booking;
    }

    /**
     * Cancel a booking. Staff cancels are never late. Returns the new status and whether a credit came back.
     *
     * @return array{status: string, credit_refunded: bool}
     *
     * @throws BookingException
     */
    public function cancel(Booking $booking, ?int $userId = null, bool $byAdmin = false): array
    {
        if (! $byAdmin && (int) $booking->user_id !== (int) $userId) {
            throw new BookingException('forbidden', 'That booking is not on your account.');
        }
        if (! $booking->isActive()) {
            throw new BookingException('invalid', 'This booking can no longer be cancelled.');
        }
        $session = $booking->classSession;
        if (! $byAdmin && $session->hasStarted()) {
            throw new BookingException('invalid', 'This class has already started.');
        }

        $wasBooked = $booking->status === 'booked';
        $late = ! $byAdmin && $wasBooked && $this->isLate($session);
        $studioKeepsCredit = $late && ! $session->studio->setting('late_cancel_keeps_credit');
        $refund = $booking->credit_consumed && $booking->entitlement_id && ! $studioKeepsCredit;

        DB::transaction(function () use ($booking, $late, $refund) {
            $booking->update([
                'status' => $late ? 'late_cancelled' : 'cancelled',
                'cancelled_at' => now(),
                'position' => null,
                'credit_consumed' => $refund ? false : $booking->credit_consumed,
            ]);
            if ($refund && $booking->entitlement) {
                $this->entitlements->refundCredit($booking->entitlement);
            }
        });

        BookingCancelled::dispatch($booking, $late);

        if ($wasBooked) {
            $this->promoteWaitlist($session);
        }

        return ['status' => $booking->status, 'credit_refunded' => (bool) $refund];
    }

    /**
     * Fill open seats from the waitlist, in order. Each promoted booking is re-checked: if its
     * membership lapsed or its credits ran out, another usable entitlement is tried, else it's dropped.
     *
     * @return Collection<int, Booking> the promoted bookings
     */
    public function promoteWaitlist(ClassSession $session): Collection
    {
        $studio = $session->studio;
        if (! $studio->setting('waitlist_auto_promote') || $session->isCancelled() || $session->hasStarted()) {
            return collect();
        }

        $promoted = DB::transaction(function () use ($studio, $session) {
            $locked = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $promoted = collect();

            while ($this->seatsTaken($locked) < $locked->capacity) {
                $next = $locked->bookings()->where('status', 'waitlisted')
                    ->orderBy('position')->orderBy('booked_at')->orderBy('id')->first();
                if (! $next) {
                    break;
                }

                $entitlement = $next->entitlement;
                $creditConsumed = false;
                if ($next->entitlement_id !== null) { // no entitlement = staff comp; promote as-is
                    if (! $entitlement || ! $this->entitlements->isUsable($entitlement, $locked)) {
                        $entitlement = $this->entitlements->usableFor($studio, (int) $next->user_id, $locked);
                    }
                    if ($entitlement?->isCredits() && ! ($creditConsumed = $this->entitlements->consumeCredit($entitlement))) {
                        $entitlement = null;
                    }
                    if (! $entitlement) {
                        $next->update(['status' => 'cancelled', 'cancelled_at' => now(), 'position' => null]);

                        continue;
                    }
                }

                $next->update([
                    'status' => 'booked',
                    'position' => null,
                    'source' => 'waitlist_promote',
                    'entitlement_id' => $entitlement?->id,
                    'credit_consumed' => $creditConsumed,
                ]);
                $promoted->push($next);
            }

            return $promoted;
        });

        $promoted->each(fn (Booking $b) => WaitlistPromoted::dispatch($b));

        return $promoted;
    }

    /**
     * Staff check-in: attended, no_show, or booked (undo). With a lenient no-show policy
     * (no_show_consumes_credit off), a no-show hands the credit back, and undoing takes it again.
     */
    public function mark(Booking $booking, string $status): bool
    {
        if (! in_array($status, ['attended', 'no_show', 'booked'], true) || ! in_array($booking->status, Booking::SEAT_STATUSES, true)) {
            return false;
        }
        $update = ['status' => $status, 'attended_at' => $status === 'attended' ? now() : null];

        $entitlement = $booking->entitlement;
        if (! $booking->studio->setting('no_show_consumes_credit') && $entitlement?->isCredits()) {
            if ($status === 'no_show' && $booking->credit_consumed) {
                $this->entitlements->refundCredit($entitlement);
                $update['credit_consumed'] = false;
            } elseif ($status !== 'no_show' && $booking->status === 'no_show' && ! $booking->credit_consumed) {
                $update['credit_consumed'] = $this->entitlements->consumeCredit($entitlement);
            }
        }
        $booking->update($update);

        return true;
    }

    /** Studio cancels a class: cancel every booking, return credits, notify members. Returns bookings affected. */
    public function cancelSession(ClassSession $session): int
    {
        $cancelled = DB::transaction(function () use ($session) {
            $session->update(['status' => 'cancelled']);
            $bookings = $session->bookings()->whereIn('status', Booking::ACTIVE_STATUSES)->get();
            foreach ($bookings as $booking) {
                if ($booking->credit_consumed && $booking->entitlement) {
                    $this->entitlements->refundCredit($booking->entitlement);
                }
                $booking->update(['status' => 'cancelled', 'cancelled_at' => now(), 'position' => null, 'credit_consumed' => false]);
            }

            return $bookings;
        });

        $cancelled->each(fn (Booking $b) => SessionBookingCancelled::dispatch($b));

        return $cancelled->count();
    }

    /* ------------------------------------------------------------------ Rules */

    public function isLate(ClassSession $session): bool
    {
        return $session->starts_at->copy()->subHours((int) $session->studio->setting('late_cancel_hours'))->isPast();
    }

    /** When members may start booking this session (null = any time). */
    public function opensAt(ClassSession $session): ?Carbon
    {
        $days = (int) $session->studio->setting('booking_opens_days_before');

        return $days > 0 ? $session->starts_at->copy()->subDays($days) : null;
    }

    /** @throws BookingException */
    public function assertOpen(ClassSession $session, string $source = 'web'): void
    {
        if ($session->isCancelled()) {
            throw new BookingException('cancelled', 'This class has been cancelled.');
        }
        if ($session->hasStarted()) {
            throw new BookingException('closed', 'This class has already started.');
        }
        $closes = (int) $session->studio->setting('booking_closes_minutes_before');
        if ($closes > 0 && $session->starts_at->copy()->subMinutes($closes)->isPast()) {
            throw new BookingException('closed', 'Booking for this class has closed.');
        }
        $opensAt = $this->opensAt($session);
        if ($source === 'web' && $opensAt && $opensAt->isFuture()) {
            $days = (int) $session->studio->setting('booking_opens_days_before');
            throw new BookingException('closed', "Booking opens {$days} days before class.");
        }
    }

    /** @throws BookingException */
    public function assertAge(ClassSession $session, Attendee $attendee): void
    {
        $program = $session->program;
        $age = $attendee->age($session->studio->local($session->starts_at));
        if ($age === null) {
            return;
        }
        if ($program->min_age !== null && $age < $program->min_age) {
            throw new BookingException('age', "This class is for ages {$program->min_age} and up.");
        }
        if ($program->max_age !== null && $age > $program->max_age) {
            throw new BookingException('age', "This class is for ages up to {$program->max_age}.");
        }
    }

    public function existing(ClassSession $session, Attendee $attendee): ?Booking
    {
        return $session->bookings()
            ->where('attendee_id', $attendee->id)
            ->whereIn('status', ['booked', 'waitlisted', 'attended', 'no_show'])
            ->first();
    }

    private function pickEntitlement(Studio $studio, int $userId, ClassSession $session, ?Entitlement $preferred): ?Entitlement
    {
        if ($preferred && (int) $preferred->user_id === $userId && $this->entitlements->isUsable($preferred, $session)) {
            return $preferred;
        }

        return $this->entitlements->usableFor($studio, $userId, $session);
    }

    private function needsPlan(Studio $studio, ClassSession $session): BookingException
    {
        return new BookingException(
            'needs_plan',
            'You need an active membership or class credits to book this class.',
            ['plans' => $this->entitlements->eligiblePlans($studio, $session->program), 'session' => $session],
        );
    }

    private function seatsTaken(ClassSession $session): int
    {
        return $session->bookings()->whereIn('status', Booking::SEAT_STATUSES)->count();
    }

    private function nextWaitlistPosition(ClassSession $session): int
    {
        return 1 + (int) $session->bookings()->where('status', 'waitlisted')->max('position');
    }
}
