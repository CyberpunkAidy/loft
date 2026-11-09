<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Studio;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The online shop (roadmap step 6): members buy products through Stripe Checkout and pick them up
 * at the studio.
 *
 * Starting checkout reserves the stock and creates a pending order (no number yet), then a Checkout
 * Session held for HOLD_MINUTES. Paid (webhook or return page): the order is numbered, takes
 * Stripe's amounts and waits for pickup. Not paid (expired, abandoned, a delayed payment that
 * failed): the order is released and the stock goes back. checkout.session.expired releases a
 * checkout the member walked away from as soon as Stripe expires it. The hourly sweep asks Stripe
 * about pending orders whose Checkout should have finished, in case a webhook never came; it needs
 * STRIPE_SECRET on the cron service too.
 *
 * The cart is [variant_id => quantity] in the session.
 */
class Shop
{
    /** Stripe's shortest Checkout expiry. */
    public const HOLD_MINUTES = 30;

    public function __construct(
        private readonly PointOfSale $pos,
        private readonly Orders $orders,
        private readonly StripeClient $stripe,
        private readonly Messages $messages,
    ) {}

    /** The cart's lines, priced and taxed as at the till; only products and variants still for sale. */
    public function lines(Studio $studio, array $cart): Collection
    {
        $lines = collect($cart)->map(fn ($qty, $id) => ['type' => 'variant', 'id' => (int) $id, 'qty' => max(1, (int) $qty)])->values()->all();

        return $this->pos->lines($studio, ['lines' => $lines])
            ->filter(fn ($line) => $line['model']->active && $line['model']->product->active)->values();
    }

    /**
     * Reserve the stock, create the pending order and its Checkout Session; returns the Checkout URL.
     * "__ORDER__" in $cancelUrl becomes the order's ID, so coming back can release it.
     *
     * @throws CheckoutException|StripeException
     */
    public function checkout(Studio $studio, User $member, array $cart, string $successUrl, string $cancelUrl): string
    {
        if (! $studio->stripeReady()) {
            throw new CheckoutException('Online payments aren’t set up for this studio yet. Please ask at the front desk.');
        }
        if ($studio->isPaused()) {
            throw new CheckoutException($studio->name.' isn’t taking online payments right now. Please contact the studio.');
        }
        $lines = $this->lines($studio, $cart);
        if ($lines->isEmpty()) {
            throw new CheckoutException('Your cart is empty.');
        }
        // A checkout they walked away from would hold stock for half an hour: let it go first.
        Order::where('studio_id', $studio->id)->where('user_id', $member->id)->where('status', 'pending')->where('channel', 'online')
            ->get()->each(fn (Order $o) => $this->cancel($o));

        $order = DB::transaction(function () use ($studio, $member, $lines) {
            foreach ($lines as $line) {
                $variant = $line['model'];
                if ($variant->isTracked() && ProductVariant::whereKey($variant->id)->where('stock', '>=', $line['qty'])->decrement('stock', $line['qty']) === 0) {
                    $left = (int) ProductVariant::whereKey($variant->id)->value('stock');
                    throw new CheckoutException($left > 0 ? "Only {$left} of {$line['description']} left." : "{$line['description']} just sold out.");
                }
            }
            $subtotal = (int) $lines->sum('total');
            $tax = (int) $lines->sum('tax');

            return $this->orders->createPending($studio, [
                'user_id' => $member->id,
                'channel' => 'online',
                'method' => 'stripe',
                'currency' => $studio->currency,
                'subtotal_cents' => $subtotal,
                'tax_cents' => $tax,
                'total_cents' => $subtotal + $tax,
                'fulfillment_status' => 'to_pick_up',
            ], $lines->map(fn ($line) => [
                'kind' => 'product',
                'product_variant_id' => $line['model']->id,
                'description' => $line['description'],
                'quantity' => $line['qty'],
                'unit_price_cents' => $line['unit'],
                'total_cents' => $line['total'],
                'tax_cents' => $line['tax'],
            ])->all());
        });

        try {
            $session = $this->stripe->createShopCheckout($studio, $member, $order->load('items.variant.product'), $successUrl,
                str_replace('__ORDER__', (string) $order->id, $cancelUrl), self::HOLD_MINUTES);
        } catch (Throwable $e) {
            $this->release($order, 'failed');
            throw $e;
        }
        $order->update(['stripe_checkout_session_id' => $session['id']]);

        return $session['url'];
    }

    /**
     * A shop Checkout Session completed (webhook, return page or sweep). Paid: the order becomes a sale
     * and the member gets a pickup receipt, once. Returns null if the session isn't a shop order's.
     */
    public function fulfil(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if (! $order || ($cs['payment_status'] ?? '') !== 'paid') {
            return $order; // a delayed payment (bank debit) stays pending until it clears
        }
        $paid = $this->orders->markPaid($order, [
            'subtotal_cents' => (int) ($cs['amount_subtotal'] ?? $order->subtotal_cents),
            'discount_cents' => (int) ($cs['total_details']['amount_discount'] ?? 0),
            'tax_cents' => (int) ($cs['total_details']['amount_tax'] ?? $order->tax_cents),
            'total_cents' => (int) ($cs['amount_total'] ?? $order->total_cents),
            'stripe_checkout_session_id' => $cs['id'] ?? $order->stripe_checkout_session_id,
            'stripe_payment_intent_id' => is_array($cs['payment_intent'] ?? null) ? $cs['payment_intent']['id'] : ($cs['payment_intent'] ?? null),
        ]);
        if ($paid && $order->user) {
            $this->messages->email($order->user, $this->receipt($studio, $order), "receipt:order:{$order->id}");
        }

        return $order->fresh();
    }

