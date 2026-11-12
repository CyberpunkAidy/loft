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
        $allocation = $this->allocation($program);
        foreach ($wanted as $key => $start) {
            if ($existing->has($key) || $start->isPast()) {
                continue;
            }
            $session = ClassSession::create([
                'studio_id' => $program->studio_id,
                'program_id' => $program->id,
                'season_id' => $program->isEvent() ? null : ($program->season_id ?? $program->studio->currentSeason()?->id),
                'starts_at' => $start,
                'ends_at' => $start->copy()->addMinutes(max(15, $program->duration_min)),
                'capacity' => max(1, $program->capacity),
                'status' => 'scheduled',
                'instructor_id' => $program->instructor_id,
                'price_cents' => $program->dropin_price_cents,
                'room_id' => $program->room_id,
                'waitlist_capacity' => $program->waitlist_capacity,
                'delivery' => $program->delivery,
                'online_capacity' => $program->online_capacity,
                'meeting_url' => $program->meeting_url,
            ]);
            if ($allocation) {
                $session->apparatus()->sync($allocation);
            }
            $created++;
        }

        // Classes in a series' date range belong to it (decision 47), including ones just made.
        $program->series()->each(fn ($series) => $series->attachSessions());

        return ['created' => $created, 'removed' => $removed];
    }

    /**
     * Copy the program's capacity, room, waitlist limit, apparatus and where it happens (online spots,
     * meeting link) onto its upcoming sessions,
     * replacing per-session edits. People already booked keep their spots; anyone a bigger class
     * now has room for moves in from the waitlist.
     *
     * @return int sessions updated
     */
    public function applyToUpcoming(Program $program, Bookings $bookings): int
    {
        $allocation = $this->allocation($program);
        $sessions = $program->sessions()->where('status', 'scheduled')->where('starts_at', '>', now())->get();
        foreach ($sessions as $session) {
            $session->update([
                'capacity' => max(1, $program->capacity),
                'room_id' => $program->room_id,
                'waitlist_capacity' => $program->waitlist_capacity,
                'delivery' => $program->delivery,
                'online_capacity' => $program->online_capacity,
                'meeting_url' => $program->meeting_url,
            ]);
            $session->apparatus()->sync($allocation);
            $bookings->promoteWaitlist($session);
        }

        return $sessions->count();
    }

    /** @return array<int, array{quantity: int, max_per_item: int}> the program's apparatus, for a session */
    private function allocation(Program $program): array
    {
        if (! $program->room_id) {
            return [];
        }

        return $program->apparatus()->where('apparatus.room_id', $program->room_id)->get()
            ->mapWithKeys(fn ($a) => [$a->id => ['quantity' => (int) $a->pivot->quantity, 'max_per_item' => (int) $a->pivot->max_per_item]])
            ->all();
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
