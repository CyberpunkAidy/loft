<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An attendee reserved into a session (or waiting for a seat).
 */
#[Fillable([
    'studio_id', 'class_session_id', 'attendee_id', 'user_id', 'entitlement_id', 'status', 'position',
    'credit_consumed', 'source', 'booked_at', 'cancelled_at', 'attended_at', 'apparatus_id', 'apparatus_item', 'online_spot',
])]
class Booking extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'booked', 'credit_consumed' => false, 'source' => 'web', 'online_spot' => false];

    /** Statuses that occupy a seat. */
    public const SEAT_STATUSES = ['booked', 'attended', 'no_show'];

    /** Statuses a member can still cancel. */
    public const ACTIVE_STATUSES = ['booked', 'waitlisted'];

    /** Statuses that count toward a membership's weekly limit. */
    public const WEEKLY_USAGE_STATUSES = ['booked', 'attended', 'no_show', 'late_cancelled'];

    public const STATUS_LABELS = [
        'booked' => 'Booked',
        'waitlisted' => 'Waitlist',
        'cancelled' => 'Cancelled',
        'late_cancelled' => 'Late cancel',
        'no_show' => 'No-show',
        'attended' => 'Attended',
    ];

    protected function casts(): array
    {
        return [
            'online_spot' => 'boolean',
            'credit_consumed' => 'boolean',
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'attended_at' => 'datetime',
            'position' => 'integer',
            'apparatus_item' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    public function apparatus(): BelongsTo
    {
        return $this->belongsTo(Apparatus::class);
    }

    /** "Silks 3" (booked), "Silks" (waiting for one), or null. */
    public function apparatusLabel(): ?string
    {
        return $this->apparatus?->label($this->status === 'waitlisted' ? null : $this->apparatus_item);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function canCancel(): bool
    {
        return $this->isActive() && $this->classSession->starts_at->isFuture();
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    /** Takes part online: the class is online, or they took a hybrid class's online spot. */
    public function attendsOnline(): bool
    {
        return $this->online_spot || $this->classSession?->isOnline();
    }
}
