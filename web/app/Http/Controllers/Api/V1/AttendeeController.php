<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\AttendeeResource;
use App\Models\Attendee;
use App\Models\Studio;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** The people on the member's account who take classes: themselves, their children. */
class AttendeeController extends Controller
{
    public function index(Request $request, Studio $studio): AnonymousResourceCollection
    {
        return AttendeeResource::collection($studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')
            ->orderByDesc('is_self')->orderBy('first_name')->get()->each->setRelation('studio', $studio));
    }

    public function store(Request $request, Studio $studio): JsonResponse
    {
        $data = $request->validate(Attendee::rules());
        $attendee = $studio->attendees()->create(['is_self' => $request->boolean('is_self')] + $data + ['user_id' => $request->user()->id]);

        return (new AttendeeResource($attendee->setRelation('studio', $studio)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Studio $studio, Attendee $attendee): AttendeeResource
    {
        $this->authorizeOwner($request, $attendee);
        $rules = collect(Attendee::rules())->map(fn ($r) => ['sometimes', ...$r])->all();
        $attendee->update($request->validate($rules));

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
