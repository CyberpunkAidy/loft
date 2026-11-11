<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A code a new studio can join Loft with (decision 43): a studio's own (studio_id), or a partner's
 * (name), made with `loft:partner-code`. Shared as loftstudio.app/r/{code} or typed at signup.
 */
#[Fillable(['code', 'studio_id', 'name', 'disabled_at'])]
class ReferralCode extends Model
{
    protected function casts(): array
    {
        return ['disabled_at' => 'datetime'];
    }

    /** The studio whose code this is; null for a partner code. */
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** Studios that joined with it. */
    public function referredStudios(): HasMany
    {
        return $this->hasMany(Studio::class, 'referral_code_id');
    }

    public function isPartner(): bool
    {
        return $this->studio_id === null;
    }

    /** Who it belongs to, for the report. */
    public function owner(): string
    {
        return $this->studio?->name ?? $this->name ?? 'Partner';
    }

    public function url(): string
    {
        return route('referral', ['code' => $this->code]);
    }
}
