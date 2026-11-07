<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a studio sells. Memberships and seasonal tuition are Stripe subscriptions; packs and
 * drop-ins are one-time payments that grant credits.
 */
#[Fillable([
    'studio_id', 'name', 'description', 'kind', 'price_cents', 'currency', 'billing_interval', 'credits',
    'credit_validity_days', 'weekly_limit', 'season_id', 'registration_fee_cents', 'stripe_product_id',
    'stripe_price_id', 'stripe_fee_price_id', 'program_scope', 'active', 'sort',
])]
class Plan extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['active' => true, 'registration_fee_cents' => 0, 'sort' => 0, 'currency' => 'usd'];

    public const KINDS = [
        'subscription' => 'Monthly membership',
        'season' => 'Seasonal tuition',
        'pack' => 'Class pack',
        'dropin' => 'Drop-in',
    ];

    protected function casts(): array
    {
        return [
            'program_scope' => 'array',
            'active' => 'boolean',
            'price_cents' => 'integer',
            'credits' => 'integer',
            'credit_validity_days' => 'integer',
            'weekly_limit' => 'integer',
            'registration_fee_cents' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function isRecurring(): bool
    {
        return in_array($this->kind, ['subscription', 'season'], true);
    }

    public function isMembership(): bool
    {
        return $this->isRecurring();
    }

    public function coversAll(): bool
    {
        return $this->program_scope === null;
    }

    public function covers(Program $program): bool
    {
        return self::scopeCovers($this->program_scope, $program);
    }

    /** @param list<string>|null $scope plan tag slugs, or null for every program */
    public static function scopeCovers(?array $scope, Program $program): bool
    {
        return $scope === null || array_intersect($scope, $program->planTagSlugs()) !== [];
    }

    public function isStripeReady(): bool
    {
        return ! empty($this->stripe_price_id);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->currency).($this->isRecurring() ? '/mo' : '');
    }

    public function detailLabel(): string
    {
        return match ($this->kind) {
            'subscription' => $this->weekly_limit ? "Up to {$this->weekly_limit} classes a week" : 'Unlimited classes',
            'season' => trim(($this->season?->name ? 'Billed monthly through '.$this->season->ends_on->format('M j, Y') : 'Billed monthly')
                .($this->weekly_limit ? " · up to {$this->weekly_limit}/week" : '')
                .($this->registration_fee_cents ? ' · '.Money::format($this->registration_fee_cents, $this->currency).' registration fee' : '')),
            'pack' => $this->credits.' class credits'.($this->credit_validity_days ? " · valid {$this->credit_validity_days} days" : ''),
            'dropin' => 'One class',
            default => '',
        };
    }
}
