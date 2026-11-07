<?php

namespace App\Services\Stripe;

use App\Models\Booking;
use App\Models\Entitlement;

/** What fulfilling a Checkout Session produced: the entitlement, and the booking it was started for. */
final class CheckoutResult
{
    public function __construct(
        public readonly ?Entitlement $entitlement,
        public readonly bool $created = false,
        public readonly ?Booking $booking = null,
        public readonly ?string $bookingError = null,
    ) {}
}
