<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Studio;
use App\Models\WaiverOverride;
use App\Services\Bookings;
use App\Services\Waivers;
use App\Support\BookingException;
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
            ? $selected->bookings()->with(['attendee', 'user', 'apparatus'])->whereNot('status', 'cancelled')
                ->orderByRaw("case status when 'waitlisted' then 2 when 'late_cancelled' then 3 else 1 end")
                ->orderBy('position')->orderBy('booked_at')->get()
            : collect();

        return view('admin.checkin', [
            'date' => $date,
            'sessions' => $sessions,
            'selected' => $selected,
            'roster' => $roster,
            'waiverOk' => $roster->mapWithKeys(fn (Booking $b) => [$b->id => $waivers->clears($b)]),
            'overrides' => WaiverOverride::whereIn('booking_id', $roster->pluck('id'))->pluck('reason', 'booking_id'),
        ]);
    }

    public function mark(Request $request, Studio $studio, Booking $booking, Bookings $bookings): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:attended,no_show,booked'],
            'override_reason' => ['nullable', 'string', 'max:190'],
        ]);
        $override = filled($data['override_reason'] ?? null) ? ['staff' => $request->user(), 'reason' => trim($data['override_reason'])] : null;
        try {
            $marked = $bookings->mark($booking, $data['status'], $override);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }
        if (! $marked) {
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
