<?php

namespace App\Services;

use App\Models\ClassSession;
use App\Models\Program;
use Illuminate\Support\Collection;

/**
 * Room clashes: other classes in the same room at the same time, and apparatus used by more
 * classes at once than are rigged. A warning for staff, not a booking rule.
 */
class Rooms
{
    /** @return Collection<int, ClassSession> other live sessions in the same room that overlap this one */
    public function overlapping(ClassSession $session): Collection
    {
        if (! $session->room_id) {
            return collect();
        }

        return ClassSession::query()->with(['program', 'apparatus'])
            ->where('room_id', $session->room_id)
            ->whereKeyNot($session->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '<', $session->ends_at)
            ->where('ends_at', '>', $session->starts_at)
            ->orderBy('starts_at')->get();
    }

    /**
     * Apparatus this session and the classes overlapping it use more of than the room has.
     *
     * @return list<string> e.g. ["Silks: 7 in use, 5 rigged"]
     */
    public function overcommitted(ClassSession $session, ?Collection $overlapping = null): array
    {
        $overlapping ??= $this->overlapping($session);
        $problems = [];
        foreach ($session->apparatus as $a) {
            $inUse = (int) $a->pivot->quantity + $overlapping->sum(fn (ClassSession $o) => (int) ($o->apparatus->firstWhere('id', $a->id)?->pivot->quantity ?? 0));
            if ($inUse > $a->quantity) {
                $problems[] = "{$a->name}: {$inUse} in use, {$a->quantity} rigged";
            }
        }

        return $problems;
    }

    /** How many of a program's upcoming sessions share their room with another class at the same time. */
    public function clashes(Program $program): int
    {
        if (! $program->room_id) {
            return 0;
        }

        return $program->sessions()->where('status', 'scheduled')->where('starts_at', '>', now())
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('class_sessions as other')
                ->whereColumn('other.room_id', 'class_sessions.room_id')
                ->whereColumn('other.id', '!=', 'class_sessions.id')
                ->where('other.status', 'scheduled')
                ->whereColumn('other.starts_at', '<', 'class_sessions.ends_at')
                ->whereColumn('other.ends_at', '>', 'class_sessions.starts_at'))
            ->count();
    }
}
