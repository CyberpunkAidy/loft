<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The exact text of one published version of a waiver. Never changed once someone signs it. */
#[Fillable(['studio_id', 'waiver_id', 'version', 'title', 'body', 'body_sha256'])]
class WaiverVersion extends Model
{
    protected function casts(): array
    {
        return ['version' => 'integer'];
    }

    public function waiver(): BelongsTo
    {
        return $this->belongsTo(Waiver::class);
    }

    public static function hash(string $body): string
    {
        return hash('sha256', $body);
    }
}
