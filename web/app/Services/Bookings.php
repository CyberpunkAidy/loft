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
use App\Models\User;
use App\Support\ApparatusSlot;
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
     * $waiverOverride (staff only: ['staff' => User, 'reason' => string]) books someone who hasn't
     * signed every waiver the class needs, and records who decided that and why.
     * $apparatusId picks the apparatus when the class uses some; null takes whichever has the most
     * spots free. A waitlist entry remembers it, and is promoted only when that apparatus has a spot.
     *
     * @param  array{staff: User, reason: string}|null  $waiverOverride
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
        ?array $waiverOverride = null,
        ?int $apparatusId = null,
    ): Booking {
        $studio = $session->studio;

        if ((int) $attendee->user_id !== $userId || (int) $attendee->studio_id !== (int) $studio->id || $attendee->status !== 'active') {
            throw new BookingException('forbidden', 'That attendee is not on your account.');
        }
        $this->assertOpen($session, $source);
        if ($this->existing($session, $attendee)) {
            throw new BookingException('duplicate', "{$attendee->first_name} is already booked for this class.");
        }
        $unsigned = $this->waivers->nextUnsigned($studio, $attendee, $session->program);
        if ($unsigned && ($source === 'web' || $waiverOverride === null)) {
            throw new BookingException('waiver', "{$attendee->first_name} needs to sign “{$unsigned->title}” before booking.", ['attendee' => $attendee, 'waiver' => $unsigned]);
        }
        $this->assertAge($session, $attendee);
        if ($apparatusId !== null && ! $session->apparatusSlots()->has($apparatusId)) {
            throw new BookingException('apparatus', 'Choose apparatus this class uses.');
        }

        $entitlement = null;
        if (! $skipEntitlement) {
            $entitlement = $this->pickEntitlement($studio, $userId, $session, $preferred);
            if (! $entitlement) {
                throw $this->needsPlan($studio, $session);
            }
        }

        [$booking, $waitlisted] = DB::transaction(function () use ($studio, $session, $attendee, $userId, $source, $entitlement, $unsigned, $waiverOverride, $apparatusId) {
            $locked = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($this->existing($locked, $attendee)) {
                throw new BookingException('duplicate', "{$attendee->first_name} is already booked for this class.");
            }

            $slots = $locked->apparatusSlots(refresh: true);
            $slot = $this->pickSlot($slots, $apparatusId);
            $full = $this->seatsTaken($locked) >= $locked->capacity || ($slots->isNotEmpty() && (! $slot || $slot->isFull()));
            if ($full && $locked->waitlist_capacity !== null && $this->waitlistTaken($locked) >= $locked->waitlist_capacity) {
                throw new BookingException('waitlist_full', $locked->waitlist_capacity === 0
                    ? 'This class is full.'
                    : 'This class and its waitlist are full.');
            }
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
                // Booked: the spot taken. Waitlisted: the apparatus asked for (null = any).
                'apparatus_id' => $full ? ($apparatusId ?? ($slots->count() === 1 ? $slot->apparatus->id : null)) : $slot?->apparatus->id,
                'apparatus_item' => $slot && ! $full ? $this->nextFreeItem($locked, $slot) : null,
            ]);
            if ($unsigned && $waiverOverride) {
                $this->waivers->override($booking, $waiverOverride['staff'], $waiverOverride['reason']);
            }

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
     * When the class uses apparatus, an entry moves up only when its apparatus has a spot (entries
     * without one take whichever has the most free); the rest keep their place.
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

            $waiting = $locked->bookings()->with(['attendee', 'studio', 'classSession.program'])->where('status', 'waitlisted')
                ->orderBy('position')->orderBy('booked_at')->orderBy('id')->get();
            foreach ($waiting as $next) {
                if ($this->seatsTaken($locked) >= $locked->capacity) {
                    break;
                }
                $slots = $locked->apparatusSlots(refresh: true);
                if ($slots->isNotEmpty() && $slots->every(fn (ApparatusSlot $s) => $s->isFull())) {
                    break;
                }
                $slot = $this->pickSlot($slots, $next->apparatus_id);
                if ($slots->isNotEmpty() && $slot->isFull()) {
                    continue; // waits for a spot on the apparatus they chose
                }
                if (! $this->waivers->clears($next)) {
                    continue; // keeps their place until the waiver is signed
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
                    'apparatus_id' => $slot?->apparatus->id,
                    'apparatus_item' => $slot ? $this->nextFreeItem($locked, $slot) : null,
                ]);
                $promoted->push($next);
            }

            return $promoted;
        });

        $promoted->each(fn (Booking $b) => WaitlistPromoted::dispatch($b));

        return $promoted;
    }

    /**
     * Staff move a booking to another apparatus (or a waitlist entry to wait for another one).
     * A booked spot needs room on the new apparatus; the spot it leaves goes to the waitlist.
     *
     * @throws BookingException when the apparatus isn't used in the class or has no spot free
     */
    public function moveApparatus(Booking $booking, int $apparatusId): void
    {
        $session = $booking->classSession;
        if (! in_array($booking->status, [...Booking::SEAT_STATUSES, 'waitlisted'], true)) {
            throw new BookingException('invalid', 'This booking is no longer in the class.');
        }
        if ((int) $booking->apparatus_id === $apparatusId) {
            return;
        }

        $freed = DB::transaction(function () use ($booking, $session, $apparatusId) {
            $locked = ClassSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            $slot = $locked->apparatusSlots(refresh: true)->get($apparatusId);
            if (! $slot) {
                throw new BookingException('apparatus', 'That apparatus isn’t used in this class.');
            }
            if ($booking->status === 'waitlisted') {
                $booking->update(['apparatus_id' => $apparatusId, 'apparatus_item' => null]);

                return false;
            }
            if ($slot->isFull()) {
                throw new BookingException('apparatus_full', "Every spot on {$slot->apparatus->name} is taken.");
            }
            $booking->update(['apparatus_id' => $apparatusId, 'apparatus_item' => $this->nextFreeItem($locked, $slot)]);

            return true;
        });

        if ($freed) {
            $this->promoteWaitlist($session);
        }
    }

    /** After a waiver is signed: try to move the attendee off waitlists where a seat is free. */
    public function promoteAfterSigning(Attendee $attendee): void
    {
        $sessions = ClassSession::query()
            ->whereIn('id', Booking::where('attendee_id', $attendee->id)->where('status', 'waitlisted')->select('class_session_id'))
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->get();
        foreach ($sessions as $session) {
            $this->promoteWaitlist($session);
        }
    }

    /**
     * Staff check-in: attended, no_show, or booked (undo). With a lenient no-show policy
     * (no_show_consumes_credit off), a no-show hands the credit back, and undoing takes it again.
     * Checking someone in needs every waiver their class requires, or a staff override with a reason.
     *
     * @param  array{staff: User, reason: string}|null  $waiverOverride
     *
     * @throws BookingException when they haven't signed and there's no override
     */
    public function mark(Booking $booking, string $status, ?array $waiverOverride = null): bool
    {
        if (! in_array($status, ['attended', 'no_show', 'booked'], true) || ! in_array($booking->status, Booking::SEAT_STATUSES, true)) {
            return false;
        }
        if ($status === 'attended' && ! $this->waivers->clears($booking)) {
            if ($waiverOverride === null) {
                $name = $booking->attendee->first_name;
                throw new BookingException('waiver', "{$name} hasn’t signed the waiver this class needs. Have them sign it, or check them in with a reason.", ['attendee' => $booking->attendee]);
            }
            $this->waivers->override($booking, $waiverOverride['staff'], $waiverOverride['reason']);
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

    private function waitlistTaken(ClassSession $session): int
    {
        return $session->bookings()->where('status', 'waitlisted')->count();
    }

    /**
     * The apparatus a booking goes on: the one asked for, or (null, or no longer used in the class)
     * the one with the most spots free. Null when the class uses no apparatus.
     *
     * @param  Collection<int, ApparatusSlot>  $slots
     */
    private function pickSlot(Collection $slots, ?int $apparatusId): ?ApparatusSlot
    {
        if ($apparatusId !== null && $slots->has($apparatusId)) {
            return $slots->get($apparatusId);
        }

        return $slots->sortByDesc(fn (ApparatusSlot $s) => $s->left())->first();
    }

    /** The lowest-numbered item ("Silks 3") with room for one more, or null if none is clear. */
    private function nextFreeItem(ClassSession $session, ApparatusSlot $slot): ?int
    {
        $used = $session->bookings()->whereIn('status', Booking::SEAT_STATUSES)
            ->where('apparatus_id', $slot->apparatus->id)->whereNotNull('apparatus_item')
            ->selectRaw('apparatus_item, count(*) as n')->groupBy('apparatus_item')->pluck('n', 'apparatus_item');
        for ($item = 1; $item <= $slot->quantity; $item++) {
            if ((int) ($used[$item] ?? 0) < $slot->perItem) {
                return $item;
            }
        }

        return null;
    }

    private function nextWaitlistPosition(ClassSession $session): int
    {
        return 1 + (int) $session->bookings()->where('status', 'waitlisted')->max('position');
    }
}
