<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One person signed up for a series (decision 47). "pending" holds their spot while they pay;
 * "active" once paid, with a booking in each class; "withdrawn" (staff took them out, or refunded)
 * or "released" (the payment never came) after.
 */
#[Fillable(['studio_id', 'series_id', 'attendee_id', 'user_id', 'status', 'classes', 'price_cents', 'source', 'enrolled_at', 'withdrawn_at'])]
class SeriesEnrollment extends Model
{
    public const STATUS_LABELS = ['pending' => 'Paying', 'active' => 'Signed up', 'withdrawn' => 'Withdrawn', 'released' => 'Didn’t pay'];

    protected function casts(): array
    {
        return [
            'classes' => 'integer',
            'price_cents' => 'integer',
            'enrolled_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** The order line that paid for it. */
    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
