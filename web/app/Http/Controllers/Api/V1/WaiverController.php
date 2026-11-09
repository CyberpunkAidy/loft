<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Waivers;
use App\Support\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Waivers in force, where each person on the account stands, and signing one. */
class WaiverController extends Controller
{
    public function index(Request $request, Studio $studio, Waivers $waivers): JsonResponse
    {
        $attendees = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')->orderByDesc('is_self')->orderBy('first_name')->get();

        return response()->json(['data' => $studio->activeWaivers()->map(fn ($waiver) => [
            'id' => $waiver->id,
            'title' => $waiver->title,
            'version' => $waiver->version,
            'body' => $waiver->body,
            'applies_to_every_class' => $waiver->appliesToEveryClass(),
            'programs' => $waiver->programs->map(fn ($p) => ['id' => $p->id, 'title' => $p->title])->values(),
            'attendees' => $attendees->map(function (Attendee $attendee) use ($studio, $waiver, $waivers) {
                $status = $waivers->statusOf($studio, $attendee, $waiver);

                return [
                    'attendee_id' => $attendee->id,
                    'signed' => $status['signed'],
                    'expired' => $status['expired'],
                    'signed_at' => $status['signed_at']?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'expires_at' => $status['expires_at']?->utc()->format('Y-m-d\TH:i:s\Z'),
                    'needed' => ! $status['signed'] && $waivers->needs($attendee, $waiver),
                ];
            })->values(),
        ])->values()]);
    }

    /** Sign a waiver for someone on the account. A waitlist spot waiting on it may go through. */
    public function store(Request $request, Studio $studio, Attendee $attendee, Waivers $waivers, Bookings $bookings): JsonResponse
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
        $data = $request->validate([
            'waiver_id' => ['required', 'integer'],
            'signed_name' => ['required', 'string', 'min:3', 'max:190'],
            'agree' => ['accepted'],
            'signature_png' => ['nullable', 'string', 'max:700000'],
        ], ['signed_name.required' => 'Type your full legal name.', 'agree.accepted' => 'Confirm you agree.']);
        $waiver = $studio->activeWaivers()->firstWhere('id', (int) $data['waiver_id'])
            ?? throw new ApiException('waiver_not_in_force', 'That waiver isn’t in force any more.', 422);

        $signature = $waivers->sign($studio, $attendee, $waiver, $request->user(), trim($data['signed_name']), $data['signature_png'] ?? null, $request->ip(), $request->userAgent());
        $bookings->promoteAfterSigning($attendee);

        return response()->json([
            'data' => [
                'id' => $signature->id,
                'waiver_id' => $waiver->id,
                'waiver_version' => $signature->waiver_version,
                'attendee_id' => $attendee->id,
                'signed_at' => $signature->signed_at->utc()->format('Y-m-d\TH:i:s\Z'),
            ],
            'next_waiver_id' => $waivers->nextToSign($studio, $attendee)?->id,
        ], 201);
    }
}
