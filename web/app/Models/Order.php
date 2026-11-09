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
    'recorded_by_user_id', 'paid_at', 'voided_at', 'voided_by_user_id', 'walk_in', 'note',
    'fulfillment_status', 'picked_up_at', 'picked_up_by_user_id', 'card_reader_id',
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
            'walk_in' => 'boolean',
            'picked_up_at' => 'datetime',
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

    public function cardReader(): BelongsTo
    {
        return $this->belongsTo(CardReader::class);
    }

    /** Sent to a card reader, waiting for the customer's card. */
    public function awaitsReader(): bool
    {
        return $this->status === 'pending' && $this->method === 'reader';
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function awaitsPickup(): bool
    {
        return $this->status === 'paid' && $this->fulfillment_status === 'to_pick_up';
    }

    /** Sales: paid or voided. Pending (unpaid) and released online orders aren't sales. */
    public function scopeSales($query)
    {
        return $query->whereIn('status', ['paid', 'void']);
    }

    public function totalLabel(): string
    {
        return Money::format($this->total_cents, $this->currency);
    }

    /** "Online (Stripe)", "Cash", "No charge (comp)"… */
    public function methodLabel(): string
    {
        return match ($this->method) {
            'stripe' => 'Online (Stripe)',
            'reader' => 'Card reader',
            default => Payment::METHODS[$this->method] ?? $this->method,
        };
    }

    /** "5-Class Pack", or "Studio Tee · M ×2 + Grip Aid" */
    public function itemsLabel(): string
    {
        return $this->items->map(fn (OrderItem $i) => $i->description.($i->quantity > 1 ? ' ×'.$i->quantity : ''))->join(' + ') ?: '—';
    }

    /** Who bought it, for lists: their name, "Walk-in", or "Account deleted". */
    public function customerLabel(): string
    {
        return $this->user?->name ?? ($this->walk_in ? 'Walk-in' : 'Account deleted');
    }

    /** The note staff added (on the sale, or on the payment record for plans added from a member's page). */
    public function noteText(): ?string
    {
        return $this->note ?? $this->payment?->note;
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
