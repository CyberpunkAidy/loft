<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One occurrence of a program, with its own capacity. starts_at / ends_at are UTC.
 */
#[Fillable([
    'studio_id', 'program_id', 'season_id', 'starts_at', 'ends_at', 'capacity', 'status', 'instructor_id', 'price_cents', 'notes',
])]
class ClassSession extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'scheduled', 'capacity' => 10];

    protected $table = 'class_sessions';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'price_cents' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /** Adds booked_count (seats taken) and waitlist_count. */
    public function scopeWithSeatCounts(Builder $query): Builder
    {
        return $query->withCount([
            'bookings as booked_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES),
            'bookings as waitlist_count' => fn ($q) => $q->where('status', 'waitlisted'),
        ]);
    }

    public function bookedCount(): int
    {
        return (int) ($this->booked_count ?? $this->bookings()->whereIn('status', Booking::SEAT_STATUSES)->count());
    }

    public function waitlistCount(): int
    {
        return (int) ($this->waitlist_count ?? $this->bookings()->where('status', 'waitlisted')->count());
    }

    public function spotsLeft(): int
    {
        return max(0, $this->capacity - $this->bookedCount());
    }

    public function isFull(): bool
    {
        return $this->spotsLeft() === 0;
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function hasStarted(): bool
    {
        return $this->starts_at->isPast();
    }
}
