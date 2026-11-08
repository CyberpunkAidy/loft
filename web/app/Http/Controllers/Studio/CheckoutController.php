<?php

namespace App\Http\Controllers\Studio;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Models\Studio;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Services\Stripe\StripeWebhooks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Stripe Checkout and the customer portal, on the studio's connected Stripe account. */
class CheckoutController extends Controller
{
    public function start(Request $request, Studio $studio, Plan $plan, StripeClient $stripe): RedirectResponse
    {
        abort_unless($plan->active, 404);
        if (! $studio->stripeReady()) {
            return back()->with('error', 'Online payments aren’t set up for this studio yet. Please contact the studio.');
        }
        if ($plan->kind === 'season' && $plan->season && $plan->season->ends_on->endOfDay()->isPast()) {
            return back()->with('error', 'That season has ended.');
        }

        // A booking to finish once payment completes (both must be this member's and this studio's).
        $session = $request->integer('session_id') ? $studio->sessions()->find($request->integer('session_id')) : null;
        $attendee = $request->integer('attendee_id')
            ? $studio->attendees()->where('user_id', $request->user()->id)->find($request->integer('attendee_id'))
            : null;
        $context = $session && $attendee ? ['session_id' => $session->id, 'attendee_id' => $attendee->id] : [];
        if ($context && $session->apparatusSlots()->has($request->integer('apparatus_id'))) {
            $context['apparatus_id'] = $request->integer('apparatus_id');
        }

        try {
            $checkout = $stripe->createCheckout(
                $studio,
                $request->user(),
                $plan,
                route('studio.checkout.complete').'?session_id={CHECKOUT_SESSION_ID}',
                $context ? route('studio.book', ['session' => $session]) : route('studio.plans'),
                $context,
            );
        } catch (StripeException $e) {
            report($e);

            return back()->with('error', 'We couldn’t start checkout: '.$e->getMessage());
        }

        return redirect()->away($checkout['url']);
    }

    /** Stripe sends the member back here; fulfil now rather than wait for the webhook. */
    public function complete(Request $request, Studio $studio, StripeClient $stripe, StripeWebhooks $webhooks): RedirectResponse
    {
        $id = (string) $request->query('session_id', '');
        if (! str_starts_with($id, 'cs_')) {
            return redirect()->route('studio.account.billing');
        }
        try {
            $cs = $stripe->retrieveCheckoutSession($studio, $id);
            $meta = $cs['metadata'] ?? [];
            abort_unless((int) ($meta['loft_user_id'] ?? 0) === (int) $request->user()->id && (int) ($meta['loft_studio_id'] ?? 0) === (int) $studio->id, 404);
            $result = $webhooks->fulfillCheckout($studio, $cs);
        } catch (StripeException $e) {
            report($e);

            return redirect()->route('studio.account.billing')->with('success', 'Payment received — thank you! Your plan will appear here in a moment.');
        }

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
