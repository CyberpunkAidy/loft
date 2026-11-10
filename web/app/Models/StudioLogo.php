<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A studio's logo image, base64 (decision 41). Written only by App\Services\StudioLogos. */
#[Fillable(['studio_id', 'data'])]
class StudioLogo extends Model
{
    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }
}
