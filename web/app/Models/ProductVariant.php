<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One size or colour of a product, with its own stock (null = not tracked) and optionally its own price. */
#[Fillable(['studio_id', 'product_id', 'name', 'sku', 'price_cents', 'stock', 'active', 'sort'])]
class ProductVariant extends Model
{
    public const LOW_STOCK = 3;

    protected $attributes = ['active' => true, 'sort' => 0];

    protected function casts(): array
    {
        return ['price_cents' => 'integer', 'stock' => 'integer', 'active' => 'boolean', 'sort' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function price(): int
    {
        return $this->price_cents ?? $this->product->price_cents;
    }

    /** "Studio Tee · M", or just "Grip Aid" for a product with one unnamed variant. */
    public function label(): string
    {
        return $this->product->name.($this->name !== '' && $this->name !== 'One size' ? ' · '.$this->name : '');
    }

    public function isTracked(): bool
    {
        return $this->stock !== null;
    }

    public function has(int $quantity): bool
    {
        return $this->stock === null || $this->stock >= $quantity;
    }
}
