<?php

namespace App\Services\Stripe;

use App\Events\CheckoutPaymentFailed;
use App\Events\EntitlementGranted;
use App\Events\PaymentFailed;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\StripeEvent;
use App\Models\Studio;
use App\Models\User;
use App\Services\Bookings;
use App\Services\DomainEvents;
use App\Services\Entitlements;
use App\Services\Orders;
use App\Services\Shop;
use App\Support\BookingException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Stripe Connect webhook endpoint logic (POST /stripe/webhook). Events from every connected
 * account arrive here; `account` says which studio. Port of the plugin's Stripe\Webhooks.
 */
class StripeWebhooks
{
    private const SUBSCRIPTION_STATUS = [
        'active' => 'active',
        'trialing' => 'active',
        'past_due' => 'past_due',
        'unpaid' => 'past_due',
        'canceled' => 'cancelled',
        'incomplete_expired' => 'cancelled',
        'paused' => 'cancelled',
    ];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly Entitlements $entitlements,
        private readonly Bookings $bookings,
        private readonly DomainEvents $events,
        private readonly Orders $orders,
        private readonly Shop $shop,
    ) {}

    /**
     * Verify, log and process one delivery.
     *
     * @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body
     */
    public function receive(string $payload, string $signature): array
    {
        $secret = (string) config('loft.stripe.webhook_secret');
        if ($secret === '' || ! StripeClient::verifySignature($payload, $signature, $secret)) {
            return [400, ['error' => 'invalid signature']];
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return [400, ['error' => 'bad payload']];
        }

        $account = $event['account'] ?? null;
        $studio = $account ? Studio::where('stripe_account_id', $account)->first() : null;
        $record = StripeEvent::createOrFirst(['event_id' => $event['id']], [
            'type' => $event['type'],
            'account' => $account,
            'studio_id' => $studio?->id,
            'received_at' => now(),
            'payload' => $payload,
        ]);
        if ($record->processed_at) {
            return [200, ['received' => true, 'duplicate' => true]];
        }

        try {
            $this->process($event, $studio);
            $record->update(['processed_at' => now(), 'error' => null]);
        } catch (Throwable $e) {
            // Unlike the plugin, answer 500 so Stripe retries; the unprocessed row lets the retry through.
            $record->update(['error' => $e->getMessage()]);
            report($e);

            return [500, ['received' => true, 'error' => 'processing failed']];
        }

        return [200, ['received' => true]];
    }

    /**
     * Webhook payloads come in the endpoint's API version, which Stripe picks for new endpoints and
     * which reshapes subscriptions and invoices between versions. So events are used only for the
     * object's id; each handler gets the object fetched from the API in the client's pinned version
     * (config loft.stripe.api_version). That works with any endpoint version and always acts on the
     * latest state, even when events arrive out of order.
     *
     * @param  array<string, mixed>  $event
     */
    public function process(array $event, ?Studio $studio): void
    {
        $id = (string) ($event['data']['object']['id'] ?? '');

        if ($event['type'] === 'account.updated' && $studio) {
            $this->stripe->refreshAccountStatus($studio);

            return;
        }
        if ($event['type'] === 'account.application.deauthorized' && $studio) {
            $studio->update([
                'stripe_account_id' => null,
                'stripe_charges_enabled' => false,
                'stripe_details_submitted' => false,
                'stripe_portal_configuration_id' => null,
            ]);

            return;
        }
        if (! $studio || $id === '') {
            return; // not from a studio we know
        }

        match ($event['type']) {
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->fulfillCheckout($studio, $this->stripe->retrieveCheckoutSession($studio, $id)),
            'checkout.session.async_payment_failed' => $this->checkoutFailed($studio, $this->stripe->retrieveCheckoutSession($studio, $id)),
            'checkout.session.expired' => $this->shop->expired($studio, $this->stripe->retrieveCheckoutSession($studio, $id)), // plan checkouts hold nothing
            'charge.refunded' => $this->chargeRefunded($studio, $this->stripe->retrieveCharge($studio, $id)),
            'invoice.paid' => $this->invoicePaid($studio, $this->stripe->retrieveInvoice($studio, $id)),
            'invoice.payment_failed' => $this->invoiceFailed($studio, $this->stripe->retrieveInvoice($studio, $id)),
            'customer.subscription.updated' => $this->subscriptionUpdated($studio, $this->stripe->retrieveSubscription($studio, $id)),
            'customer.subscription.deleted' => $this->subscriptionDeleted($studio, ['id' => $id]),
            default => null,
        };
    }

    /**
     * Grant what a completed Checkout Session paid for, then finish the booking it started from.
     * Safe to call more than once (webhook and the success redirect both call it).
     *
     * A one-time payment by a delayed method (a bank debit, say) completes checkout unpaid; it's
     * granted when checkout.session.async_payment_succeeded arrives, days later.
     *
     * @param  array<string, mixed>  $cs
     */
    public function fulfillCheckout(Studio $studio, array $cs): CheckoutResult
    {
        if (($cs['metadata']['loft_kind'] ?? null) === 'shop') {
            $this->shop->fulfil($studio, $cs); // an online shop order, not a plan

            return new CheckoutResult(null);
        }
        if (($cs['status'] ?? 'complete') !== 'complete') {
            return new CheckoutResult(null);
        }
        if (($cs['payment_status'] ?? '') !== 'paid' && ($cs['mode'] ?? '') !== 'subscription') {
            return new CheckoutResult(null); // still processing: async_payment_succeeded (or _failed) follows
        }
        $meta = $cs['metadata'] ?? [];
        [$plan, $user] = $this->planAndUser($studio, $meta);
        if (! $plan || ! $user) {
            throw new RuntimeException('Checkout Session '.($cs['id'] ?? '?').' is missing plan or member metadata.');
        }

        $subscriptionId = $cs['subscription'] ?? null;
        $entitlement = $this->findEntitlement($studio, $cs['id'], $subscriptionId);
        $created = false;
        if (! $entitlement) {
            $stripe = [
                'customer_id' => $cs['customer'] ?? null,
                'checkout_session_id' => $cs['id'],
                'subscription_id' => $subscriptionId,
            ];
            if ($subscriptionId) {
                $stripe['current_period_end'] = $this->periodEnd($this->stripe->retrieveSubscription($studio, $subscriptionId));
            }
            [$entitlement, $created] = $this->grant($studio, $plan, $user, $stripe);
            if ($created) {
                $this->endContinued($studio, $meta, $entitlement);
            }
        } elseif (! $entitlement->stripe_checkout_session_id) {
            $entitlement->update(['stripe_checkout_session_id' => $cs['id']]); // invoice.paid got here first
        }

        if ($entitlement) {
            $this->orders->recordCheckout($studio, $cs, $entitlement); // one-time payments; memberships record their invoices
        }
        [$booking, $error] = $this->completeBooking($studio, $entitlement, $user, $meta);

        return new CheckoutResult($entitlement, $created, $booking, $error);
    }

    /**
     * A delayed one-time payment failed, so nothing was granted: tell the member. (Subscriptions
     * report the failure through invoice.payment_failed instead.)
     *
     * @param  array<string, mixed>  $cs
     */
    private function checkoutFailed(Studio $studio, array $cs): void
    {
        if (($cs['mode'] ?? '') === 'subscription') {
            return;
        }
        if (($cs['metadata']['loft_kind'] ?? null) === 'shop') {
            $this->shop->failed($studio, $cs);

            return;
        }
        $meta = $cs['metadata'] ?? [];
        [$plan, $user] = $this->planAndUser($studio, $meta);
        if ($user) {
            $this->events->record('checkout.failed', $studio, ['plan_id' => $plan?->id, 'plan_name' => $plan?->name, 'checkout_session_id' => $cs['id'] ?? null], $user->id);
            CheckoutPaymentFailed::dispatch($studio, $user, $plan, (int) ($meta['session_id'] ?? 0) > 0);
        }
    }

    /** @param array<string, mixed> $invoice */
    private function invoicePaid(Studio $studio, array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? null;
        if (! $subscriptionId) {
            return;
        }
        $entitlement = $studio->entitlements()->where('stripe_subscription_id', $subscriptionId)->first();
        if (! $entitlement) {
            // Paid before checkout.session.completed arrived, or created in the Stripe dashboard with our metadata.
            $subscription = $this->stripe->retrieveSubscription($studio, $subscriptionId);
            [$plan, $user] = $this->planAndUser($studio, $subscription['metadata'] ?? []);
            if ($plan && $user) {
                [$entitlement, $created] = $this->grant($studio, $plan, $user, [
                    'customer_id' => $subscription['customer'] ?? null,
                    'subscription_id' => $subscriptionId,
                    'current_period_end' => $this->periodEnd($subscription),
                ]);
                if ($entitlement && $created) {
                    $this->endContinued($studio, $subscription['metadata'] ?? [], $entitlement);
                }
                if ($entitlement) {
                    $this->orders->recordInvoice($studio, $invoice, $entitlement);
                }
            }

            return;
        }
        $this->orders->recordInvoice($studio, $invoice, $entitlement);

        $periodEnd = null;
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            if (! empty($line['period']['end'])) {
                $periodEnd = max((int) $periodEnd, (int) $line['period']['end']);
            }
        }
        $update = ['current_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : $entitlement->current_period_end];
        if (in_array($entitlement->status, ['active', 'past_due'], true)) {
            $update['status'] = 'active';
        }
        $entitlement->update($update);
    }

    /** @param array<string, mixed> $invoice */
    private function invoiceFailed(Studio $studio, array $invoice): void
    {
        $entitlement = ($invoice['subscription'] ?? null)
            ? $studio->entitlements()->where('stripe_subscription_id', $invoice['subscription'])->first()
            : null;
        if ($entitlement && $entitlement->status === 'active') {
            DB::transaction(function () use ($entitlement, $invoice) {
                $entitlement->update(['status' => 'past_due']);
                $this->events->forEntitlement('payment.failed', $entitlement, ['invoice_id' => $invoice['id'] ?? null]);
            });
            PaymentFailed::dispatch($entitlement); // once per lapse, not once per Stripe retry
        }
    }

    /** @param array<string, mixed> $subscription */
    private function subscriptionUpdated(Studio $studio, array $subscription): void
    {
        $entitlement = $studio->entitlements()->where('stripe_subscription_id', $subscription['id'] ?? '')->first();
        if (! $entitlement) {
            return;
        }
        $periodEnd = $this->periodEnd($subscription);
        $expiresAt = match (true) {
            ! empty($subscription['cancel_at']) => Carbon::createFromTimestamp((int) $subscription['cancel_at']),
            ! empty($subscription['cancel_at_period_end']) => $periodEnd,
            default => null,
        };
        $wasEnded = $entitlement->hasEnded();
        $wasEnding = $entitlement->expires_at !== null;
        DB::transaction(function () use ($entitlement, $subscription, $periodEnd, $expiresAt, $wasEnded, $wasEnding) {
            $entitlement->update([
                'status' => self::SUBSCRIPTION_STATUS[$subscription['status'] ?? ''] ?? $entitlement->status,
                'current_period_end' => $periodEnd ?? $entitlement->current_period_end,
                'expires_at' => $expiresAt,
            ]);
            // History for churn: a member choosing to leave (or changing their mind) matters as much as the end.
            match (true) {
                ! $wasEnded && $entitlement->hasEnded() => $this->events->forEntitlement('entitlement.ended', $entitlement, ['reason' => 'cancelled']),
                $entitlement->plan?->kind === 'season' => null, // a season plan's end date is the season's, not a choice
                ! $wasEnding && $expiresAt !== null => $this->events->forEntitlement('entitlement.cancel_scheduled', $entitlement, ['ends_at' => $expiresAt->toIso8601String()]),
                $wasEnding && $expiresAt === null && ! $entitlement->hasEnded() => $this->events->forEntitlement('entitlement.cancel_unscheduled', $entitlement),
                default => null,
            };
        });
    }

    /** @param array<string, mixed> $subscription */
    /**
     * Money given back for a payment, from Loft or the Stripe Dashboard: recorded on its order, which
     * a full refund takes back (Orders::refunded). Payments Loft has no order for are ignored.
     */
    private function chargeRefunded(Studio $studio, array $charge): void
    {
        $intent = is_array($charge['payment_intent'] ?? null) ? ($charge['payment_intent']['id'] ?? null) : ($charge['payment_intent'] ?? null);
        $invoice = is_array($charge['invoice'] ?? null) ? ($charge['invoice']['id'] ?? null) : ($charge['invoice'] ?? null);
        $order = match (true) {
            filled($intent) && ($found = $studio->orders()->where('stripe_payment_intent_id', $intent)->first()) !== null => $found,
            filled($invoice) => $studio->orders()->where('stripe_invoice_id', $invoice)->first(),
            default => null,
        };
        if ($order) {
            $this->orders->refunded($order, (int) ($charge['amount_refunded'] ?? 0), null, ['charge_id' => $charge['id'] ?? null]);
        }
    }

    private function subscriptionDeleted(Studio $studio, array $subscription): void
    {
        $entitlement = $studio->entitlements()->where('stripe_subscription_id', $subscription['id'] ?? '')->first();
        if (! $entitlement) {
            return;
        }
        $wasEnded = $entitlement->hasEnded();
        DB::transaction(function () use ($entitlement, $wasEnded) {
            $entitlement->update(['status' => 'cancelled', 'expires_at' => $wasEnded ? $entitlement->expires_at : now()]);
            if (! $wasEnded) {
                $this->events->forEntitlement('entitlement.ended', $entitlement, ['reason' => 'cancelled']);
            }
        });
    }

    /* ------------------------------------------------------------------ Helpers */

    /** @return array{0: ?Plan, 1: ?User} — only this studio's plans and members */
    private function planAndUser(Studio $studio, array $meta): array
    {
        $plan = $studio->plans()->find((int) ($meta['loft_plan_id'] ?? 0));
        $user = $studio->members()->find((int) ($meta['loft_user_id'] ?? 0));

        return [$plan, $user];
    }

    private function findEntitlement(Studio $studio, ?string $checkoutSessionId, ?string $subscriptionId): ?Entitlement
    {
        return ($checkoutSessionId ? $studio->entitlements()->where('stripe_checkout_session_id', $checkoutSessionId)->first() : null)
            ?? ($subscriptionId ? $studio->entitlements()->where('stripe_subscription_id', $subscriptionId)->first() : null);
    }

    /** @return array{0: Entitlement, 1: bool} the entitlement, and whether this call created it */
    private function grant(Studio $studio, Plan $plan, User $user, array $stripe): array
    {
        try {
            $entitlement = $this->entitlements->grantFromPlan($plan, $user->id, $stripe);
        } catch (UniqueConstraintViolationException) {
            // The webhook and the success redirect raced; the other one won.
            return [$this->findEntitlement($studio, $stripe['checkout_session_id'] ?? null, $stripe['subscription_id'] ?? null), false];
        }
        if ($plan->kind === 'season' && ! empty($stripe['subscription_id'])) {
            $this->stripe->setSeasonEnd($studio, $plan, $stripe['subscription_id']);
        }
        EntitlementGranted::dispatch($entitlement);

        return [$entitlement, true];
    }

    /**
     * A membership imported from another system, now carried on by a Loft subscription (decision
     * 37): the subscription covers the rest of its time (Stripe trials until it would have ended),
     * so it ends here rather than showing twice.
     *
     * @param  array<string, mixed>  $meta
     */
    private function endContinued(Studio $studio, array $meta, Entitlement $new): void
    {
        $id = (int) ($meta['continues_entitlement_id'] ?? 0);
        $old = $id ? $studio->entitlements()->whereKey($id)->where('user_id', $new->user_id)->whereNotNull('import_id')
            ->whereNull('stripe_subscription_id')->where('status', 'active')->with('plan')->first() : null;
        if (! $old) {
            return;
        }
        DB::transaction(function () use ($old) {
            $old->update(['status' => 'expired']);
            $this->events->forEntitlement('entitlement.ended', $old, ['reason' => 'continued']);
        });
    }

    /** @return array{0: ?Booking, 1: ?string} */
    private function completeBooking(Studio $studio, Entitlement $entitlement, User $user, array $meta): array
    {
        $session = $studio->sessions()->find((int) ($meta['session_id'] ?? 0));
        $attendee = $studio->attendees()->find((int) ($meta['attendee_id'] ?? 0));
        if (! $session || ! $attendee) {
            return [null, null];
        }
        if ($existing = $this->bookings->existing($session, $attendee)) {
            return [$existing, null];
        }
        try {
            $apparatusId = (int) ($meta['apparatus_id'] ?? 0);
            $apparatusId = $session->apparatusSlots()->has($apparatusId) ? $apparatusId : null; // else any

            return [$this->bookings->book($session, $attendee, $user->id, 'web', $entitlement, apparatusId: $apparatusId, online: ! empty($meta['online']), skipQuestions: true), null];
        } catch (BookingException $e) {
            Log::info('Booking after checkout failed', ['session' => $session->id, 'attendee' => $attendee->id, 'reason' => $e->reason]);

            return [null, $e->getMessage()];
        }
    }

    private function periodEnd(array $subscription): ?Carbon
    {
        return ! empty($subscription['current_period_end']) ? Carbon::createFromTimestamp((int) $subscription['current_period_end']) : null;
    }
}
