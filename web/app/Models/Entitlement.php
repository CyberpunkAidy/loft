<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an account holds: a live subscription (optionally limited per week) or a credit balance.
 * Created by Stripe webhooks or by staff comping a plan; used up by bookings.
 */
#[Fillable([
    'studio_id', 'user_id', 'plan_id', 'kind', 'status', 'stripe_subscription_id', 'stripe_customer_id',
    'stripe_checkout_session_id', 'credits_total', 'credits_remaining', 'expires_at', 'current_period_end',
    'program_scope', 'weekly_limit',
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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
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

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
