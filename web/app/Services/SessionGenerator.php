<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Program;
use Illuminate\Support\Carbon;

/**
 * Turns a program's schedule (weekday + time across a season, or a single event date) into sessions.
 * Times are built in the studio's timezone, so a 5pm class stays at 5pm across daylight-saving changes.
 */
class SessionGenerator
{
    /**
     * Create the missing sessions and remove future sessions that no longer match the schedule
     * (only ones nobody ever booked). Existing matching sessions keep any per-session edits.
     *
     * @return array{created: int, removed: int}
     */
    public function sync(Program $program): array
    {
        $starts = $this->scheduledStarts($program);
        $wanted = [];
        foreach ($starts as $start) {
            $wanted[$start->format('Y-m-d H:i:s')] = $start;
        }

        $existing = $program->sessions()->get()->keyBy(fn (ClassSession $s) => $s->starts_at->format('Y-m-d H:i:s'));

        $removed = 0;
        foreach ($existing as $key => $session) {
            if (! isset($wanted[$key]) && $session->starts_at->isFuture() && ! $session->bookings()->exists()) {
                $session->delete();
                $removed++;
            }
        }

        $created = 0;
        foreach ($wanted as $key => $start) {
            if ($existing->has($key) || $start->isPast()) {
                continue;
            }
            ClassSession::create([
                'studio_id' => $program->studio_id,
                'program_id' => $program->id,
                'season_id' => $program->isEvent() ? null : ($program->season_id ?? $program->studio->currentSeason()?->id),
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(max(15, $program->duration_min)),
                'capacity' => max(1, $program->capacity),
                'status' => 'scheduled',
                'instructor_id' => $program->instructor_id,
                'price_cents' => $program->dropin_price_cents,
            ]);
            $created++;
        }

        return ['created' => $created, 'removed' => $removed];
    }

    /** @return list<Carbon> UTC start times the schedule calls for */
    public function scheduledStarts(Program $program): array
    {
        $studio = $program->studio;
        $time = $program->start_time ?: '17:00';

        if ($program->isEvent()) {
            return $program->event_date ? [$studio->parseLocal($program->event_date->format('Y-m-d').' '.$time)] : [];
        }

        $season = $program->season ?? $studio->currentSeason();
        if (! $season || $program->weekday === null) {
            return [];
        }

        $starts = [];
        $cursor = Carbon::parse($season->starts_on->format('Y-m-d'), $studio->timezone);
        $last = Carbon::parse($season->ends_on->format('Y-m-d'), $studio->timezone);
        while ($cursor->dayOfWeek !== $program->weekday) {
            $cursor->addDay();
        }
        for (; $cursor->lte($last); $cursor->addWeek()) {
            $starts[] = $studio->parseLocal($cursor->format('Y-m-d').' '.$time);
        }

        return $starts;
    }
}
