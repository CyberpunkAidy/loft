<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A room in the studio, with its fire-code limit and the apparatus rigged in it. */
#[Fillable(['studio_id', 'name', 'max_capacity'])]
class Room extends Model
{
    protected function casts(): array
    {
        return ['max_capacity' => 'integer'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function apparatus(): HasMany
    {
        return $this->hasMany(Apparatus::class)->orderBy('name');
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
