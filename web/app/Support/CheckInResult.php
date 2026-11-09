<?php

namespace App\Support;

use App\Models\AccessEvent;
use App\Models\Attendee;
use App\Models\Booking;

/** What a kiosk shows after a scan: in or not, why (a stable code), and a message for the person. */
final class CheckInResult
{
    public function __construct(
        public readonly bool $granted,
        public readonly string $reason,
        public readonly string $message,
        public readonly ?Attendee $attendee,
        public readonly ?Booking $booking,
        public readonly AccessEvent $event,
    ) {}
}
