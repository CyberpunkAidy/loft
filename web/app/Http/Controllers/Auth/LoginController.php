<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Support\ReturnPath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** One login for the whole platform; /{studio}/login is the same form in the studio's branding. */
class LoginController extends Controller
{
    public function show(Request $request): View
    {
        $studio = $request->route('studio');
        if ($return = ReturnPath::from($request)) {
            $request->session()->put('url.intended', url($return)); // back to the page that sent them (a series, say)
        }

        return view('auth.login', ['studio' => $studio instanceof Studio ? $studio : null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'That email and password don’t match an account.']);
        }
        $request->session()->regenerate();

        $studio = Studio::where('slug', (string) $request->input('studio'))->first();
        $fallback = match (true) {
            $studio && $request->user()->hasRoleIn($studio, 'instructor') => route('studio.admin.dashboard', ['studio' => $studio]),
            $studio !== null => route('studio.account', ['studio' => $studio]),
            default => route('studios'),
        };

        return redirect()->intended($fallback);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(ReturnPath::from($request) ?? route('home'));
    }
}
