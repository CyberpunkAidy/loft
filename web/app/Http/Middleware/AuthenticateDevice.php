<?php

namespace App\Http\Middleware;

use App\Models\AccessDevice;
use App\Support\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** device — a check-in kiosk or reader, signed in with its own token (not a member's). */
class AuthenticateDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = AccessDevice::findByToken($request->bearerToken());
        if (! $device) {
            throw new ApiException('unauthenticated', 'This device isn’t set up, or was removed. Add it again under Admin → Check-in devices.', 401);
        }
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }
        $request->attributes->set('access_device', $device);

        return $next($request);
    }
}
