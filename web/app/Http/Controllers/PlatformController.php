<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function home(): View
    {
        return view('platform.home');
    }

    /** Every studio the signed-in user belongs to, staff studios first. */
    public function studios(Request $request): View
    {
        $memberships = $request->user()->studioUsers()->with('studio')->get()
            ->sortByDesc(fn ($m) => [$m->isStaff(), $m->created_at])
            ->values();

        return view('platform.studios', ['memberships' => $memberships]);
    }
}
