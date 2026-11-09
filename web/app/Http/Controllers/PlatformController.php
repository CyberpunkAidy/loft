<?php

namespace App\Http\Controllers;

use App\Services\StudioBilling;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlatformController extends Controller
{
    public function home(StudioBilling $billing): View
    {
        return view('platform.home', ['trialDays' => $billing->trialDays(), 'price' => $billing->priceLabel()]);
    }

    /** The price of Loft for a studio (decision 36). */
    public function pricing(StudioBilling $billing): View
    {
        return view('platform.pricing', [
            'price' => $billing->priceLabel(),
            'trialDays' => $billing->trialDays(),
            'graceDays' => $billing->graceDays(),
            'fee' => (float) config('loft.stripe.platform_fee_percent'),
            'videoHours' => intdiv((int) config('loft.mux.studio_minutes'), 60),
        ]);
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
