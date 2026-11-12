<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * What an account holds: a live subscription (optionally limited per week) or a credit balance.
 * Created by Stripe webhooks or by staff (a recorded payment or a comp); used up by bookings.
 */
#[Fillable([
    'studio_id', 'user_id', 'plan_id', 'series_enrollment_id', 'kind', 'status', 'stripe_subscription_id', 'stripe_customer_id',
    'stripe_checkout_session_id', 'credits_total', 'credits_remaining', 'expires_at', 'current_period_end',
    'program_scope', 'weekly_limit', 'import_id',
])]
class Entitlement extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'active'];

    public const STATUS_LABELS = [
        'active' => 'Active',
        'past_due' => 'Payment failed',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ];

    protected function casts(): array
    {
        return [
            'program_scope' => 'array',
            'expires_at' => 'datetime',
            'current_period_end' => 'datetime',
            'credits_total' => 'integer',
            'credits_remaining' => 'integer',
            'weekly_limit' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** The recorded payment (or comp) behind it, when staff granted it by hand. */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** The series a make-up credit came from (decision 47). */
    public function seriesEnrollment(): BelongsTo
    {
        return $this->belongsTo(SeriesEnrollment::class);
    }

    public function isMakeup(): bool
    {
        return $this->series_enrollment_id !== null;
    }

    /** What to call it: the plan's name, or "Make-up class (Silks Level 1)". */
    public function name(): string
    {
        if ($this->isMakeup()) {
            $series = $this->seriesEnrollment?->series;

            return 'Make-up class'.($series ? " ({$series->name})" : '');
        }

        return $this->plan?->name ?? 'Plan';
    }

    public function isCredits(): bool
    {
        return $this->kind === 'credits';
    }

    public function covers(Program $program): bool
    {
        return Plan::scopeCovers($this->program_scope, $program);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Cancelled or expired: it no longer covers classes and won't again. */
    public function hasEnded(): bool
    {
        return in_array($this->status, ['cancelled', 'expired'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
