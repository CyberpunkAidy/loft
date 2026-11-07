<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/** A booking was made; $waitlisted when the class was full. (Plugin hook: loft_booking_created.) */
class BookingCreated
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking, public readonly bool $waitlisted) {}
}
