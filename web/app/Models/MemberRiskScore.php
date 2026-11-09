<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member's risk of leaving the studio as of one night, why, and which model said so (App\Services\ChurnScores). */
#[Fillable(['studio_id', 'user_id', 'member_snapshot_id', 'as_of', 'model_version', 'score', 'level', 'reasons', 'created_at'])]
class MemberRiskScore extends Model
{
    public const UPDATED_AT = null;

    public const LEVELS = [
        'high' => 'High risk',
        'medium' => 'Some risk',
        'low' => 'Low risk',
        'lapsed' => 'Lapsed',
    ];

    protected function casts(): array
    {
        return ['as_of' => 'date', 'reasons' => 'array', 'score' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function levelLabel(): string
    {
        return self::LEVELS[$this->level] ?? $this->level;
    }

    /** The badge class for the level. */
    public function badgeClass(): string
    {
        return match ($this->level) {
            'high' => 'badge-full',
            'medium' => 'badge-warn',
            'lapsed' => 'badge-muted',
            default => '',
        };
    }

    /** @return list<string> what pushed the score up, biggest first */
    public function concerns(): array
    {
        return array_values(array_map(fn ($r) => $r['label'], array_filter($this->reasons ?? [], fn ($r) => $r['points'] > 0)));
    }
}
