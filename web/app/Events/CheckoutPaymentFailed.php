<?php

namespace App\Events;

use App\Models\Plan;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** A delayed one-time payment (e.g. a bank debit) failed after checkout, so nothing was granted. */
class CheckoutPaymentFailed
{
    use Dispatchable;

    public function __construct(
        public readonly Studio $studio,
        public readonly User $user,
        public readonly ?Plan $plan,
        public readonly bool $hadBooking,
    ) {}
}
