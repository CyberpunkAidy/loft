<?php

namespace App\Models;

use App\Support\ApparatusSlot;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One occurrence of a program, with its own capacity. starts_at / ends_at are UTC.
 *
 * A session can be in a room and use some of the room's apparatus (session_apparatus). Then each
 * booking takes a spot on one apparatus, and the class is full when either its capacity or every
 * apparatus it uses is full.
 */
#[Fillable([
    'studio_id', 'program_id', 'season_id', 'starts_at', 'ends_at', 'capacity', 'status', 'instructor_id', 'price_cents', 'notes',
    'room_id', 'waitlist_capacity', 'delivery', 'online_capacity', 'meeting_url',
])]
class ClassSession extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'scheduled', 'capacity' => 10, 'delivery' => 'in_person'];

    /** Where a class happens. Online ones use capacity for online spots; hybrid ones add online_capacity. */
    public const DELIVERIES = [
        'in_person' => 'In the studio',
        'online' => 'Online',
        'hybrid' => 'In the studio and online',
    ];

    /** People booked online can join from this many minutes before class until it ends. */
    public const JOIN_OPENS_MINUTES = 15;

    protected $table = 'class_sessions';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'capacity' => 'integer',
            'price_cents' => 'integer',
            'waitlist_capacity' => 'integer',
            'online_capacity' => 'integer',
        ];
    }

    /** @var Collection<int, ApparatusSlot>|null keyed by apparatus id */
    private ?Collection $apparatusSlots = null;

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

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** The apparatus this session uses (pivot: quantity, max_per_item). */
    public function apparatus(): BelongsToMany
    {
        return $this->belongsToMany(Apparatus::class, 'session_apparatus')->withPivot(['quantity', 'max_per_item'])->orderBy('apparatus.name');
    }

    /**
     * The apparatus this session uses, with spots booked on each, keyed by apparatus id. Empty when
     * the session uses none. Cached on the instance; pass $refresh to count again (inside a lock).
     *
     * @return Collection<int, ApparatusSlot>
     */
    public function apparatusSlots(bool $refresh = false): Collection
    {
        if ($this->apparatusSlots === null || $refresh) {
            if ($refresh) {
                $this->unsetRelation('apparatus');
            }
            self::loadApparatusSlots(collect([$this]));
        }

        return $this->apparatusSlots;
    }

    /** Count apparatus spots for many sessions at once (two queries), e.g. for the schedule. */
    public static function loadApparatusSlots(Collection $sessions): void
    {
        $sessions = $sessions->filter(fn (self $s) => $s->exists);
        if ($sessions->isEmpty()) {
            return;
        }
        EloquentCollection::make($sessions->all())->loadMissing('apparatus');
        $using = $sessions->filter(fn (self $s) => $s->apparatus->isNotEmpty())->pluck('id');
        $taken = $using->isEmpty() ? collect() : Booking::query()
            ->whereIn('class_session_id', $using)->whereIn('status', Booking::SEAT_STATUSES)->whereNotNull('apparatus_id')
            ->selectRaw('class_session_id, apparatus_id, count(*) as taken')->groupBy('class_session_id', 'apparatus_id')
            ->get()->groupBy('class_session_id')->map(fn ($rows) => $rows->pluck('taken', 'apparatus_id'));

        foreach ($sessions as $session) {
            $session->apparatusSlots = $session->apparatus->mapWithKeys(fn (Apparatus $a) => [$a->id => new ApparatusSlot(
                $a,
                (int) $a->pivot->quantity,
                (int) $a->pivot->max_per_item,
                (int) ($taken[$session->id][$a->id] ?? 0),
            )]);
        }
    }

    public function usesApparatus(): bool
    {
        return $this->apparatusSlots()->isNotEmpty();
    }

    /** Adds booked_count (seats taken), online_count (a hybrid class's online spots taken) and waitlist_count. */
    public function scopeWithSeatCounts(Builder $query): Builder
    {
        return $query->withCount(self::seatCounts());
    }

    /** @return array<string, \Closure> the counts withSeatCounts adds, for loadCount() on one session */
    public static function seatCounts(): array
    {
        return [
            'bookings as booked_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES)->where('online_spot', false),
            'bookings as online_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES)->where('online_spot', true),
            'bookings as waitlist_count' => fn ($q) => $q->where('status', 'waitlisted'),
        ];
    }

    /** Seats taken: everyone in the studio (or, for an online class, everyone). A hybrid class's online spots are counted apart. */
    public function bookedCount(): int
    {
        return (int) ($this->booked_count ?? $this->bookings()->whereIn('status', Booking::SEAT_STATUSES)->where('online_spot', false)->count());
    }

    public function onlineCount(): int
    {
        return (int) ($this->online_count ?? $this->bookings()->whereIn('status', Booking::SEAT_STATUSES)->where('online_spot', true)->count());
    }

    public function isOnline(): bool
    {
        return $this->delivery === 'online';
    }

    public function isHybrid(): bool
    {
        return $this->delivery === 'hybrid';
    }

    public function deliveryLabel(): string
    {
        return self::DELIVERIES[$this->delivery] ?? self::DELIVERIES['in_person'];
    }

    /** A hybrid class's online spots left: null when there's no limit. */
    public function onlineSpotsLeft(): ?int
    {
        return $this->isHybrid() && $this->online_capacity !== null ? max(0, $this->online_capacity - $this->onlineCount()) : null;
    }

    /** When people booked online can start joining. */
    public function joinOpensAt(): Carbon
    {
        return $this->starts_at->copy()->subMinutes(self::JOIN_OPENS_MINUTES);
    }

    public function waitlistCount(): int
    {
        return (int) ($this->waitlist_count ?? $this->bookings()->where('status', 'waitlisted')->count());
    }

    /** Open spots: the capacity left, and no more than the apparatus spots left. */
    public function spotsLeft(): int
    {
        $left = max(0, $this->capacity - $this->bookedCount());
        $slots = $this->apparatusSlots();

        return $slots->isEmpty() ? $left : min($left, $slots->sum(fn (ApparatusSlot $slot) => $slot->left()));
    }

    public function waitlistIsFull(): bool
    {
        return $this->waitlist_capacity !== null && $this->waitlistCount() >= $this->waitlist_capacity;
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
