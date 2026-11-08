<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's activity at a studio as of one night (App\Services\MemberSnapshots). Kept so churn
 * scoring can later look back at what was known on any given date.
 */
#[Fillable([
    'studio_id', 'user_id', 'as_of', 'days_since_visit', 'visits_28d', 'visits_prev_28d', 'misses_28d', 'upcoming_bookings',
    'has_membership', 'credits_remaining', 'failed_payments_90d', 'attendees', 'tenure_days', 'created_at',
])]
class MemberSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'as_of' => 'date',
            'has_membership' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
