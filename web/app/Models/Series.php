<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A run of one program's classes sold as one (decision 47): "Silks Level 1, Jan 6 – Feb 24", eight
 * classes for $240. Signing up books the person into every class still to come; its classes are
 * the program's sessions on dates from starts_on to ends_on (class_sessions.series_id).
 */
#[Fillable(['studio_id', 'program_id', 'name', 'starts_on', 'ends_on', 'price_cents', 'join_until_class', 'makeups', 'allow_dropins', 'signups_open'])]
class Series extends Model
{
    protected $table = 'series';

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['join_until_class' => 2, 'makeups' => true, 'allow_dropins' => false, 'signups_open' => true];

    /** How long a make-up credit lasts after the series ends. */
    public const MAKEUP_GRACE_DAYS = 14;

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'price_cents' => 'integer',
            'join_until_class' => 'integer',
            'makeups' => 'boolean',
            'allow_dropins' => 'boolean',
            'signups_open' => 'boolean',
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

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class)->orderBy('starts_at');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SeriesEnrollment::class);
    }

    /** Its classes that will run, in order (cancelled ones don't count). */
    public function classes(): Collection
    {
        return $this->sessions()->where('status', '!=', 'cancelled')->get();
    }

    /** Point the program's classes in this date range at this series, and let go of any outside it. */
    public function attachSessions(): int
    {
        $studio = $this->studio;
        [$from, $to] = [$studio->parseLocal($this->starts_on->format('Y-m-d').' 00:00:00'), $studio->parseLocal($this->ends_on->format('Y-m-d').' 23:59:59')];
        ClassSession::where('series_id', $this->id)->where(fn ($q) => $q->where('starts_at', '<', $from)->orWhere('starts_at', '>', $to)->orWhere('program_id', '!=', $this->program_id))
            ->update(['series_id' => null]);

        return ClassSession::where('program_id', $this->program_id)->whereBetween('starts_at', [$from, $to])
            ->where(fn ($q) => $q->whereNull('series_id')->orWhere('series_id', $this->id))
            ->update(['series_id' => $this->id]);
    }

    /** People in it now, or holding a spot while they pay. */
    public function takenCount(): int
    {
        return $this->enrollments()->whereIn('status', ['pending', 'active'])->count();
    }

    /** When make-up credits from it run out: two weeks after its last day, in studio time. */
    public function makeupsExpireAt(): Carbon
    {
        return $this->studio->parseLocal($this->ends_on->copy()->addDays(self::MAKEUP_GRACE_DAYS)->format('Y-m-d').' 23:59:59');
    }

    public function priceLabel(): string
    {
        return Money::format($this->price_cents, $this->studio->currency);
    }

    /** "Jan 6 – Feb 24" (with years when it crosses one or isn't this year). */
    public function datesLabel(): string
    {
        $sameYear = $this->starts_on->year === $this->ends_on->year;
        $showYear = ! $sameYear || $this->starts_on->year !== now()->year;

        return $this->starts_on->format($sameYear ? 'M j' : 'M j, Y').' – '.$this->ends_on->format($showYear ? 'M j, Y' : 'M j');
    }
}
