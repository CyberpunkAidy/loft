<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\User;
use App\Services\MemberInvitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * /{studio}/welcome/{member}: where an invitation to a member imported from another system leads
 * (decision 37). The link is signed, lasts 30 days, and stops working once the password changes.
 */
class WelcomeController extends Controller
{
    public function show(Request $request, Studio $studio, User $member): View|RedirectResponse
    {
        return $this->stale($request, $studio, $member) ?? view('studio.welcome', ['member' => $member]);
    }

    public function store(Request $request, Studio $studio, User $member, MemberInvitations $invitations): RedirectResponse
    {
        if ($stale = $this->stale($request, $studio, $member)) {
            return $stale;
        }
        $data = $request->validate(['password' => ['required', 'confirmed', Password::min(8)]]);

        $member->forceFill([
            'password' => $data['password'],
            'email_verified_at' => $member->email_verified_at ?? now(), // the invitation reached them
        ])->setRememberToken(Str::random(60));
        $member->save();
        $invitations->accepted($studio, $member);

        Auth::login($member, true);
        $request->session()->regenerate();

        return redirect()->route('studio.account', ['studio' => $studio])->with('success', "You’re all set. Welcome to {$studio->name}!");
    }

    /** A link that expired, was tampered with, or was already used goes to sign-in instead. */
    private function stale(Request $request, Studio $studio, User $member): ?RedirectResponse
    {
        if (! $request->hasValidSignature()) {
            return redirect()->route('studio.login', ['studio' => $studio])
                ->with('warning', 'That invitation link has expired. Choose “Forgot your password?” below to get a new one.');
        }
        if (! hash_equals(MemberInvitations::fingerprint($member), (string) $request->query('v'))) {
            return redirect()->route('studio.login', ['studio' => $studio])
                ->with('warning', 'You’ve already chosen a password with that link. Sign in below, or choose “Forgot your password?”.');
        }

        return null;
    }
}
