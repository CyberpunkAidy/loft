<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Studio;
use App\Services\PlanCheckout;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\CheckoutException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stripe Checkout and the customer portal, on the studio's connected Stripe account. */
class CheckoutController extends Controller
{
    public function start(Request $request, Studio $studio, Plan $plan, PlanCheckout $checkout): RedirectResponse
    {
        abort_unless($plan->active, 404);
        // A booking to finish once payment completes.
        $context = $checkout->bookingContext($studio, $request->user(), $request->integer('session_id') ?: null,
            $request->integer('attendee_id') ?: null, $request->integer('apparatus_id') ?: null, $request->boolean('online'));
        try {
            $session = $checkout->start($studio, $request->user(), $plan, $context,
                route('studio.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}',
                $context ? route('studio.book', array_filter(['session' => $context['session_id'], 'online' => $context['online'] ?? null])) : route('studio.plans'));
        } catch (CheckoutException $e) {
            return back()->with('error', $e->getMessage());
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'We couldn’t start checkout: '.$e->getMessage());
        }

        return redirect()->away($session['url']);
    }

    /** Stripe sends the member back here; fulfil now rather than wait for the webhook. */
    public function complete(Request $request, Studio $studio, PlanCheckout $checkout): RedirectResponse
    {
        $id = (string) $request->query('session_id', '');
        if (! str_starts_with($id, 'cs_')) {
            return redirect()->route('studio.account.billing');
        }
        try {
            $confirmed = $checkout->confirm($studio, $request->user(), $id);
        } catch (StripeException $e) {
            report($e);

            return redirect()->route('studio.account.billing')->with('success', 'Payment received — thank you! Your plan will appear here in a moment.');
        }
        abort_unless($confirmed !== null, 404);
        ['cs' => $cs, 'result' => $result] = $confirmed;
        $meta = $cs['metadata'] ?? [];

        if (! $result->entitlement && ($cs['status'] ?? '') === 'complete' && ($cs['payment_status'] ?? '') === 'unpaid') {
            // A delayed method, like a bank debit: fulfilled by the async_payment_succeeded webhook.
            $booking = (int) ($meta['session_id'] ?? 0) > 0 ? ' and finish your booking' : '';

            return redirect()->route('studio.account.billing')->with('success', "Thanks! Your payment is processing. Bank payments can take a few business days; we’ll email you when it goes through{$booking}.");
        }
        if (! $result->entitlement) {
            return redirect()->route('studio.account.billing')->with('success', 'Thanks! We’re confirming your payment; your plan will appear here shortly.');
        }
        if ($result->booking) {
            $name = $result->booking->attendee->first_name;
            $what = $result->booking->status === 'waitlisted' ? "is #{$result->booking->position} on the waitlist" : 'is booked';

            return redirect()->route('studio.account')->with('success', "Payment received — {$name} {$what} for {$result->booking->classSession->program->title}.");
        }
        if ($result->bookingError) {
            return redirect()->route('studio.account.billing')->with('warning', 'Payment received, but we couldn’t complete the booking: '.$result->bookingError);
        }

        return redirect()->route('studio.account.billing')->with('success', 'Payment received — thank you!');
    }

    public function portal(Request $request, Studio $studio, StripeClient $stripe): RedirectResponse
    {
        try {
            $portal = $stripe->createPortal($studio, $request->user(), route('studio.account.billing'));
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'We couldn’t open billing: '.$e->getMessage());
        }

        return redirect()->away($portal['url']);
    }
}
