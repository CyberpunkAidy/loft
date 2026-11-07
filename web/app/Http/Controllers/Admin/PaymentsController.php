<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Studio;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Stripe Connect onboarding: the studio connects (or creates) its own Stripe account. */
class PaymentsController extends Controller
{
    public function show(Studio $studio, StripeClient $stripe): View
    {
        $error = null;
        if ($studio->stripe_account_id && ! $studio->stripe_charges_enabled && $stripe->configured()) {
            try {
                $stripe->refreshAccountStatus($studio);
            } catch (StripeException $e) {
                $error = $e->getMessage();
            }
        }

        return view('admin.payments', [
            'configured' => $stripe->configured(),
            'error' => $error,
            'webhookUrl' => route('stripe.webhook'),
        ]);
    }

    public function connect(Request $request, Studio $studio, StripeClient $stripe): RedirectResponse
    {
        if (! $stripe->configured()) {
            return back()->with('error', 'Payments aren’t configured on this server yet (STRIPE_SECRET).');
        }
        try {
            if (! $studio->stripe_account_id) {
                $account = $stripe->createAccount($studio, $studio->contact_email ?: $request->user()->email);
                $studio->update(['stripe_account_id' => $account['id']]);
            }
            $url = $stripe->accountLink(
                $studio->stripe_account_id,
                route('studio.admin.payments'),
                route('studio.admin.payments.return'),
            );
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->away($url);
    }

    public function return(Studio $studio, StripeClient $stripe): RedirectResponse
    {
        try {
            $stripe->refreshAccountStatus($studio);
        } catch (StripeException $e) {
            return redirect()->route('studio.admin.payments')->with('error', 'Stripe: '.$e->getMessage());
        }

        return redirect()->route('studio.admin.payments')->with(
            $studio->stripe_charges_enabled ? 'success' : 'warning',
            $studio->stripe_charges_enabled
                ? 'Stripe is connected. Members can now buy plans.'
                : 'Stripe still needs a few details before you can take payments. Continue setup when you’re ready.',
        );
    }
}
