<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Support\BookingException;

/**
 * Live virtual classes (roadmap step 10): people booked into an online class, or into a hybrid
 * class's online spot, join through the studio's meeting link (Zoom, Meet…). The link is handed
 * out only to them, from JOIN_OPENS_MINUTES before class until it ends, and joining checks them in
 * (attendance counts for snapshots and churn scoring, as at the door).
 */
class OnlineClasses
{
    public function __construct(private readonly Bookings $bookings) {}

    /** Whether $booking can join right now. */
    public function joinable(Booking $booking): bool
    {
        return $this->check($booking) === null;
    }

    /**
     * The meeting link, if they can join now (and they're checked in); otherwise why not.
     *
     * @return array{url: ?string, reason: ?string, message: ?string}
     */
    public function join(Booking $booking): array
    {
        if ($reason = $this->check($booking)) {
            return ['url' => null, 'reason' => $reason, 'message' => $this->message($reason, $booking->classSession)];
        }
        if ($booking->status === 'booked') {
            try {
                $this->bookings->mark($booking, 'attended');
            } catch (BookingException) {
                // A waiver they now need: staff can sort that out; let them into the class meanwhile.
            }
        }

        return ['url' => $booking->classSession->meeting_url, 'reason' => null, 'message' => null];
    }

    private function check(Booking $booking): ?string
    {
        $session = $booking->classSession;

        return match (true) {
            ! $booking->attendsOnline() => 'not_online',
            ! in_array($booking->status, ['booked', 'attended'], true) || $session->isCancelled() => 'not_booked',
            $session->joinOpensAt()->isFuture() => 'not_open_yet',
            $session->ends_at->isPast() => 'ended',
            blank($session->meeting_url) => 'no_link',
            default => null,
        };
    }

    private function message(string $reason, ClassSession $session): string
    {
        return match ($reason) {
            'not_online' => 'That booking is for the studio, not online.',
            'not_booked' => 'That booking isn’t active, so there’s nothing to join.',
            'not_open_yet' => 'The link opens '.$session->studio->local($session->joinOpensAt())->format('g:i A, M j').', '.ClassSession::JOIN_OPENS_MINUTES.' minutes before class.',
            'ended' => 'That class has ended.',
            default => 'The studio hasn’t added the link for this class yet. Please contact them.',
        };
    }
}
