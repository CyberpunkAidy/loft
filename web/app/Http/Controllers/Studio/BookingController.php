<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Waivers;
use App\Support\BookingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book a class: choose who's going → waiver check → membership/credit check → (checkout) → booked.
 * The plugin did this with modals over its REST API; here each step is a page, so it works without JavaScript.
 */
class BookingController extends Controller
{
    public function create(Request $request, Studio $studio, ClassSession $session, Bookings $bookings, Waivers $waivers): View
    {
        abort_if($session->isCancelled() || ! $session->program->is_published, 404);
        $session->loadCount([
            'bookings as booked_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES),
            'bookings as waitlist_count' => fn ($q) => $q->where('status', 'waitlisted'),
        ]);

        $attendees = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')
            ->orderByDesc('is_self')->orderBy('first_name')->get();
        $states = [];
        foreach ($attendees as $attendee) {
            $existing = $bookings->existing($session, $attendee);
            $ageError = null;
            try {
                $bookings->assertAge($session, $attendee);
            } catch (BookingException $e) {
                $ageError = $e->getMessage();
            }
            $states[$attendee->id] = [
                'booking' => $existing,
                'age_error' => $ageError,
                'needs_waiver' => ! $waivers->isSigned($studio, $attendee, $session->program),
            ];
        }

        $closed = null;
        try {
            $bookings->assertOpen($session);
        } catch (BookingException $e) {
            $closed = $e->getMessage();
        }

        return view('studio.book', compact('session', 'attendees', 'states', 'closed'));
    }

    public function store(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): View|RedirectResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->findOrFail($data['attendee_id']);

        try {
            $booking = $bookings->book($session, $attendee, $request->user()->id);
        } catch (BookingException $e) {
            return match ($e->reason) {
                'waiver' => redirect()->route('studio.waiver', [
                    'attendee' => $attendee,
                    'waiver' => $e->context['waiver']->id,
                    'return' => route('studio.book', ['session' => $session], false),
                ])->with('warning', $e->getMessage()),
                'needs_plan' => view('studio.choose-plan', ['session' => $session, 'attendee' => $attendee, 'plans' => $e->context['plans']]),
                default => back()->with('error', $e->getMessage()),
            };
        }

        $message = $booking->status === 'waitlisted'
            ? "That class is full, so {$attendee->first_name} is #{$booking->position} on the waitlist. We'll email you if a spot opens."
            : "{$attendee->first_name} is booked for {$session->program->title}!";

        return redirect()->route('studio.account')->with('success', $message);
    }

    public function cancel(Request $request, Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        try {
            $result = $bookings->cancel($booking, $request->user()->id);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = match (true) {
            $result['status'] === 'late_cancelled' && $booking->entitlement?->isCredits() && ! $result['credit_refunded'] => 'Cancelled. It was inside the late-cancellation window, so the class credit wasn’t returned.',
            $result['credit_refunded'] => 'Cancelled, and your class credit is back on your account.',
            default => 'Cancelled.',
        };

        return back()->with('success', $message);
    }
}
