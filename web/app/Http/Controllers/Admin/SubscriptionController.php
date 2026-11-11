<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\Referrals;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Services\StudioBilling;
use App\Support\BillingState;
use App\Support\CheckoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin → Subscription: the studio's own Loft subscription (decision 36). Every staff member can
 * see where it stands (it's the one page left when the studio is paused); owners subscribe through
 * Stripe Checkout, monthly or yearly (decision 40), and manage the card and plan in Stripe's
 * billing portal.
 */
class SubscriptionController extends Controller
{
    public function show(Request $request, Studio $studio, StudioBilling $billing, StripeClient $stripe, Referrals $referrals): View
    {
        // Owners and admins share the studio's referral link (decision 43); instructors don't see it,
        // and nobody does while referral rewards are off.
        $code = $referrals->bonusDays() > 0 && ! $studio->isDemo() && $request->user()->hasRoleIn($studio, 'admin') ? $referrals->codeFor($studio) : null;

        return view('admin.subscription', [
            'state' => $billing->state($studio),
            'price' => $billing->priceLabel(),
            'yearly' => $billing->yearlyPriceLabel(),
            'yearlySaving' => $billing->yearlySaving(),
            'twelveMonths' => $billing->monthlyForAYearLabel(),
            'plan' => $billing->planLabel($studio),
            'graceDays' => $billing->graceDays(),
            'isOwner' => $request->user()->hasRoleIn($studio, 'owner'),
            'owners' => $billing->owners($studio),
            'configured' => $stripe->configured(),
            'referralCode' => $code,
            'referred' => $code ? $code->referredStudios()->latest()->get(['id', 'name', 'created_at', 'referral_rewarded_at', 'referral_reward', 'referral_reward_cents']) : collect(),
            'trialDays' => $billing->trialDays(),
            'bonusDays' => $referrals->bonusDays(),
            'billing' => $billing,
        ]);
    }

    public function checkout(Request $request, Studio $studio, StudioBilling $billing): RedirectResponse
    {
        try {
            $url = $billing->checkoutUrl(
                $studio,
                $request->user(),
                route('studio.admin.subscription.complete').'?session_id={CHECKOUT_SESSION_ID}',
                route('studio.admin.subscription'),
                (string) $request->input('interval', 'month'),
            );
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->away($url);
    }

    /** Back from Checkout. The webhook records it too; this just makes it show straight away. */
    public function complete(Request $request, Studio $studio, StudioBilling $billing): RedirectResponse
    {
        $id = (string) $request->query('session_id', '');
        try {
            $done = $id !== '' && $billing->complete($studio, $id);
        } catch (StripeException $e) {
            report($e);
            $done = false;
        }
        $state = $billing->state($studio->refresh());
        if (! $done || $state->status !== BillingState::SUBSCRIBED) {
            return redirect()->route('studio.admin.subscription')
                ->with('warning', 'We couldn’t confirm your subscription yet. It should show here within a few minutes.');
        }

        return redirect()->route('studio.admin.subscription')->with('success', $state->inStripeTrial && $state->periodEndsAt
            ? 'Thanks for subscribing! Your card is first charged on '.$studio->local($state->periodEndsAt)->format('F j').', when your trial ends.'
            : 'Thanks for subscribing! Everything’s running.');
    }

    /** Stripe's billing portal; with switch=1, straight to choosing monthly or yearly. */
    public function portal(Request $request, Studio $studio, StudioBilling $billing): RedirectResponse
    {
        try {
            return redirect()->away($billing->portalUrl($studio, route('studio.admin.subscription'), $request->boolean('switch')));
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'Stripe: '.$e->getMessage());
        }
    }
}
