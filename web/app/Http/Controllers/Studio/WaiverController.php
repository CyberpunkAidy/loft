<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Attendee;
use App\Models\Studio;
use App\Services\Waivers;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Sign the studio's active waiver for one attendee (the plugin's [loft_waiver]). */
class WaiverController extends Controller
{
    public function show(Request $request, Studio $studio, Attendee $attendee, Waivers $waivers): View|RedirectResponse
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
        $waiver = $studio->activeWaiver();
        if (! $waiver) {
            return redirect(ReturnPath::from($request) ?? route('studio.account.waivers'))->with('success', 'No waiver is required right now.');
        }

        return view('studio.waiver', [
            'waiver' => $waiver,
            'attendee' => $attendee,
            'status' => $waivers->statusFor($studio, $attendee),
            'isMinor' => $attendee->isMinor((int) $studio->setting('minor_age')),
            'return' => ReturnPath::from($request),
        ]);
    }

    public function sign(Request $request, Studio $studio, Attendee $attendee, Waivers $waivers): RedirectResponse
    {
        abort_unless((int) $attendee->user_id === (int) $request->user()->id && $attendee->status === 'active', 404);
        $data = $request->validate([
            'signed_name' => ['required', 'string', 'min:3', 'max:190'],
            'agree' => ['accepted'],
            'signature_png' => ['nullable', 'string', 'max:700000'],
        ], [
            'signed_name.required' => 'Type your full legal name.',
            'agree.accepted' => 'Tick the box to confirm you agree.',
        ]);

        $signature = $waivers->sign($studio, $attendee, $request->user(), trim($data['signed_name']), $data['signature_png'] ?? null, $request->ip(), $request->userAgent());
        if (! $signature) {
            return redirect()->route('studio.account.waivers')->with('success', 'No waiver is required right now.');
        }

        return redirect(ReturnPath::from($request) ?? route('studio.account.waivers'))
            ->with('success', "Waiver signed for {$attendee->first_name}. Thank you!");
    }
}
