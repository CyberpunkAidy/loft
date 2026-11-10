<?php

namespace App\Http\Controllers;

use App\Services\StudioBilling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PlatformController extends Controller
{
    /**
     * The marketing home page (decision 39). The savings calculator starts from a typical studio:
     * $199 a month of software that adds 1% to $10,000 a month of card sales. The page works it out
     * here so it reads right without JavaScript; resources/js keeps the same sum for the inputs.
     */
    public function home(StudioBilling $billing): View
    {
        $monthly = (int) config('loft.billing.monthly_cents') / 100;
        $fee = (float) config('loft.stripe.platform_fee_percent');
        $calc = ['software' => 199, 'percent' => 1, 'sales' => 10000];
        $now = 12 * ($calc['software'] + $calc['sales'] * $calc['percent'] / 100);
        $loft = 12 * ($monthly + $calc['sales'] * $fee / 100);

        return view('platform.home', [
            'trialDays' => $billing->trialDays(),
            'graceDays' => $billing->graceDays(),
            'price' => $billing->priceLabel(),
            'monthly' => $monthly,
            'currency' => strtoupper((string) config('loft.billing.currency')),
            'fee' => $fee,
            'supportEmail' => config('loft.support_email'),
            'calc' => $calc + ['now' => $now, 'loft' => $loft, 'saved' => $now - $loft],
        ]);
    }

    /** The Terms of Service, with the data processing terms (decision 38). */
    public function terms(StudioBilling $billing): View
    {
        return view('platform.legal.terms', $this->legal($billing));
    }

    /** The Privacy Policy (decision 38). */
    public function privacy(StudioBilling $billing): View
    {
        return view('platform.legal.privacy', $this->legal($billing));
    }

    /** @return array<string, mixed> */
    private function legal(StudioBilling $billing): array
    {
        $legal = config('loft.legal');

        return [
            'entity' => $legal['entity'],
            'state' => $legal['state'],
            'email' => $legal['email'],
            'termsDate' => Carbon::parse($legal['terms_version'])->format('F j, Y'),
            'privacyDate' => Carbon::parse($legal['privacy_version'])->format('F j, Y'),
            'price' => $billing->priceLabel(),
            'trialDays' => $billing->trialDays(),
            'graceDays' => $billing->graceDays(),
        ];
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
