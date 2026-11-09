<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\AccountDeletion;
use App\Services\Bookings;
use App\Services\Consent;
use App\Services\Stripe\StripeException;
use App\Services\Waivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
            ->with(['classSession.program', 'classSession.room', 'attendee', 'apparatus'])
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
            ->with(['classSession.program', 'classSession.room', 'attendee', 'apparatus'])
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
            'orders' => $studio->orders()->sales()->with('items')->where('user_id', $request->user()->id)->latest('paid_at')->latest('id')->limit(20)->get(),
        ]);
    }

    public function waivers(Request $request, Studio $studio, Waivers $waivers): View
    {
        $attendees = $this->attendees($request, $studio);

        $inForce = $studio->activeWaivers();

        return view('studio.account.waivers', [
            'waivers' => $inForce,
            'attendees' => $attendees,
            // attendee id => waiver id => status
            'statuses' => $attendees->mapWithKeys(fn ($a) => [$a->id => $inForce->mapWithKeys(fn ($w) => [$w->id => $waivers->statusOf($studio, $a, $w)])]),
        ]);
    }

    public function profile(Request $request, AccountDeletion $deletion): View
    {
        $user = $request->user();

        return view('studio.account.profile', [
            'user' => $user,
            'marketingEmail' => (bool) StudioUser::where('studio_id', $request->route('studio')->id)->where('user_id', $user->id)->first()?->allowsMarketingEmail(),
            // Studios they're the only owner of, each with the staff who could take it over.
            'ownedStudios' => $deletion->soleOwnerOf($user)
                ->map(fn (Studio $owned) => ['studio' => $owned, 'successors' => $deletion->successorsFor($owned, $user)]),
        ]);
    }

    public function updateProfile(Request $request, Studio $studio, Consent $consent): RedirectResponse
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
        if ($membership = StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->first()) {
            $consent->setMarketingEmail($membership, $request->boolean('marketing_email'), 'profile');
        }

        return back()->with('success', 'Profile saved.');
    }

    /**
     * Delete the account at every studio, after the user confirms their password. For each studio
     * they're the only owner of, they choose a staff member to take it over, or close it.
     */
    public function destroy(Request $request, Studio $studio, AccountDeletion $deletion): RedirectResponse
    {
        $user = $request->user();
        $rules = ['delete_password' => ['required', 'current_password']];
        foreach ($deletion->soleOwnerOf($user) as $owned) {
            $choices = $deletion->successorsFor($owned, $user)->pluck('user_id')->push(AccountDeletion::CLOSE)->map(fn ($v) => (string) $v);
            $rules["studios.{$owned->id}"] = ['required', Rule::in($choices->all())];
        }
        $data = $request->validate($rules, [
            'studios.*.required' => 'Choose who takes this studio over, or close it.',
            'studios.*.in' => 'Choose who takes this studio over, or close it.',
        ], ['delete_password' => 'password']);

        $closing = ($data['studios'][$studio->id] ?? null) === AccountDeletion::CLOSE;
        try {
            // A fresh copy, so logging out below doesn't save the deleted user back.
            $deletion->delete($user->fresh(), $data['studios'] ?? []);
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'We couldn’t cancel a membership in Stripe, so your account wasn’t deleted. Try again in a few minutes.');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // This studio's pages are gone if they just closed it.
        return redirect()->to($closing ? route('home') : route('studio.schedule', $studio))
            ->with('success', 'Your account has been deleted.');
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
