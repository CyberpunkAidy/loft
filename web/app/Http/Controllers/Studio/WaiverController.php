<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Models\Waiver;
use App\Services\Bookings;
use App\Services\Waivers;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Sign a waiver for one attendee (the plugin's [loft_waiver]). ?waiver= picks one; without it, the
 * page shows the next waiver in force that the attendee hasn't signed.
 */
class WaiverController extends Controller
{
    public function show(Request $request, Studio $studio, Attendee $attendee, Waivers $waivers): View|RedirectResponse
    {
        $this->authorizeAttendee($request, $attendee);
        $waiver = $this->chosen($request, $studio) ?? $waivers->nextToSign($studio, $attendee);
        if (! $waiver) {
            return redirect(ReturnPath::from($request) ?? route('studio.account.waivers'))->with('success', "{$attendee->first_name} has signed everything needed.");
        }

        return view('studio.waiver', [
            'waiver' => $waiver,
            'attendee' => $attendee,
            'status' => $waivers->statusOf($studio, $attendee, $waiver),
            'isMinor' => $attendee->isMinor((int) $studio->setting('minor_age')),
            'return' => ReturnPath::from($request),
        ]);
    }

    public function sign(Request $request, Studio $studio, Attendee $attendee, Waivers $waivers, Bookings $bookings): RedirectResponse
    {
        $this->authorizeAttendee($request, $attendee);
        $data = $request->validate([
            'waiver_id' => ['required', 'integer'],
            'signed_name' => ['required', 'string', 'min:3', 'max:190'],
            'agree' => ['accepted'],
            'signature_png' => ['nullable', 'string', 'max:700000'],
        ], [
            'signed_name.required' => 'Type your full legal name.',
            'agree.accepted' => 'Tick the box to confirm you agree.',
        ]);
        $waiver = $studio->activeWaivers()->firstWhere('id', (int) $data['waiver_id']);
        if (! $waiver) {
            return redirect()->route('studio.account.waivers')->with('warning', 'That waiver isn’t in force any more.');
        }

        $waivers->sign($studio, $attendee, $waiver, $request->user(), trim($data['signed_name']), $data['signature_png'] ?? null, $request->ip(), $request->userAgent());
        $bookings->promoteAfterSigning($attendee); // a waitlist spot may have been waiting on this

        // More to sign? Carry on to the next one, then back to where they came from.
        $return = ReturnPath::from($request);
        if ($next = $waivers->nextToSign($studio, $attendee)) {
            return redirect()->route('studio.waiver', array_filter(['attendee' => $attendee, 'waiver' => $next->id, 'return' => $return]))
                ->with('success', "Signed “{$waiver->title}” for {$attendee->first_name}. One more to go.");
        }

        return redirect($return ?? route('studio.account.waivers'))->with('success', "Waiver signed for {$attendee->first_name}. Thank you!");
    }

    private function authorizeAttendee(Request $request, Attendee $attendee): void
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
    }

    private function chosen(Request $request, Studio $studio): ?Waiver
    {
        return $request->filled('waiver') ? $studio->activeWaivers()->firstWhere('id', $request->integer('waiver')) : null;
    }
}
