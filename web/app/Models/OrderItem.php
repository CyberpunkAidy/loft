<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of an order: a plan, a product, a fee (a season's registration fee), or something else on a Stripe invoice. */
#[Fillable(['order_id', 'kind', 'plan_id', 'entitlement_id', 'product_variant_id', 'description', 'quantity', 'unit_price_cents', 'total_cents', 'tax_cents'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_cents' => 'integer', 'total_cents' => 'integer', 'tax_cents' => 'integer'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function entitlement(): BelongsTo
    {
        return $this->belongsTo(Entitlement::class);
    }
}
