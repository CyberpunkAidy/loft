<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/** A waitlisted booking got a seat. (loft_waitlist_promoted) */
class WaitlistPromoted
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
