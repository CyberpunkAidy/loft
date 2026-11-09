<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Bookings;
use App\Services\Rooms;
use App\Services\Waivers;
use App\Support\ApparatusAllocation;
use App\Support\BookingException;
use App\Support\Search;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

    public function show(Request $request, Studio $studio, ClassSession $session, Waivers $waivers, Rooms $rooms): View
    {
        $session->loadCount(ClassSession::seatCounts());
        $roster = $session->bookings()->with(['attendee', 'user', 'entitlement.plan', 'apparatus'])->whereNot('status', 'cancelled')
            ->orderByRaw("case status when 'waitlisted' then 2 when 'late_cancelled' then 3 else 1 end")
            ->orderBy('position')->orderBy('booked_at')->get();

        $q = trim((string) $request->query('q', ''));
        $candidates = $studio->attendees()->with('user')->where('status', 'active')
            ->tap(fn ($query) => Search::apply($query, ['first_name', 'last_name'], $q))
            ->whereNotIn('id', $roster->pluck('attendee_id'))
            ->orderBy('first_name')->orderBy('last_name')->limit(300)->get();

        $session->load(['program', 'instructor', 'room', 'apparatus']);
        $overlapping = $rooms->overlapping($session);

        return view('admin.sessions.show', [
            'session' => $session,
            'slots' => $session->apparatusSlots(),
            'rooms' => $studio->rooms()->with('apparatus')->orderBy('name')->get(),
            'overlapping' => $overlapping,
            'overcommitted' => $rooms->overcommitted($session, $overlapping),
            'roster' => $roster,
            'waiverOk' => $roster->mapWithKeys(fn (Booking $b) => [$b->id => $waivers->clears($b)]),
            'candidates' => $candidates,
            'q' => $q,
            'instructors' => $studio->members()->wherePivotIn('role', StudioUser::STAFF_ROLES)->orderBy('first_name')->get(),
        ]);
    }

    public function update(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): RedirectResponse
    {
        $staffIds = $studio->studioUsers()->whereIn('role', StudioUser::STAFF_ROLES)->pluck('user_id')->all();
        $room = $request->filled('room_id') ? $studio->rooms()->with('apparatus')->find($request->integer('room_id')) : null;
        $data = $request->validate([
            'capacity' => ['required', 'integer', 'between:1,500', ApparatusAllocation::capacityRule($room)],
            'room_id' => ['nullable', Rule::exists('rooms', 'id')->where('studio_id', $studio->id)],
            'waitlist_capacity' => ['nullable', 'integer', 'between:0,500'],
            'delivery' => ['nullable', Rule::in(array_keys(ClassSession::DELIVERIES))],
            'online_capacity' => ['nullable', 'integer', 'between:1,1000'],
            'meeting_url' => ['nullable', 'url:https,http', 'max:500'],
            'instructor_id' => ['nullable', Rule::in($staffIds)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], ['waitlist_capacity' => 'waitlist limit', 'online_capacity' => 'online spots']);
        $data['delivery'] ??= 'in_person';
        $data['online_capacity'] = $data['delivery'] === 'hybrid' ? ($data['online_capacity'] ?? null) : null;
        if ($session->isHybrid() && $data['delivery'] !== 'hybrid' && ($online = $session->onlineCount())) {
            throw ValidationException::withMessages(['delivery' => ($online === 1 ? '1 person is' : "{$online} people are").' booked online. Keep this class in the studio and online, or remove them first.']);
        }
        $allocation = ApparatusAllocation::fromRequest($request, $room);

        $session->update($data);
        $session->apparatus()->sync($allocation);
        $promoted = $bookings->promoteWaitlist($session)->count();

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
        $data = $request->validate([
            'attendee_id' => ['required', 'integer'],
            'comp' => ['nullable', 'boolean'],
            'waiver_override' => ['nullable', 'string', 'max:190'],
            'apparatus_id' => ['nullable', 'integer'],
        ]);
        $attendee = $studio->attendees()->where('status', 'active')->findOrFail($data['attendee_id']);
        $override = filled($data['waiver_override'] ?? null) ? ['staff' => $request->user(), 'reason' => trim($data['waiver_override'])] : null;
        $apparatusId = ($data['apparatus_id'] ?? null) ? (int) $data['apparatus_id'] : null;

        try {
            $booking = $bookings->book($session, $attendee, (int) $attendee->user_id, 'admin', null, $request->boolean('comp'), $override, $apparatusId);
        } catch (BookingException $e) {
            $message = match ($e->reason) {
                'needs_plan' => "{$attendee->name} has no membership or credits that cover this class. Tick “Comp” to add them free.",
                'waiver' => "{$attendee->name} hasn’t signed “{$e->context['waiver']->title}”. To add them anyway, give a reason under “No signed waiver?”.",
                'waitlist_full' => "The class and its waitlist are full. To add {$attendee->name}, raise the capacity or the waitlist limit.",
                default => $e->getMessage(),
            };

            return back()->with('error', $message);
        }

        return back()->with('success', match (true) {
            $booking->status === 'waitlisted' && $apparatusId && $booking->apparatus => "Every {$booking->apparatus->name} spot is taken, so {$attendee->name} was added to the waitlist for one (#{$booking->position}).",
            $booking->status === 'waitlisted' => "The class is full, so {$attendee->name} was added to the waitlist (#{$booking->position}).",
            default => "{$attendee->name} added".($booking->apparatus ? " on {$booking->apparatusLabel()}" : '').'.',
        });
    }

    /** Move someone to another apparatus (a waitlist entry then waits for that one). */
    public function moveApparatus(Request $request, Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        $data = $request->validate(['apparatus_id' => ['required', 'integer']]);
        try {
            $bookings->moveApparatus($booking, (int) $data['apparatus_id']);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }
        $booking->refresh()->load('apparatus');

        return back()->with('success', "{$booking->attendee->first_name} is now ".($booking->status === 'waitlisted'
            ? "waiting for {$booking->apparatus->name}."
            : "on {$booking->apparatusLabel()}."));
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
