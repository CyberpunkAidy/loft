<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A named date range, e.g. "2026–27 Season" (Sept 1 → May 31). Recurring programs generate sessions inside it. */
#[Fillable(['studio_id', 'name', 'starts_on', 'ends_on', 'is_current'])]
class Season extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['is_current' => false];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }
}
