<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A liability waiver. Bumping the version makes everyone sign again. One waiver per studio is active.
 * The body is plain text; blank lines separate paragraphs.
 */
#[Fillable(['studio_id', 'title', 'body', 'version', 'is_active'])]
class Waiver extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['version' => 1, 'is_active' => false];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(WaiverSignature::class);
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\R\s*\R/", (string) $this->body) ?: [])));
    }
}
