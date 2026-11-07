<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/** The studio cancelled a class, which cancelled this booking. (loft_session_cancelled_booking) */
class SessionBookingCancelled
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
