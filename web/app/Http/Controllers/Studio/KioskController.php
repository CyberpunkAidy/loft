<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\AccessDevice;
use App\Models\Studio;
use App\Services\CheckIns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The check-in kiosk: a browser on a tablet or computer at the door, paired once with a device code
 * from Admin → Check-in devices (kept in an encrypted cookie; no one stays signed in). It works with
 * NFC/RFID readers and QR scanners that type what they read and press Enter, as most USB and
 * Bluetooth ones do: every button is a plain form.
 */
class KioskController extends Controller
{
    public function show(Request $request, Studio $studio): View
    {
        $device = $this->device($request, $studio);

        return $device
            ? view('studio.kiosk', ['device' => $device, 'result' => $request->session()->get('kiosk_result')])
            : view('studio.kiosk-pair');
    }

    public function pair(Request $request, Studio $studio): RedirectResponse
    {
        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:100']])['code']);
        $device = AccessDevice::findByToken($code);
        if (! $device || $device->studio_id !== $studio->id) {
            return back()->withErrors(['code' => 'That device code isn’t right, or the device was removed.']);
        }

        return redirect()->route('studio.kiosk')->withCookie(cookie()->forever($this->cookie($studio), $code, null, null, null, true, false, 'lax'));
    }

    public function scan(Request $request, Studio $studio, CheckIns $checkIns): RedirectResponse
    {
        $device = $this->device($request, $studio);
        if (! $device) {
            return redirect()->route('studio.kiosk');
        }
        $value = trim((string) $request->validate(['credential' => ['required', 'string', 'max:255']])['credential']);
        $result = $checkIns->scan($device, $value);

        return redirect()->route('studio.kiosk')->with('kiosk_result', [
            'granted' => $result->granted,
            'reason' => $result->reason,
            'message' => $result->message,
            'name' => $result->attendee?->first_name,
        ]);
    }

    public function unpair(Studio $studio): RedirectResponse
    {
        return redirect()->route('studio.kiosk')->withCookie(cookie()->forget($this->cookie($studio)));
    }

    private function device(Request $request, Studio $studio): ?AccessDevice
    {
        $device = AccessDevice::findByToken($request->cookie($this->cookie($studio)));
        if (! $device || $device->studio_id !== $studio->id) {
            return null;
        }
        if (! $device->last_seen_at || $device->last_seen_at->lt(now()->subMinute())) {
            $device->forceFill(['last_seen_at' => now()])->save();
        }

        return $device;
    }

    private function cookie(Studio $studio): string
    {
        return "loft_kiosk_{$studio->id}";
    }
}
