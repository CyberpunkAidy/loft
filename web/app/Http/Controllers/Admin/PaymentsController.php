<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Studio;
use App\Services\OfflinePayments;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Stripe Connect onboarding, and the payments staff recorded by hand. */
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

        // Everything taken in person: plans added from a member's page and point-of-sale sales.
        $inPerson = $studio->orders()->where('channel', 'in_person');
        $thisMonth = (clone $inPerson)->where('status', 'paid')->where('method', '!=', 'comp')->where('currency', $studio->currency)
            ->where('paid_at', '>=', $studio->localNow()->startOfMonth()->utc());

        return view('admin.payments', [
            'configured' => $stripe->configured(),
            'error' => $error,
            'webhookUrl' => route('stripe.webhook'),
            'orders' => (clone $inPerson)->with(['items', 'user', 'payment', 'studio'])->latest('paid_at')->latest('id')->paginate(25),
            'monthTotal' => (int) (clone $thisMonth)->sum('total_cents'),
            'monthCount' => (clone $thisMonth)->count(),
        ]);
    }

    /** Undo a payment recorded by mistake; the plan it granted is cancelled. */
    public function void(Request $request, Studio $studio, Payment $payment, OfflinePayments $payments): RedirectResponse
    {
        if ($payment->isVoided()) {
            return back()->with('error', 'That payment is already void.');
        }
        $payments->void($payment, $request->user());

        return back()->with('success', 'Payment voided, and the plan it added was cancelled.');
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
