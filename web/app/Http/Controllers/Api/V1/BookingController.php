<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookingResource;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Services\Bookings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The member's bookings: list them, book (POST, with an Idempotency-Key), cancel. Booking rules are
 * App\Services\Bookings', the same as the website's; when one says no, the error code says why
 * (waiver_required, needs_plan, full, closed, age…).
 */
class BookingController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        $data = $request->validate(['when' => ['nullable', Rule::in(['upcoming', 'past'])], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $upcoming = ($data['when'] ?? 'upcoming') === 'upcoming';
        $query = $studio->bookings()->where('bookings.user_id', $request->user()->id)
            ->join('class_sessions as session', 'session.id', '=', 'bookings.class_session_id')
            ->select('bookings.*', 'session.starts_at as session_starts_at')
            ->with(['attendee', 'apparatus', 'classSession.program', 'classSession.room']);
        if ($upcoming) {
            $query->where('session.starts_at', '>=', now())->whereIn('bookings.status', ['booked', 'waitlisted'])
                ->orderBy('session_starts_at')->orderBy('bookings.id');
        } else {
            $query->where('session.starts_at', '<', now())->orderByDesc('session_starts_at')->orderByDesc('bookings.id');
        }
        $page = $query->cursorPaginate((int) ($data['per_page'] ?? 50))->withQueryString();
        $page->getCollection()->each(fn (Booking $b) => $b->classSession->setRelation('studio', $studio));

        return BookingResource::collection($page);
    }

    public function store(Request $request, Studio $studio, ClassSession $session, Bookings $bookings): JsonResponse
    {
        abort_unless($session->program->is_published, 404);
        $data = $request->validate(['attendee_id' => ['required', 'integer'], 'apparatus_id' => ['nullable', 'integer']]);
        $attendee = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')->find($data['attendee_id'])
            ?? throw ValidationException::withMessages(['attendee_id' => 'That person isn’t on your account.']);

        $booking = $bookings->book($session, $attendee, $request->user()->id, apparatusId: ($data['apparatus_id'] ?? null) ? (int) $data['apparatus_id'] : null);
        $booking->load(['attendee', 'apparatus', 'classSession.program', 'classSession.room'])->classSession->setRelation('studio', $studio);
        $message = $booking->status === 'waitlisted'
            ? "That class is full, so {$attendee->first_name} is #{$booking->position} on the waitlist. We’ll email you if a spot opens."
            : "{$attendee->first_name} is booked for {$session->program->title}!";

        return (new BookingResource($booking))->additional(['message' => $message])->response()->setStatusCode(201);
    }

    public function destroy(Request $request, Studio $studio, Booking $booking, Bookings $bookings): JsonResponse
    {
        $result = $bookings->cancel($booking, $request->user()->id);
        $booking = $booking->fresh(['attendee', 'apparatus', 'classSession.program', 'classSession.room']);
        $booking->classSession->setRelation('studio', $studio);
        $message = match (true) {
            $result['status'] === 'late_cancelled' && $booking->entitlement?->isCredits() && ! $result['credit_refunded'] => 'Cancelled. It was inside the late-cancellation window, so the class credit wasn’t returned.',
            $result['credit_refunded'] => 'Cancelled, and your class credit is back on your account.',
            default => 'Cancelled.',
        };

        return (new BookingResource($booking))->additional(['credit_refunded' => (bool) $result['credit_refunded'], 'message' => $message])->response();
    }
}
