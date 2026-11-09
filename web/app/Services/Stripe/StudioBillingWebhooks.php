<?php

namespace App\Services\Stripe;

use App\Models\StripeEvent;
use App\Models\Studio;
use App\Services\StudioBilling;
use Throwable;

/**
 * The billing webhook (POST /stripe/billing-webhook): events on Loft's own Stripe account about
 * studios' Loft subscriptions (decision 36). It's a separate endpoint from the Connect one because
 * Stripe sends platform events and connected-account events to different endpoints, each with its
 * own signing secret (STRIPE_BILLING_WEBHOOK_SECRET).
 *
 * As with the Connect webhook, the event is only used for the object's id: the subscription is
 * fetched in the client's pinned API version and mirrored onto the studio as it is now.
 */
class StudioBillingWebhooks
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StudioBilling $billing,
    ) {}

    /** @return array{0: int, 1: array<string, mixed>} HTTP status and JSON body */
    public function receive(string $payload, string $signature): array
    {
        $secret = (string) config('loft.billing.webhook_secret');
        if ($secret === '' || ! StripeClient::verifySignature($payload, $signature, $secret)) {
            return [400, ['error' => 'invalid signature']];
        }
        $event = json_decode($payload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            return [400, ['error' => 'bad payload']];
        }

        $record = StripeEvent::createOrFirst(['event_id' => $event['id']], [
            'type' => $event['type'],
            'received_at' => now(),
            'payload' => $payload,
        ]);
        if ($record->processed_at) {
            return [200, ['received' => true, 'duplicate' => true]];
        }
        try {
            $studio = $this->process($event);
            $record->update(['processed_at' => now(), 'error' => null, 'studio_id' => $studio?->id]);
        } catch (Throwable $e) {
            $record->update(['error' => $e->getMessage()]);
            report($e);

            return [500, ['received' => true, 'error' => 'processing failed']];
        }

        return [200, ['received' => true]];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return Studio|null the studio it was about, if it was about one
     */
    public function process(array $event): ?Studio
    {
        $object = $event['data']['object'] ?? [];
        $id = (string) ($object['id'] ?? '');
        if ($id === '') {
            return null;
        }

        $subscription = match ($event['type']) {
            'checkout.session.completed' => ($object['mode'] ?? null) === 'subscription' ? ($this->stripe->retrieveBillingCheckout($id)['subscription'] ?? null) : null,
            'customer.subscription.created',
            'customer.subscription.updated',
            'customer.subscription.deleted',
            'customer.subscription.paused',
            'customer.subscription.resumed' => $this->stripe->retrieveBillingSubscription($id),
            'invoice.paid',
            'invoice.payment_failed' => $this->invoiceSubscription($this->stripe->retrieveBillingInvoice($id)),
            default => null,
        };
        if (! is_array($subscription) || ! ($studio = $this->billing->studioFor($subscription))) {
            return null; // not a studio subscription we know
        }
        $this->billing->sync($studio, $subscription);

        return $studio;
    }

    /** The subscription an invoice bills, fetched; null for invoices that aren't for one. */
    private function invoiceSubscription(array $invoice): ?array
    {
        $id = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $id = is_array($id) ? ($id['id'] ?? null) : $id;

        return is_string($id) && $id !== '' ? $this->stripe->retrieveBillingSubscription($id) : null;
    }
}
