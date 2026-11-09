<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AttendeeResource;
use App\Http\Resources\Api\SessionResource;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Studio;
use App\Services\BookingOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The schedule. Anyone can read it; with a token, each class also lists the member's bookings in
 * it ("mine"), and a class's own page says what each person on their account can do.
 */
class SessionController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,62'],
            'program_id' => ['nullable', 'integer'],
            'age_group' => ['nullable', 'string', 'max:60'],
            'level' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $from = isset($data['from']) ? $studio->parseLocal($data['from'].' 00:00:00') : $studio->localNow()->startOfDay();
        $to = $from->copy()->addDays((int) ($data['days'] ?? 14));

        $query = $studio->sessions()->withSeatCounts()->with(['program.categories', 'instructor', 'room'])
            ->where('status', 'scheduled')
            ->where('starts_at', '>=', $from->copy()->utc())
            ->where('starts_at', '<', $to->copy()->utc())
            ->whereHas('program', fn ($q) => $q->where('is_published', true))
            ->orderBy('starts_at')->orderBy('id');
        if (isset($data['program_id'])) {
            $query->where('program_id', $data['program_id']);
        }
        foreach (['age_group', 'level'] as $type) {
            if (! empty($data[$type])) {
                $query->whereHas('program.categories', fn ($q) => $q->where('type', $type)->where('slug', $data[$type]));
            }
        }
        $page = $query->cursorPaginate((int) ($data['per_page'] ?? 50))->withQueryString();
        $sessions = $page->getCollection()->each->setRelation('studio', $studio);
        ClassSession::loadApparatusSlots($sessions);
        $this->attachMine($request, $studio, $sessions);

        return SessionResource::collection($page);
    }

    /** One class; with a member's token, what each person on their account can do in it. */
    public function show(Request $request, Studio $studio, ClassSession $session, BookingOptions $options): JsonResponse
    {
        abort_unless($session->program->is_published, 404);
        $session->loadCount([
            'bookings as booked_count' => fn ($q) => $q->whereIn('status', Booking::SEAT_STATUSES),
            'bookings as waitlist_count' => fn ($q) => $q->where('status', 'waitlisted'),
        ])->load(['program.categories', 'instructor', 'room'])->setRelation('studio', $studio);
        $user = $request->user('sanctum');
        $this->attachMine($request, $studio, collect([$session]));

        $resource = new SessionResource($session);
        if ($user && $user->hasRoleIn($studio)) {
            $found = $options->for($studio, $session, $user);
            $resource->additional(['booking' => [
                'closed' => $found['closed_code'] !== null,
                'closed_code' => $found['closed_code'],
                'closed_message' => $found['closed'],
                'attendees' => $found['attendees']->map(fn (array $o) => $this->option($o, $found))->values(),
            ]]);
        }

        return $resource->response();
    }

    /** One person's line: can they book, and if not, why (a stable code, a message, the waiver to sign). */
    private function option(array $o, array $found): array
    {
        [$reason, $message] = match (true) {
            $o['booking'] !== null => ['already_booked', "{$o['attendee']->first_name} is already in this class."],
            $found['closed_code'] !== null => [$found['closed_code'], $found['closed']],
            $o['age_error'] !== null => ['age', $o['age_error']],
            $o['waiver'] !== null => ['waiver_required', "{$o['attendee']->first_name} needs to sign “{$o['waiver']->title}” first."],
            default => [null, null],
        };

        return [
            'attendee' => (new AttendeeResource($o['attendee']))->resolve(),
            'booking' => $o['booking'] ? ['id' => $o['booking']->id, 'status' => $o['booking']->status, 'position' => $o['booking']->position] : null,
            'can_book' => $reason === null,
            'reason' => $reason,
            'message' => $message,
            'waiver_id' => $o['waiver']?->id,
        ];
    }

    /** With a token: each session's bookings for the member, as its myBookings relation. */
    private function attachMine(Request $request, Studio $studio, $sessions): void
    {
        $user = $request->user('sanctum');
        if (! $user || $sessions->isEmpty()) {
            return;
        }
        $mine = $studio->bookings()->where('user_id', $user->id)->whereIn('class_session_id', $sessions->pluck('id'))
            ->whereIn('status', ['booked', 'waitlisted', 'attended'])->orderBy('id')->get()->groupBy('class_session_id');
        $sessions->each(fn (ClassSession $s) => $s->setRelation('myBookings', $mine->get($s->id, collect())));
    }
}
