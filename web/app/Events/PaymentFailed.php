<?php

namespace App\Events;

use App\Models\Entitlement;
use Illuminate\Foundation\Events\Dispatchable;

/** A membership renewal failed; the entitlement is now past_due. (loft_payment_failed) */
class PaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly Entitlement $entitlement) {}
}
