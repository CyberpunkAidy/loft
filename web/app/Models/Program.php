<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A class offering (recurring weekly in a season) or a one-off event. Sessions are generated from it.
 */
#[Fillable([
    'studio_id', 'title', 'excerpt', 'description', 'type', 'season_id', 'weekday', 'start_time', 'duration_min',
    'event_date', 'capacity', 'dropin_price_cents', 'instructor_id', 'min_age', 'max_age', 'members_only', 'is_published',
    'room_id', 'waitlist_capacity', 'delivery', 'online_capacity', 'meeting_url',
])]
class Program extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['type' => 'class', 'is_published' => true, 'members_only' => false, 'capacity' => 10, 'duration_min' => 60, 'delivery' => 'in_person'];

    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 0 => 'Sunday'];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'members_only' => 'boolean',
            'is_published' => 'boolean',
            'weekday' => 'integer',
            'capacity' => 'integer',
            'duration_min' => 'integer',
            'min_age' => 'integer',
            'max_age' => 'integer',
            'dropin_price_cents' => 'integer',
            'waitlist_capacity' => 'integer',
            'online_capacity' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** Waivers attached to this program (on top of the studio-wide ones). */
    public function waivers(): BelongsToMany
    {
        return $this->belongsToMany(Waiver::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** The apparatus new sessions use by default (pivot: quantity, max_per_item). */
    public function apparatus(): BelongsToMany
    {
        return $this->belongsToMany(Apparatus::class, 'program_apparatus')->withPivot(['quantity', 'max_per_item'])->orderBy('apparatus.name');
    }

    public function isEvent(): bool
    {
        return $this->type === 'event';
    }

    /** @return Collection<int, Category> */
    public function categoriesOf(string $type): Collection
    {
        return $this->categories->where('type', $type)->values();
    }

    /** @return list<string> */
    public function planTagSlugs(): array
    {
        return $this->categoriesOf('plan_tag')->pluck('slug')->all();
    }

    public function scheduleLabel(): string
    {
        if ($this->isEvent()) {
            return $this->event_date ? 'Event · '.$this->event_date->format('D, M j, Y') : 'Event · date not set';
        }
        if ($this->weekday === null || ! $this->start_time) {
            return 'Schedule not set';
        }
        $time = \DateTime::createFromFormat('H:i', $this->start_time);

        return self::WEEKDAYS[$this->weekday].'s · '.($time ? $time->format('g:i A') : $this->start_time).' · '.$this->duration_min.' min';
    }

    public function ageLabel(): ?string
    {
        return match (true) {
            $this->min_age !== null && $this->max_age !== null => "Ages {$this->min_age}–{$this->max_age}",
            $this->min_age !== null => "Ages {$this->min_age}+",
            $this->max_age !== null => "Ages up to {$this->max_age}",
            default => null,
        };
    }
}
