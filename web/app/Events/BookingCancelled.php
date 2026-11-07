<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/** A member or staff cancelled a booking; $late when inside the late-cancel window. (loft_booking_cancelled) */
class BookingCancelled
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking, public readonly bool $late) {}
}
