<?php

namespace App\Events;

use App\Models\Entitlement;
use Illuminate\Foundation\Events\Dispatchable;

/** A purchase completed and granted a membership or credits. (loft_entitlement_granted) */
class EntitlementGranted
{
    use Dispatchable;

    public function __construct(public readonly Entitlement $entitlement) {}
}
