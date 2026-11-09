<?php

namespace App\Http\Resources\Api;

use App\Models\Video;
use Illuminate\Http\Request;

/**
 * A video in the library. The controller sets thumbnail_url and, for the member, locked_reason
 * (sign_in, not_a_member, needs_plan; null when they can watch).
 *
 * @mixin Video
 */
class VideoResource extends ApiResource
{
    public ?string $thumbnailUrl = null;

    public ?string $lockedReason = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'duration_seconds' => $this->duration_seconds,
            'duration_label' => $this->durationLabel(),
            'aspect_ratio' => $this->aspect_ratio,
            'access' => $this->access,
            'thumbnail_url' => $this->thumbnailUrl,
            'published_at' => self::utc($this->published_at),
            'can_watch' => $this->lockedReason === null,
            'locked_reason' => $this->lockedReason,
        ];
    }
}
