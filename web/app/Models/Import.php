<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One CSV upload from a studio's old system (decision 37): its members and families ("people"), or
 * their credits and memberships ("plans"). A draft until the owner checks the preview and imports
 * it; the file itself is kept only until then. Everything it creates points back here (import_id).
 */
#[Fillable([
    'studio_id', 'user_id', 'kind', 'status', 'file_name', 'csv', 'row_count', 'mapping', 'options',
    'summary', 'report', 'error', 'started_at', 'finished_at', 'undone_at',
])]
#[Hidden(['csv'])]
class Import extends Model
{
    public const KINDS = [
        'people' => 'Members and families',
        'plans' => 'Credits and memberships',
    ];

    public const STATUS_LABELS = [
        'draft' => 'Not imported yet',
        'queued' => 'Waiting to import',
        'running' => 'Importing',
        'done' => 'Imported',
        'failed' => 'Failed',
        'undone' => 'Undone',
    ];

    /** How long after importing it can be undone. */
    public const UNDO_DAYS = 30;

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'mapping' => 'array',
            'options' => 'array',
            'summary' => 'array',
            'report' => 'array',
            'row_count' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function inProgress(): bool
    {
        return in_array($this->status, ['queued', 'running'], true);
    }

    public function canUndo(): bool
    {
        return $this->status === 'done' && $this->finished_at && $this->finished_at->gt(now()->subDays(self::UNDO_DAYS));
    }

    /** A count from the summary. */
    public function tally(string $key): int
    {
        return (int) ($this->summary[$key] ?? 0);
    }
}
