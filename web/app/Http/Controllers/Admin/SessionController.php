<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Bookings;
use App\Services\Waivers;
use App\Support\BookingException;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Session list, rosters, capacity, cancellations and staff bookings. */
class SessionController extends Controller
{
    public function index(Request $request, Studio $studio): View
    {
        $raw = (string) $request->query('from', '');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? Carbon::parse($raw, $studio->timezone)->startOfDay() : $studio->localNow()->startOfDay();
        $to = $from->copy()->addDays(14);
        $programId = (int) $request->query('program', 0);

        $sessions = $studio->sessions()->withSeatCounts()->with('program')
            ->where('starts_at', '>=', $from->copy()->utc())->where('starts_at', '<', $to->copy()->utc())
            ->when($programId, fn ($q) => $q->where('program_id', $programId))
            ->orderBy('starts_at')->limit(500)->get();

        return view('admin.sessions.index', [
            'sessions' => $sessions,
            'from' => $from,
            'to' => $to,
            'programId' => $programId,
            'programs' => $studio->programs()->orderBy('title')->get(['id', 'title']),
        ]);
    }

    public function show(Request $request, Studio $studio, ClassSession $session, Waivers $waivers): View
    {
        $session->loadCount([
            'bookings as booked_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES),
            'bookings as waitlist_count' => fn ($q) => $q->where('status', 'waitlisted'),
        ]);
        $roster = $session->bookings()->with(['attendee', 'user', 'entitlement.plan'])->whereNot('status', 'cancelled')
            ->orderByRaw("case status when 'waitlisted' then 2 when 'late_cancelled' then 3 else 1 end")
            ->orderBy('position')->orderBy('booked_at')->get();

        $q = trim((string) $request->query('q', ''));
        $candidates = $studio->attendees()->with('user')->where('status', 'active')
            ->tap(fn ($query) => Search::apply($query, ['first_name', 'last_name'], $q))
            ->whereNotIn('id', $roster->pluck('attendee_id'))
            ->orderBy('first_name')->orderBy('last_name')->limit(300)->get();

        return view('admin.sessions.show', [
            'session' => $session->load(['program', 'instructor']),
            'roster' => $roster,
            'waiverOk' => $roster->mapWithKeys(fn (Booking $b) => [$b->id => $waivers->isSigned($studio, $b->attendee)]),
            'candidates' => $candidates,
            'q' => $q,
            'instructors' => $studio->members()->wherePivotIn('role', StudioUser::STAFF_ROLES)->orderBy('first_name')->get(),
        ]);
    }

    public function update(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): RedirectResponse
    {
        $staffIds = $studio->studioUsers()->whereIn('role', StudioUser::STAFF_ROLES)->pluck('user_id')->all();
        $data = $request->validate([
            'capacity' => ['required', 'integer', 'between:1,500'],
            'instructor_id' => ['nullable', Rule::in($staffIds)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $grew = $data['capacity'] > $session->capacity;
        $session->update($data);
        $promoted = $grew ? $bookings->promoteWaitlist($session)->count() : 0;

        return back()->with('success', 'Session updated.'.($promoted ? " {$promoted} moved in from the waitlist." : ''));
    }

    public function cancel(Studio $studio, ClassSession $session, Bookings $bookings): RedirectResponse
    {
        if ($session->isCancelled()) {
            return back();
        }
        $count = $bookings->cancelSession($session);

        return back()->with('success', "Session cancelled. {$count} ".($count === 1 ? 'booking was' : 'bookings were').' cancelled and notified; credits were returned.');
    }

    public function destroy(Studio $studio, ClassSession $session): RedirectResponse
    {
        if ($session->bookings()->exists()) {
            return back()->with('error', 'This session has booking history, so it can be cancelled but not deleted.');
        }
        $session->delete();

        return redirect()->route('studio.admin.sessions.index')->with('success', 'Session deleted.');
    }

    /** Staff add someone to a class. "Comp" books without using a membership or credit. */
    public function book(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): RedirectResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer'], 'comp' => ['nullable', 'boolean']]);
        $attendee = $studio->attendees()->where('status', 'active')->findOrFail($data['attendee_id']);

        try {
            $booking = $bookings->book($session, $attendee, (int) $attendee->user_id, 'admin', null, $request->boolean('comp'));
        } catch (BookingException $e) {
            $message = $e->reason === 'needs_plan'
                ? "{$attendee->name} has no membership or credits that cover this class. Tick “Comp” to add them free."
                : $e->getMessage();

            return back()->with('error', $message);
        }

        return back()->with('success', $booking->status === 'waitlisted'
            ? "The class is full, so {$attendee->name} was added to the waitlist (#{$booking->position})."
            : "{$attendee->name} added.");
    }

    public function cancelBooking(Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        try {
            $result = $bookings->cancel($booking, null, byAdmin: true);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Removed from the class.'.($result['credit_refunded'] ? ' Their credit was returned.' : ''));
    }
}
