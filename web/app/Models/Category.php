<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Age groups and levels drive the schedule filters; plan tags decide which plans cover which programs.
 */
#[Fillable(['studio_id', 'type', 'name', 'slug'])]
class Category extends Model
{
    public const TYPES = [
        'age_group' => 'Age groups',
        'level' => 'Levels',
        'plan_tag' => 'Plan tags',
    ];

    /** Seeded into every new studio, like the plugin's default terms. */
    public const DEFAULTS = [
        'age_group' => ['Kids', 'Teens', 'Adults'],
        'level' => ['Recreational', 'Competitive', 'Technique', 'Open Gym'],
        'plan_tag' => ['recreational', 'competitive', 'events'],
    ];

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    /** Find or create categories of a type from a list of names. */
    public static function ensure(Studio $studio, string $type, array $names): array
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            $slug = Str::slug($name);
            if ($name === '' || $slug === '') {
                continue;
            }
            $ids[] = $studio->categories()->firstOrCreate(
                ['type' => $type, 'slug' => $slug],
                ['name' => $name],
            )->id;
        }

        return $ids;
    }

    public static function seedDefaults(Studio $studio): void
    {
        foreach (self::DEFAULTS as $type => $names) {
            self::ensure($studio, $type, $names);
        }
    }
}
