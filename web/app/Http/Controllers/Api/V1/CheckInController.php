<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\StudioResource;
use App\Models\AccessDevice;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\CheckInCodes;
use App\Services\CheckIns;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Contactless check-in over the API: kiosks and readers (with a device token) report scans; members
 * (with their own token) get the QR codes the app shows.
 */
class CheckInController extends Controller
{
    /** The device, and the studio it checks people in to. */
    public function device(Request $request): JsonResponse
    {
        $device = $this->accessDevice($request);

        return response()->json(['data' => [
            'id' => $device->id,
            'name' => $device->name,
            'studio' => (new StudioResource($device->studio))->resolve(),
        ]]);
    }

    /** A scan: always 200, with granted or denied and why (unless the request itself is wrong). */
    public function store(Request $request, CheckIns $checkIns): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'array'],
            'credential.kind' => ['required', Rule::in(['qr', 'nfc', 'rfid'])],
            'credential.value' => ['required', 'string', 'max:255'],
            'occurred_at' => ['nullable', 'date', 'after:-1 day', 'before:+5 minutes'],
        ], ['occurred_at.after' => 'Scans older than a day can’t be checked in.', 'occurred_at.before' => 'That time is in the future.']);
        $result = $checkIns->scan($this->accessDevice($request), $data['credential']['value'],
            isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : null);
        $session = $result->booking?->classSession;
        $studio = $this->accessDevice($request)->studio;

        return response()->json(['data' => [
            'result' => $result->granted ? 'granted' : 'denied',
            'reason' => $result->reason,
            'message' => $result->message,
            'attendee' => $result->attendee ? ['id' => $result->attendee->id, 'first_name' => $result->attendee->first_name, 'last_name' => $result->attendee->last_name] : null,
            'booking' => $result->booking ? [
                'id' => $result->booking->id,
                'status' => $result->booking->status,
                'session_id' => $session->id,
                'title' => $session->program->title,
                'starts_at' => $session->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'starts_at_local' => $studio->local($session->starts_at)->toIso8601String(),
            ] : null,
            'event_id' => $result->event->id,
        ]]);
    }

    /** A member's current check-in code for someone on their account, for the app to show as a QR code. */
    public function code(Request $request, Studio $studio, Attendee $attendee, CheckInCodes $codes): JsonResponse
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
        ['code' => $code, 'expires_at' => $expires] = $codes->current($attendee);

        return response()->json(['data' => [
            'attendee_id' => $attendee->id,
            'code' => $code,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'refresh_in' => max(1, $expires - time()),
        ]]);
    }

    private function accessDevice(Request $request): AccessDevice
    {
        return $request->attributes->get('access_device');
    }
}
