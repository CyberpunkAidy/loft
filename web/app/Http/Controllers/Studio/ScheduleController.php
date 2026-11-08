<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Studio;
use App\Services\Bookings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The public schedule (the plugin's [loft_schedule]) and program pages. */
class ScheduleController extends Controller
{
    public function index(Request $request, Studio $studio, Bookings $bookings): View
    {
        $weeks = max(1, min(4, (int) $studio->setting('schedule_weeks')));
        $page = max(0, min(52, (int) $request->query('week', 0)));
        $group = (string) $request->query('group', '');
        $level = (string) $request->query('level', '');

        $from = $studio->localNow()->startOfDay()->addDays($page * 7 * $weeks);
        $to = $from->copy()->addDays(7 * $weeks);

        $query = $studio->sessions()
            ->withSeatCounts()
            ->with(['program.categories', 'instructor', 'room'])
            ->where('status', 'scheduled')
            ->where('starts_at', '>=', $from->copy()->utc())
            ->where('starts_at', '<', $to->copy()->utc())
            ->whereHas('program', fn ($q) => $q->where('is_published', true))
            ->orderBy('starts_at');
        foreach (['age_group' => $group, 'level' => $level] as $type => $slug) {
            if ($slug !== '') {
                $query->whereHas('program.categories', fn ($q) => $q->where('type', $type)->where('slug', $slug));
            }
        }
        $sessions = $query->limit(500)->get()->each->setRelation('studio', $studio);
        ClassSession::loadApparatusSlots($sessions);

        return view('studio.schedule', [
            'days' => $sessions->groupBy(fn (ClassSession $s) => $studio->local($s->starts_at)->format('Y-m-d')),
            'mine' => $this->mine($request, $studio, $sessions->pluck('id')->all()),
            'actions' => $sessions->mapWithKeys(fn (ClassSession $s) => [$s->id => $this->action($request, $s, $bookings)]),
            'from' => $from,
            'to' => $to->copy()->subDay(),
            'page' => $page,
            'group' => $group,
            'level' => $level,
            'groups' => $studio->categories()->where('type', 'age_group')->orderBy('name')->get(),
            'levels' => $studio->categories()->where('type', 'level')->orderBy('name')->get(),
        ]);
    }

    public function program(Request $request, Studio $studio, Program $program, Bookings $bookings): View
    {
        abort_unless($program->is_published, 404);
        $sessions = $program->sessions()->withSeatCounts()->with(['program.categories', 'room'])
            ->where('status', 'scheduled')->where('starts_at', '>', now())
            ->orderBy('starts_at')->limit(12)->get()->each->setRelation('studio', $studio);
        ClassSession::loadApparatusSlots($sessions);

        return view('studio.program', [
            'program' => $program->load(['categories', 'instructor', 'season']),
            'sessions' => $sessions,
            'mine' => $this->mine($request, $studio, $sessions->pluck('id')->all()),
            'actions' => $sessions->mapWithKeys(fn (ClassSession $s) => [$s->id => $this->action($request, $s, $bookings)]),
        ]);
    }

    /** The signed-in member's bookings in these sessions: [session_id => [booking…]]. */
    private function mine(Request $request, Studio $studio, array $sessionIds): array
    {
        if (! $request->user() || ! $sessionIds) {
            return [];
        }

        return $studio->bookings()->with('attendee')
            ->where('user_id', $request->user()->id)
            ->whereIn('class_session_id', $sessionIds)
            ->whereIn('status', ['booked', 'waitlisted', 'attended'])
            ->get()
            ->groupBy('class_session_id')
            ->all();
    }

    /** @return array{label: string, url: ?string, secondary: bool} the session's booking button */
    private function action(Request $request, ClassSession $session, Bookings $bookings): array
    {
        $opensAt = $bookings->opensAt($session);
        $closes = (int) $session->studio->setting('booking_closes_minutes_before');

        return match (true) {
            $session->hasStarted() || ($closes > 0 && $session->starts_at->copy()->subMinutes($closes)->isPast()) => ['label' => 'Booking closed', 'url' => null, 'secondary' => true],
            $opensAt !== null && $opensAt->isFuture() => ['label' => 'Opens '.$session->studio->local($opensAt)->format('M j'), 'url' => null, 'secondary' => true],
            ! $request->user() => ['label' => 'Sign in to book', 'url' => route('studio.book', ['session' => $session]), 'secondary' => $session->isFull()],
            $session->isFull() => ['label' => 'Join waitlist', 'url' => route('studio.book', ['session' => $session]), 'secondary' => true],
            default => ['label' => 'Book', 'url' => route('studio.book', ['session' => $session]), 'secondary' => false],
        };
    }
}
