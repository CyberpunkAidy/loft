<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\DeviceResource;
use App\Models\UserDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** The app's push notification token for this install (sending notifications comes later). */
class DeviceController extends Controller
{
    /** Register or refresh a push token. A token seen on another account moves to this one. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(UserDevice::PLATFORMS)],
            'token' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:100'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);
        $device = UserDevice::updateOrCreate(['token' => $data['token']], [
            'user_id' => $request->user()->id,
            'platform' => $data['platform'],
            'name' => $data['name'] ?? null,
            'app_version' => $data['app_version'] ?? null,
            'last_seen_at' => now(),
        ]);

        return (new DeviceResource($device))->response()->setStatusCode($device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, UserDevice $device): Response
    {
        abort_unless($device->user_id === $request->user()->id, 404);
        $device->delete();

        return response()->noContent();
    }
}
