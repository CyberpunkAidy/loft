<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One sale in the ledger (App\Services\Orders): what was bought, how it was paid, and for how much.
 * Online orders come from Stripe (a Checkout or a paid membership invoice); in-person orders from
 * payments staff record. Numbered per studio.
 */
#[Fillable([
    'studio_id', 'number', 'user_id', 'channel', 'method', 'status', 'currency', 'subtotal_cents', 'discount_cents',
    'tax_cents', 'total_cents', 'stripe_checkout_session_id', 'stripe_invoice_id', 'stripe_payment_intent_id',
    'recorded_by_user_id', 'paid_at', 'voided_at', 'voided_by_user_id',
])]
class Order extends Model
{
    public const CHANNELS = ['online' => 'Online', 'in_person' => 'In person'];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'subtotal_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_cents' => 'integer',
            'total_cents' => 'integer',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
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

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** The in-person payment record behind it, if any. */
    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function totalLabel(): string
    {
        return Money::format($this->total_cents, $this->currency);
    }

    /** "Online (Stripe)", "Cash", "No charge (comp)"… */
    public function methodLabel(): string
    {
        return $this->method === 'stripe' ? 'Online (Stripe)' : (Payment::METHODS[$this->method] ?? $this->method);
    }

    /** "5-Class Pack", or "Seasonal Tuition + Registration fee" */
    public function itemsLabel(): string
    {
        return $this->items->pluck('description')->join(' + ') ?: '—';
    }

    /** A link to the payment in the studio's Stripe Dashboard, for online orders. */
    public function stripeUrl(): ?string
    {
        $account = $this->studio?->stripe_account_id;
        if (! $account) {
            return null;
        }

        return match (true) {
            (bool) $this->stripe_payment_intent_id => "https://dashboard.stripe.com/{$account}/payments/{$this->stripe_payment_intent_id}",
            (bool) $this->stripe_invoice_id => "https://dashboard.stripe.com/{$account}/invoices/{$this->stripe_invoice_id}",
            default => null,
        };
    }
}
