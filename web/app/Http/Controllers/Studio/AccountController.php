<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Waivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** The member's account (the plugin's [loft_account] tabs). */
class AccountController extends Controller
{
    public function upcoming(Request $request, Studio $studio, Bookings $bookings): View
    {
        $list = $studio->bookings()
            ->with(['classSession.program', 'attendee'])
            ->where('user_id', $request->user()->id)
            ->whereIn('status', Booking::ACTIVE_STATUSES)
            ->whereHas('classSession', fn ($q) => $q->where('ends_at', '>=', now()))
            ->get()
            ->sortBy(fn (Booking $b) => $b->classSession->starts_at)
            ->values()
            ->each(fn (Booking $b) => $b->classSession->setRelation('studio', $studio));

        return view('studio.account.upcoming', [
            'bookings' => $list,
            'late' => $list->mapWithKeys(fn (Booking $b) => [$b->id => $b->status === 'booked' && $bookings->isLate($b->classSession)]),
        ]);
    }

    public function history(Request $request, Studio $studio): View
    {
        $list = $studio->bookings()
            ->with(['classSession.program', 'attendee'])
            ->where('user_id', $request->user()->id)
            ->where(fn ($q) => $q->whereNotIn('status', Booking::ACTIVE_STATUSES)
                ->orWhereHas('classSession', fn ($s) => $s->where('ends_at', '<', now())))
            ->latest('booked_at')
            ->limit(100)
            ->get();

        return view('studio.account.history', ['bookings' => $list]);
    }

    public function family(Request $request, Studio $studio, Waivers $waivers): View
    {
        $attendees = $this->attendees($request, $studio);

        return view('studio.account.family', [
            'attendees' => $attendees,
            'waiverStatus' => $attendees->mapWithKeys(fn ($a) => [$a->id => $waivers->statusFor($studio, $a)]),
            'minorAge' => (int) $studio->setting('minor_age'),
        ]);
    }

    public function billing(Request $request, Studio $studio): View
    {
        $membership = $request->user()->membershipIn($studio);

        return view('studio.account.billing', [
            'entitlements' => $studio->entitlements()->with('plan')
                ->where('user_id', $request->user()->id)
                ->where(fn ($q) => $q->whereIn('status', ['active', 'past_due'])->orWhere('updated_at', '>=', now()->subDays(60)))
                ->latest()
                ->get(),
            'canOpenPortal' => $studio->stripeReady() && $membership?->stripe_customer_id,
        ]);
    }

    public function waivers(Request $request, Studio $studio, Waivers $waivers): View
    {
        $attendees = $this->attendees($request, $studio);

        return view('studio.account.waivers', [
            'waiver' => $studio->activeWaiver(),
            'attendees' => $attendees,
            'waiverStatus' => $attendees->mapWithKeys(fn ($a) => [$a->id => $waivers->statusFor($studio, $a)]),
        ]);
    }

    public function profile(Request $request): View
    {
        return view('studio.account.profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
        ]);
        $user->fill([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => strtolower($data['email']),
            'phone' => $data['phone'] ?? null,
        ]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        return back()->with('success', 'Profile saved.');
    }

    private function attendees(Request $request, Studio $studio)
    {
        return $studio->attendees()
            ->where('user_id', $request->user()->id)
            ->where('status', 'active')
            ->orderByDesc('is_self')->orderBy('first_name')
            ->get();
    }
}