    /** A delayed payment (a bank debit) failed: release the order and tell the member, once. */
    public function failed(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if ($order && $this->release($order, 'failed') && $order->user) {
            $this->messages->email($order->user, new StudioMessage($studio, 'Your payment didn’t go through',
                ['Your payment for your shop order didn’t go through, so the order was cancelled and you haven’t been charged.'],
                $order->items()->get()->mapWithKeys(fn ($i) => [$i->description => '×'.$i->quantity])->all(),
                ['You can order again and pay with a card or another payment method.'], route('studio.shop', $studio), 'Back to the shop', 'checkout_failed'),
                "checkout_failed:order:{$order->id}");
        }

        return $order;
    }

    /**
     * Its Checkout Session expired unpaid (checkout.session.expired): the member closed the tab, so
     * release the order and its stock. Returns null if the session isn't a shop order's.
     */
    public function expired(Studio $studio, array $cs): ?Order
    {
        $order = $this->orderFor($studio, $cs);
        if ($order && ($cs['status'] ?? '') === 'expired') {
            $this->release($order, 'expired');
        }

        return $order;
    }

    /** The member came back from Checkout without paying: close the session, then release its stock. */
    public function cancel(Order $order): void
    {
        if ($order->status !== 'pending') {
            return;
        }
        if ($order->stripe_checkout_session_id) {
            try {
                $cs = $this->stripe->expireCheckoutSession($order->studio, $order->stripe_checkout_session_id);
            } catch (StripeException) {
                // Only open sessions can be expired: it already expired (release now) or completed (the webhook settles it).
                try {
                    $cs = $this->stripe->retrieveCheckoutSession($order->studio, $order->stripe_checkout_session_id);
                } catch (StripeException) {
                    return; // the sweep settles it
                }
            }
            if (($cs['status'] ?? '') !== 'expired') {
                return;
            }
        }
        $this->release($order, 'expired');
    }

    /** Pending → expired or failed, and the stock back on the shelf. False if it wasn't pending. */
    public function release(Order $order, string $status): bool
    {
        return $this->orders->release($order, $status);
    }

    /**
     * Hourly: settle pending orders whose Checkout should have finished, by asking Stripe. Paid →
     * fulfilled (a missed webhook); expired → released; a bank debit that failed → released, and the
     * member told; a bank debit still clearing → left.
     */
    public function sweep(): int
    {
        $settled = 0;
        Order::with('studio')->where('status', 'pending')->where('channel', 'online')
            ->where('created_at', '<', now()->subMinutes(self::HOLD_MINUTES + 10))
            ->eachById(function (Order $order) use (&$settled) {
                if (! $order->stripe_checkout_session_id) {
                    $settled += (int) $this->release($order, 'failed');

                    return;
                }
                try {
                    $cs = $this->stripe->retrieveCheckoutSession($order->studio, $order->stripe_checkout_session_id, ['payment_intent']);
                } catch (StripeException $e) {
                    report($e);

                    return;
                }
                $complete = ($cs['status'] ?? '') === 'complete';
                $settled += (int) match (true) {
                    $complete && ($cs['payment_status'] ?? '') === 'paid' => $this->fulfil($order->studio, $cs)?->status === 'paid',
                    $complete && in_array($cs['payment_intent']['status'] ?? null, ['requires_payment_method', 'canceled'], true) => $this->failed($order->studio, $cs)?->status === 'failed',
                    ($cs['status'] ?? '') === 'expired',
                    ($cs['status'] ?? '') === 'open' && ($cs['expires_at'] ?? PHP_INT_MAX) < now()->getTimestamp() => $this->release($order, 'expired'),
                    default => false,
                };
            });

        return $settled;
    }

    /** The member's email once paid: what they bought, and to collect it at the front desk. */
    public function receipt(Studio $studio, Order $order): StudioMessage
    {
        $order->loadMissing('items');
        $details = $order->items->mapWithKeys(fn ($i) => [$i->description.($i->quantity > 1 ? ' ×'.$i->quantity : '') => Money::format($i->total_cents, $order->currency)])->all();
        if ($order->tax_cents > 0) {
            $details['Tax'] = Money::format($order->tax_cents, $order->currency);
        }
        $details['Total'] = $order->totalLabel();
        $details['Order'] = '#'.$order->number;

        return new StudioMessage($studio, 'Your order is ready to pick up', ['Thanks for your order! Pick it up at the front desk next time you’re in.'], $details,
            ['Just give your name at the desk.'], route('studio.account.billing', $studio), 'See your purchases', 'shop_order');
    }

    /** The shop order a Checkout Session is for, if it's this studio's. */
    private function orderFor(Studio $studio, array $cs): ?Order
    {
        $meta = $cs['metadata'] ?? [];
        if (($meta['loft_kind'] ?? null) !== 'shop') {
            return null;
        }

        return Order::where('studio_id', $studio->id)->find((int) ($meta['loft_order_id'] ?? 0));
    }
}
