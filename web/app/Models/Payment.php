<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A payment staff recorded by hand (or a comp), and the plan it granted. See OfflinePayments. */
#[Fillable([
    'studio_id', 'user_id', 'plan_id', 'entitlement_id', 'method', 'amount_cents', 'currency', 'paid_on', 'note',
    'recorded_by_user_id', 'voided_at', 'voided_by_user_id', 'order_id',
])]
class Payment extends Model
{
    public const METHODS = [
        'comp' => 'No charge (comp)',
        'cash' => 'Cash',
        'check' => 'Check',
        'card' => 'Card, in person',
        'transfer' => 'Bank transfer',
        'app' => 'Payment app (Venmo, Zelle…)',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'paid_on' => 'date',
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

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    public function isComp(): bool
    {
        return $this->method === 'comp';
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function amountLabel(): string
    {
        return Money::format($this->amount_cents, $this->currency);
    }
}
