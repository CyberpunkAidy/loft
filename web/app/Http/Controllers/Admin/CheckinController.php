<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Waivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/** Tablet check-in for instructors (the plugin's [loft_checkin]). */
class CheckinController extends Controller
{
    public function index(Request $request, Studio $studio, Waivers $waivers): View
    {
        $date = $this->date($request, $studio);
        $sessions = $studio->sessions()->withSeatCounts()->with('program')
            ->where('starts_at', '>=', $date->copy()->utc())
            ->where('starts_at', '<', $date->copy()->addDay()->utc())
            ->orderBy('starts_at')->get();

        $selected = $sessions->firstWhere('id', (int) $request->query('session'))
            ?? $sessions->first(fn ($s) => $s->ends_at->isFuture() && ! $s->isCancelled())
            ?? $sessions->first();

        $roster = $selected
            ? $selected->bookings()->with(['attendee', 'user'])->whereNot('status', 'cancelled')
                ->orderByRaw("case status when 'waitlisted' then 2 when 'late_cancelled' then 3 else 1 end")
                ->orderBy('position')->orderBy('booked_at')->get()
            : collect();

        return view('admin.checkin', [
            'date' => $date,
            'sessions' => $sessions,
            'selected' => $selected,
            'roster' => $roster,
            'waiverOk' => $roster->mapWithKeys(fn (Booking $b) => [$b->id => $waivers->isSigned($studio, $b->attendee)]),
        ]);
    }

    public function mark(Request $request, Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:attended,no_show,booked']]);
        if (! $bookings->mark($booking, $data['status'])) {
            return back()->with('error', 'That booking can’t be checked in.');
        }

        return back();
    }

    private function date(Request $request, Studio $studio): Carbon
    {
        $raw = (string) $request->query('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return Carbon::parse($raw, $studio->timezone)->startOfDay();
        }

        return $studio->localNow()->startOfDay();
    }
}
