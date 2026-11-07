<?php

namespace App\Http\Controllers\Studio;

use App\Events\MemberJoined;
use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
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

    public function store(Request $request, Studio $studio): RedirectResponse
    {
        if ($user = $request->user()) {
            if (! $user->hasRoleIn($studio)) {
                $studio->studioUsers()->firstOrCreate(['user_id' => $user->id], ['role' => 'member']);
                $user->forgetMemberships();
                MemberJoined::dispatch($studio, $user);
            }

            return redirect()->intended(route('studio.account.family'))->with('success', "You're a member of {$studio->name}.");
        }

        if ($request->filled('website')) { // honeypot
            return back()->withErrors(['email' => 'Something went wrong. Please try again.']);
        }
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', Password::min(8)],
            'is_student' => ['nullable', 'boolean'],
        ], ['email.unique' => 'An account with that email already exists. Sign in to join.']);

        $user = DB::transaction(function () use ($studio, $data) {
            $user = User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
            ]);
            $studio->studioUsers()->create(['user_id' => $user->id, 'role' => 'member']);
            if (! empty($data['is_student'])) {
                $studio->attendees()->create([
                    'user_id' => $user->id,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'is_self' => true,
                ]);
            }

            return $user;
        });

        Auth::login($user, true);
        $request->session()->regenerate();
        MemberJoined::dispatch($studio, $user);

        return redirect()->intended(route('studio.account.family'))->with('success', 'Your account is ready. Add everyone who’ll be taking classes.');
    }
}
