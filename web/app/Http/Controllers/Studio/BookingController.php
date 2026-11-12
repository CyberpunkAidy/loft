<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Services\BookingOptions;
use App\Services\Bookings;
use App\Services\OnlineClasses;
use App\Support\BookingException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Book a class: choose who's going → the studio's questions → waiver check → membership/credit check →
 * (checkout) → booked.
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
            'questions' => count($o['questions']),
            'needs_waiver' => $o['waiver'] !== null,
        ]])->all();
        $closed = $found['closed'];
        $slots = $session->isOnline() ? collect() : $session->apparatusSlots();
        $chosen = $slots->has($request->integer('apparatus')) ? $request->integer('apparatus') : null;
        $picked = $request->integer('attendee'); // back from the questions or a waiver: still chosen
        $online = $session->isHybrid() && ($request->boolean('online') || ($session->isFull() && $session->waitlistIsFull()));

        return view('studio.book', compact('session', 'attendees', 'states', 'closed', 'slots', 'chosen', 'online', 'picked'));
    }

    public function store(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): View|RedirectResponse
    {
        $data = $request->validate(['attendee_id' => ['required', 'integer'], 'apparatus_id' => ['nullable', 'integer'], 'online' => ['nullable', 'boolean']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->findOrFail($data['attendee_id']);
        $online = $session->isHybrid() && $request->boolean('online');
        $apparatusId = ! $online && ($data['apparatus_id'] ?? null) ? (int) $data['apparatus_id'] : null;

        try {
            $booking = $bookings->book($session, $attendee, $request->user()->id, apparatusId: $apparatusId, online: $online);
        } catch (BookingException $e) {
            $back = route('studio.book', array_filter(['session' => $session, 'attendee' => $attendee->id, 'apparatus' => $apparatusId, 'online' => $online ? 1 : null]), false);

            return match ($e->reason) {
                'questions' => redirect()->route('studio.attendees.questions', ['attendee' => $attendee, 'return' => $back]),
                'series_only' => redirect()->route('studio.series', ['series' => $e->context['series']])->with('warning', $e->getMessage()),
                'waiver' => redirect()->route('studio.waiver', [
                    'attendee' => $attendee,
                    'waiver' => $e->context['waiver']->id,
                    'return' => $back,
                ])->with('warning', $e->getMessage()),
                'needs_plan' => view('studio.choose-plan', ['session' => $session, 'attendee' => $attendee, 'plans' => $e->context['plans'], 'apparatusId' => $apparatusId, 'online' => $online]),
                default => back()->withInput()->with('error', $e->getMessage()),
            };
        }

        $apparatus = $booking->apparatus;
        $message = match (true) {
            $booking->status === 'waitlisted' && $apparatus && $apparatusId => "Every {$apparatus->name} spot is taken, so {$attendee->first_name} is #{$booking->position} on the waitlist for one. We'll email you if a spot opens.",
            $booking->status === 'waitlisted' => "That class is full, so {$attendee->first_name} is #{$booking->position} on the waitlist. We'll email you if a spot opens.",
            $booking->attendsOnline() => "{$attendee->first_name} is booked online for {$session->program->title}! The link to join appears under Upcoming ".ClassSession::JOIN_OPENS_MINUTES.' minutes before class.',
            default => "{$attendee->first_name} is booked for {$session->program->title}".($apparatus ? " ({$booking->apparatusLabel()})" : '').'!',
        };

        return redirect()->route('studio.account')->with('success', $message);
    }

    /** Join an online class: from shortly before it starts until it ends. Joining checks them in. */
    public function join(Request $request, Studio $studio, Booking $booking, OnlineClasses $online): RedirectResponse
    {
        abort_unless((int) $booking->user_id === (int) $request->user()->id, 404);
        $result = $online->join($booking);

        return $result['url'] ? redirect()->away($result['url']) : redirect()->route('studio.account')->with('warning', $result['message']);
    }

    public function cancel(Request $request, Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        try {
            $result = $bookings->cancel($booking, $request->user()->id);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = match (true) {
            $result['makeup'] => 'Cancelled. You’ve got a make-up class to use within two weeks of the series ending: book any class with it.',
            $result['status'] === 'late_cancelled' && $booking->series_enrollment_id => 'Cancelled. It was inside the late-cancellation window, so there’s no make-up class for it.',
            $result['status'] === 'late_cancelled' && $booking->entitlement?->isCredits() && ! $result['credit_refunded'] => 'Cancelled. It was inside the late-cancellation window, so the class credit wasn’t returned.',
            $result['credit_refunded'] => 'Cancelled, and your class credit is back on your account.',
            default => 'Cancelled.',
        };

        return back()->with('success', $message);
    }
}
