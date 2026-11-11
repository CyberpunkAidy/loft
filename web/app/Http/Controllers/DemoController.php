<?php

namespace App\Http\Controllers;

use App\Services\Demo\DemoStudios;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Try the demo" (decision 44): /demo explains it; starting one signs the visitor in as the owner of
 * their own copy of the sample studio. They can switch to its sample member and back, and leave to
 * start a real studio.
 */
class DemoController extends Controller
{
    public function show(Request $request, DemoStudios $demos): View
    {
        return view('platform.demo', ['current' => $demos->fromCookie($request), 'hours' => $demos->hours()]);
    }

    public function store(Request $request, DemoStudios $demos): RedirectResponse
    {
        if (! $demos->hasRoom()) {
            return back()->with('warning', 'Lots of people are trying Loft right now. Please try the demo again in a little while.');
        }
        $demo = $demos->start();

        // Signed out of any other account first: the demo is its own sign-in.
        Auth::logout();
        $request->session()->invalidate();
        Auth::login($demo['owner'], true);
        $request->session()->regenerate();

        return redirect()->route('studio.admin.dashboard', ['studio' => $demo['studio']])
            ->with('success', 'This is your own demo studio, with a month of classes, members and sales. Change anything you like.')
            ->withCookie($demos->cookie($demo));
    }

    /** Owner ⇄ member, only for the browser that started the demo. */
    public function switch(Request $request, DemoStudios $demos): RedirectResponse
    {
        $demo = $demos->fromCookie($request);
        $me = $request->user();
        if (! $demo || ! $me || ! in_array($me->id, [$demo['owner']->id, $demo['member']->id], true)) {
            return redirect()->route('demo');
        }
        $toMember = $me->id === $demo['owner']->id;
        Auth::login($toMember ? $demo['member'] : $demo['owner'], true);
        $request->session()->regenerate();

        return $toMember
            ? redirect()->route('studio.schedule', ['studio' => $demo['studio']])
                ->with('success', 'You’re now Priya Shah, a member with two kids and a membership. Book a class, sign the waiver, or see your check-in code.')
            : redirect()->route('studio.admin.dashboard', ['studio' => $demo['studio']])->with('success', 'You’re back as the owner.');
    }

    /** "Start your own studio": out of the demo account, on to the real signup. */
    public function leave(Request $request): RedirectResponse
    {
        if ($request->user()?->isDemo()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('signup');
    }
}
