<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An on-demand video in a studio's library, hosted on Mux (App\Services\Videos). Its playback is
 * signed: members get short-lived tokens from App\Services\VideoLibrary.
 */
#[Fillable([
    'studio_id', 'title', 'description', 'access', 'published', 'published_at', 'source', 'status', 'error',
    'mux_upload_id', 'mux_asset_id', 'mux_playback_id', 'duration_seconds', 'aspect_ratio', 'checked_at', 'created_by_user_id',
])]
class Video extends Model
{
    /** Who can watch it. */
    public const ACCESS = [
        'members' => 'Everyone with an account',
        'plan' => 'Members whose plan includes videos',
    ];

    public const STATUSES = [
        'uploading' => 'Waiting for the file',
        'processing' => 'Processing',
        'ready' => 'Ready',
        'errored' => 'Didn’t work',
    ];

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['access' => 'members', 'published' => false, 'source' => 'upload', 'status' => 'uploading'];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'published_at' => 'datetime',
            'duration_seconds' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function views(): HasMany
    {
        return $this->hasMany(VideoView::class);
    }

    /** In the library: published and ready to play. */
    public function scopeInLibrary(Builder $query): Builder
    {
        return $query->where('published', true)->where('status', 'ready');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->mux_playback_id !== null;
    }

    /** Still on its way: Mux hasn't got the file, or is encoding it. */
    public function isPending(): bool
    {
        return in_array($this->status, ['uploading', 'processing'], true);
    }

    public function inLibrary(): bool
    {
        return $this->published && $this->isReady();
    }

    public function needsPlan(): bool
    {
        return $this->access === 'plan';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function accessLabel(): string
    {
        return self::ACCESS[$this->access] ?? $this->access;
    }

    /** "1:02:05" or "12:05". */
    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }
        $s = $this->duration_seconds;

        return $s >= 3600 ? sprintf('%d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60) : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    }

    /** The CSS aspect ratio ("16 / 9"), from Mux's "16:9". */
    public function cssAspectRatio(): string
    {
        return preg_match('/^(\d+):(\d+)$/', (string) $this->aspect_ratio, $m) ? "{$m[1]} / {$m[2]}" : '16 / 9';
    }
}
