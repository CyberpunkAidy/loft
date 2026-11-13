<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\Memberships;
use App\Services\Texts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Become a member of a studio: a new account, or one click for someone already signed in. (The plugin's [loft_register].) */
class JoinController extends Controller
{
    public function create(Request $request, Studio $studio): View|RedirectResponse
    {
        if ($request->user()?->hasRoleIn($studio)) {
            return redirect()->route('studio.account');
        }

        return view('studio.join');
    }

    public function store(Request $request, Studio $studio, Memberships $memberships): RedirectResponse
    {
        if ($user = $request->user()) {
            $memberships->join($studio, $user, $request->boolean('marketing_email'));

            return redirect()->intended(route('studio.account.family'))->with('success', "You're a member of {$studio->name}.");
        }

        if ($request->filled('website')) { // honeypot
            return back()->withErrors(['email' => 'Something went wrong. Please try again.']);
        }
        $data = $request->validate(Memberships::registrationRules(), ['email.unique' => 'An account with that email already exists. Sign in to join.']);
        $texts = $studio->textsReady() && $request->boolean('text_updates');
        if ($texts) {
            Texts::assertTextable($data['phone'] ?? null);
        }
        $user = $memberships->register($studio, $data, $request->boolean('marketing_email'), $texts);

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('studio.account.family'))->with('success', 'Your account is ready. Add everyone who’ll be taking classes.');
    }
}
