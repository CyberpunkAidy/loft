<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Something the studio sells besides classes (a T-shirt, grip aid, a water bottle), in one or more variants. */
#[Fillable(['studio_id', 'name', 'description', 'price_cents', 'taxable', 'active'])]
class Product extends Model
{
    protected $attributes = ['taxable' => true, 'active' => true];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'taxable' => 'boolean', 'active' => 'boolean'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort')->orderBy('id');
    }

    public function priceLabel(): string
    {
        $prices = $this->variants->where('active', true)->map->price()->unique()->sort()->values();

        return $prices->count() > 1
            ? Money::format($prices->first(), $this->studio->currency).' – '.Money::format($prices->last(), $this->studio->currency)
            : Money::format($prices->first() ?? $this->price_cents, $this->studio->currency);
    }

    /** Units in stock across tracked variants, or null when none are tracked. */
    public function stock(): ?int
    {
        $tracked = $this->variants->where('active', true)->whereNotNull('stock');

        return $tracked->isEmpty() ? null : (int) $tracked->sum('stock');
    }
}
