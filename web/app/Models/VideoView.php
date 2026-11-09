<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone started watching a video: one row per person, video and day. */
#[Fillable(['studio_id', 'video_id', 'user_id', 'viewed_on'])]
class VideoView extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['viewed_on' => 'date'];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
