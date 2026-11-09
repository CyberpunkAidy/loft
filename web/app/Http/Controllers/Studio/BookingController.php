<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Services\BookingOptions;
use App\Services\Bookings;
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
    public function create(Request $request, Studio $studio, ClassSession $session, BookingOptions $options): View
    {
        abort_if($session->isCancelled() || ! $session->program->is_published, 404);
        $found = $options->for($studio, $session, $request->user());
        $attendees = $found['attendees']->pluck('attendee');
        $states = $found['attendees']->mapWithKeys(fn (array $o) => [$o['attendee']->id => [
            'booking' => $o['booking'],
            'age_error' => $o['age_error'],
            'needs_waiver' => $o['waiver'] !== null,
        ]])->all();
        $closed = $found['closed'];
        $slots = $session->apparatusSlots();
        $chosen = $slots->has($request->integer('apparatus')) ? $request->integer('apparatus') : null;

        return view('studio.book', compact('session', 'attendees', 'states', 'closed', 'slots', 'chosen'));
    }

    public function store(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): View|RedirectResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer'], 'apparatus_id' => ['nullable', 'integer']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->findOrFail($data['attendee_id']);
        $apparatusId = ($data['apparatus_id'] ?? null) ? (int) $data['apparatus_id'] : null;

        try {
            $booking = $bookings->book($session, $attendee, $request->user()->id, apparatusId: $apparatusId);
        } catch (BookingException $e) {
            return match ($e->reason) {
                'waiver' => redirect()->route('studio.waiver', [
                    'attendee' => $attendee,
                    'waiver' => $e->context['waiver']->id,
                    'return' => route('studio.book', array_filter(['session' => $session, 'apparatus' => $apparatusId]), false),
                ])->with('warning', $e->getMessage()),
                'needs_plan' => view('studio.choose-plan', ['session' => $session, 'attendee' => $attendee, 'plans' => $e->context['plans'], 'apparatusId' => $apparatusId]),
                default => back()->withInput()->with('error', $e->getMessage()),
            };
        }

        $apparatus = $booking->apparatus;
        $message = match (true) {
            $booking->status === 'waitlisted' && $apparatus && $apparatusId => "Every {$apparatus->name} spot is taken, so {$attendee->first_name} is #{$booking->position} on the waitlist for one. We'll email you if a spot opens.",
            $booking->status === 'waitlisted' => "That class is full, so {$attendee->first_name} is #{$booking->position} on the waitlist. We'll email you if a spot opens.",
            default => "{$attendee->first_name} is booked for {$session->program->title}".($apparatus ? " ({$booking->apparatusLabel()})" : '').'!',
        };

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
