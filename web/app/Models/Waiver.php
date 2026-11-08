<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A liability waiver. It applies to every class (scope "studio") or only to the programs it's attached
 * to (scope "program"), and several can be in force at once. Each published version's text is kept
 * in waiver_versions; publishing a new version makes everyone sign again. The body is plain text;
 * blank lines separate paragraphs.
 */
#[Fillable(['studio_id', 'title', 'body', 'version', 'is_active', 'scope', 'validity_days'])]
class Waiver extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['version' => 1, 'is_active' => false, 'scope' => 'studio'];

    public const SCOPES = ['studio' => 'Every class', 'program' => 'Only the classes I choose'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'version' => 'integer',
            'validity_days' => 'integer',
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

    public function versions(): HasMany
    {
        return $this->hasMany(WaiverVersion::class);
    }

    /** The programs a program-scoped waiver applies to. */
    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    public function appliesToEveryClass(): bool
    {
        return $this->scope !== 'program';
    }

    /** Whether attendees of $program (or, with none, of any class) need this waiver. */
    public function appliesTo(?Program $program): bool
    {
        return $this->appliesToEveryClass() || ($program !== null && $this->programs->contains('id', $program->id));
    }

    /** @return list<string> */
    public function paragraphs(): array
    {
        return array_values(array_filter(array_map('trim', preg_split("/\R\s*\R/", (string) $this->body) ?: [])));
    }
}
