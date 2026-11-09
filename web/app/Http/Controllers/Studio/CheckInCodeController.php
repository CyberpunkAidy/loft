<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\CheckInCodes;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** My account → Check-in: a QR code for each person on the account, to scan at the kiosk. */
class CheckInCodeController extends Controller
{
    public function show(Request $request, Studio $studio, CheckInCodes $codes): View
    {
        $attendees = $studio->attendees()->where('user_id', $request->user()->id)->where('status', 'active')->orderByDesc('is_self')->orderBy('first_name')->get();
        $now = time();

        return view('studio.account.check-in', [
            'codes' => $attendees->map(function ($attendee) use ($codes, $now) {
                $current = $codes->current($attendee, $now);

                return ['attendee' => $attendee, 'svg' => $codes->svg($current['code'])];
            }),
            // Reload a little before each code changes (they're accepted for about a minute either side).
            'refresh' => max(5, (intdiv($now, CheckInCodes::STEP) + 1) * CheckInCodes::STEP - $now + 1),
        ]);
    }
}
