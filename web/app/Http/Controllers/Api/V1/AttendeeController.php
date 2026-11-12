<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AttendeeResource;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\PersonAnswers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * The people on the member's account who take classes: themselves, their children. Adding or editing
 * someone can answer the studio's questions too (decision 45). The app asks them when adding someone;
 * they're required before booking, not here, so an app that doesn't know about them can still add
 * people (booking then answers questions_required).
 */
class AttendeeController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        return AttendeeResource::collection($studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')
            ->orderByDesc('is_self')->orderBy('first_name')->get()->each->setRelation('studio', $studio));
    }

    public function store(Request $request, Studio $studio, PersonAnswers $answers): JsonResponse
    {
        $data = $request->validate([...Attendee::rules(), ...Attendee::answerRules($studio, required: false)], Attendee::answerMessages());
        $attendee = $answers->save($studio->attendees()->make(['user_id' => $request->user()->id]), ['is_self' => $request->boolean('is_self')] + $data, $request->user());

        return (new AttendeeResource($attendee->setRelation('studio', $studio)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Studio $studio, Attendee $attendee, PersonAnswers $answers): AttendeeResource
    {
        $this->authorizeOwner($request, $attendee);
        // Only what's sent changes; "yes" to the health question still needs the details with it.
        $rules = collect([...Attendee::rules(), ...Attendee::answerRules($studio, required: false)])
            ->map(fn ($r, $field) => $field === 'medical_notes' ? $r : ['sometimes', ...$r])->all();
        $answers->save($attendee, $request->validate($rules, Attendee::answerMessages()), $request->user());

        return new AttendeeResource($attendee->setRelation('studio', $studio));
    }

    /** Remove someone from the account (kept for the studio's records, as on the website). */
    public function destroy(Request $request, Studio $studio, Attendee $attendee): Response
    {
        $this->authorizeOwner($request, $attendee);
        $attendee->update(['status' => 'archived']);

        return response()->noContent();
    }

    private function authorizeOwner(Request $request, Attendee $attendee): void
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
    }
}
