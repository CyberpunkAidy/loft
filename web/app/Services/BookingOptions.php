<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Models\User;
use App\Models\Waiver;
use App\Support\BookingException;
use Illuminate\Support\Collection;

/**
 * What a member can do in one class, before they try: whether it takes bookings at all, and for each
 * person on their account, whether they're already in it, too young or old, or have a waiver to sign
 * first. The booking page and the API both show this; Bookings::book() enforces it.
 */
class BookingOptions
{
    public function __construct(
        private readonly Bookings $bookings,
        private readonly Waivers $waivers,
    ) {}

    /**
     * @return array{
     *     closed: ?string,
     *     closed_code: ?string,
     *     attendees: Collection<int, array{attendee: Attendee, booking: ?Booking, age_error: ?string, waiver: ?Waiver}>
     * }
     */
    public function for(Studio $studio, ClassSession $session, User $user): array
    {
        if (! isset($session->booked_count)) {
            $session->loadCount(ClassSession::seatCounts());
        }

        [$closed, $code] = [null, null];
        try {
            $this->bookings->assertOpen($session);
        } catch (BookingException $e) {
            [$closed, $code] = [$e->getMessage(), $e->reason];
        }
        if (! $closed && $session->isFull() && $session->waitlistIsFull() && ! ($session->isHybrid() && $session->onlineSpotsLeft() !== 0)) {
            [$closed, $code] = [$session->waitlist_capacity === 0 ? 'This class is full.' : 'This class and its waitlist are full.', 'full'];
        }

        $attendees = $studio->attendees()->where('user_id', $user->id)->where('status', 'active')
            ->orderByDesc('is_self')->orderBy('first_name')->get()
            ->map(function ($attendee) use ($studio, $session) {
                $ageError = null;
                try {
                    $this->bookings->assertAge($session, $attendee);
                } catch (BookingException $e) {
                    $ageError = $e->getMessage();
                }

                return [
                    'attendee' => $attendee,
                    'booking' => $this->bookings->existing($session, $attendee),
                    'age_error' => $ageError,
                    'waiver' => $this->waivers->nextUnsigned($studio, $attendee, $session->program),
                ];
            });

        return ['closed' => $closed, 'closed_code' => $code, 'attendees' => $attendees];
    }
}
