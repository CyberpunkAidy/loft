<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Series;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\SeriesSignups;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/** The public schedule (the plugin's [loft_schedule]) and program pages. */
class ScheduleController extends Controller
{
    public function index(Request $request, Studio $studio, Bookings $bookings, SeriesSignups $signups): View
    {
        $weeks = max(1, min(4, (int) $studio->setting('schedule_weeks')));
        $page = max(0, min(52, (int) $request->query('week', 0)));
        $group = (string) $request->query('group', '');
        $level = (string) $request->query('level', '');

        $from = $studio->localNow()->startOfDay()->addDays($page * 7 * $weeks);
        $to = $from->copy()->addDays(7 * $weeks);

        $query = $studio->sessions()
            ->withSeatCounts()
            ->with(['program.categories', 'instructor', 'room', 'series'])
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
            'openSeries' => $page === 0 ? $this->openSeries(Series::where('studio_id', $studio->id)->whereHas('program', fn ($q) => $q->where('is_published', true)), $signups) : collect(),
        ]);
    }

    public function program(Request $request, Studio $studio, Program $program, Bookings $bookings, SeriesSignups $signups): View
    {
        abort_unless($program->is_published, 404);
        $sessions = $program->sessions()->withSeatCounts()->with(['program.categories', 'room', 'series'])
            ->where('status', 'scheduled')->where('starts_at', '>', now())
            ->orderBy('starts_at')->limit(12)->get()->each->setRelation('studio', $studio);
        ClassSession::loadApparatusSlots($sessions);

        return view('studio.program', [
            'program' => $program->load(['categories', 'instructor', 'season']),
            'sessions' => $sessions,
            'mine' => $this->mine($request, $studio, $sessions->pluck('id')->all()),
            'actions' => $sessions->mapWithKeys(fn (ClassSession $s) => [$s->id => $this->action($request, $s, $bookings)]),
            'openSeries' => $this->openSeries(Series::where('program_id', $program->id), $signups),
        ]);
    }

    /**
     * Series still taking sign-ups (decision 47), soonest first, each with its quote.
     *
     * @return Collection<int, array{series: Series, quote: array<string, mixed>}>
     */
    private function openSeries(Builder $query, SeriesSignups $signups): Collection
    {
        return $query->with(['program', 'studio'])->where('signups_open', true)
            ->where('ends_on', '>=', now()->subDay()->toDateString())->orderBy('starts_on')->limit(12)->get()
            ->map(fn (Series $series) => ['series' => $series, 'quote' => $signups->quote($series)])
            ->filter(fn (array $s) => $s['quote']['open'])->take(6)->values();
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
            $request->route('studio')->isPaused() => ['label' => 'Booking paused', 'url' => null, 'secondary' => true],
            $session->hasStarted() || ($closes > 0 && $session->starts_at->copy()->subMinutes($closes)->isPast()) => ['label' => 'Booking closed', 'url' => null, 'secondary' => true],
            $bookings->seriesOnly($session) !== null => ['label' => 'Part of a series', 'url' => route('studio.series', ['series' => $session->series_id]), 'secondary' => true],
            $opensAt !== null && $opensAt->isFuture() => ['label' => 'Opens '.$session->studio->local($opensAt)->format('M j'), 'url' => null, 'secondary' => true],
            ! $request->user() => ['label' => 'Sign in to book', 'url' => route('studio.book', ['session' => $session]), 'secondary' => $session->isFull()],
            $session->isFull() => ['label' => 'Join waitlist', 'url' => route('studio.book', ['session' => $session]), 'secondary' => true],
            default => ['label' => 'Book', 'url' => route('studio.book', ['session' => $session]), 'secondary' => false],
        };
    }
}
