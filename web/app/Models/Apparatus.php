<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apparatus rigged in a room: how many (quantity) and the rig's limit of people on each (max_per_item).
 * A class's allocation (program_apparatus / session_apparatus) uses some of it, as a pivot with the
 * same two columns.
 */
#[Fillable(['studio_id', 'room_id', 'name', 'quantity', 'max_per_item'])]
class Apparatus extends Model
{
    protected $table = 'apparatus';

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'max_per_item' => 'integer'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** "Silks 3" for an item, or just "Silks". */
    public function label(?int $item = null): string
    {
        return $item ? "{$this->name} {$item}" : $this->name;
    }

    /** "5 × up to 2 people" */
    public function summary(?int $quantity = null, ?int $perItem = null): string
    {
        $quantity ??= $this->quantity;
        $perItem ??= $this->max_per_item;

        return $quantity.' × '.($perItem === 1 ? '1 person' : "up to {$perItem} people");
    }
}
