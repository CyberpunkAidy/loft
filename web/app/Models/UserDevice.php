<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An install of the mobile app, with its push notification token (sending pushes comes later). */
#[Fillable(['user_id', 'platform', 'token', 'name', 'app_version', 'last_seen_at'])]
class UserDevice extends Model
{
    public const PLATFORMS = ['ios', 'android'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
