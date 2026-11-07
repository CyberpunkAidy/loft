<?php

namespace App\Events;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone joined a studio as a member. (loft_member_registered) */
class MemberJoined
{
    use Dispatchable;

    public function __construct(public readonly Studio $studio, public readonly User $user) {}
}
