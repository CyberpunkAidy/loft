<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessCredential;
use App\Models\AccessDevice;
use App\Models\Studio;
use App\Models\User;
use App\Services\CheckIns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Check-in devices: the studio's kiosks and readers (each with its own code), and every scan.
 * Tags (cards, fobs) are given out from a member's page.
 */
class AccessController extends Controller
{
    public function index(Request $request, Studio $studio): View
    {
        return view('admin.access', [
            'devices' => $studio->accessDevices()->orderByRaw('revoked_at is not null')->orderBy('name')->get(),
            'events' => $studio->accessEvents()->with(['device', 'attendee', 'booking.classSession.program'])
                ->when($request->query('result'), fn ($q, $result) => $q->where('result', $result === 'denied' ? 'denied' : 'granted'))
                ->latest('occurred_at')->latest('id')->paginate(50)->withQueryString(),
            'newDevice' => $request->session()->get('new_device'),
        ]);
    }

    /** Add a kiosk or reader. Its code is shown once, now. */
    public function storeDevice(Request $request, Studio $studio): RedirectResponse
    {
        $name = trim((string) $request->validate(['name' => ['required', 'string', 'max:80']])['name']);
        [$device, $token] = AccessDevice::issue($studio, $name, $request->user());

        return redirect()->route('studio.admin.access.index')->with('new_device', ['id' => $device->id, 'name' => $device->name, 'token' => $token]);
    }

    /** Stop a device: its code stops working at once, on the kiosk page and the API. */
    public function revokeDevice(Studio $studio, AccessDevice $accessDevice): RedirectResponse
    {
        $accessDevice->update(['revoked_at' => now()]);

        return back()->with('success', "{$accessDevice->name} was removed. Its code no longer works.");
    }

    /** Give someone on a member's account a tag (scan it into the box, or type its number). */
    public function storeTag(Request $request, Studio $studio, User $member, CheckIns $checkIns): RedirectResponse
    {
        $data = $request->validate([
            'attendee_id' => ['required', 'integer'],
            'uid' => ['required', 'string', 'max:100'],
            'label' => ['nullable', 'string', 'max:60'],
        ]);
        $attendee = $studio->attendees()->where('user_id', $member->id)->where('status', 'active')->findOrFail($data['attendee_id']);
        try {
            $tag = $checkIns->addTag($attendee, $data['uid'], $data['label'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "Tag …{$tag->value_last4} is now {$attendee->first_name}’s.");
    }

    public function revokeTag(Studio $studio, AccessCredential $accessCredential): RedirectResponse
    {
        $accessCredential->update(['revoked_at' => now()]);

        return back()->with('success', "Tag …{$accessCredential->value_last4} no longer works.");
    }
}
