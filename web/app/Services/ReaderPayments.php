<?php

namespace App\Services;

use App\Models\CardReader;
use App\Models\Order;
use App\Models\Studio;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Charging the point of sale's cart on a Stripe Terminal card reader (server-driven: Loft tells the
 * reader what to collect, on the studio's own Stripe account).
 *
 * Sending the cart takes the stock and creates a pending order (no number yet; plans not granted)
 * with a card-present PaymentIntent, shown on the reader. The front desk's waiting page asks Stripe
 * where it stands every couple of seconds: paid → the order is numbered, plans granted, receipt sent;
 * declined → try again or cancel; cancelled → the stock goes back. The hourly sweep settles sales
 * nobody finished. No webhook events are needed. Reader sales are refunded like online ones (Refunds).
 */
class ReaderPayments
{
    /** A sale left waiting this long is settled by the sweep (paid, or cancelled). */
    public const WAIT_MINUTES = 15;

    /** Stripe's smallest card payment, in the smallest currency unit (50¢ in USD). */
    public const MINIMUM_CENTS = 50;

    /** A test card the simulated reader declines. */
    public const DECLINED_TEST_CARD = '4000000000000002';

    public function __construct(
        private readonly PointOfSale $pos,
        private readonly Orders $orders,
        private readonly OfflinePayments $payments,
        private readonly StripeClient $stripe,
        private readonly Messages $messages,
    ) {}

    /**
     * Send the cart to a reader. Returns the pending order; nothing is left behind if the reader
     * can't take it (offline, busy).
     *
     * @throws CheckoutException
     */
    public function charge(Studio $studio, array $cart, CardReader $reader, ?string $note, User $staff): Order
    {
        if (! $studio->stripeReady()) {
            throw new CheckoutException('Connect Stripe before using a card reader.');
        }
        [$lines, $member] = $this->pos->prepare($studio, $cart);
        $totals = $this->pos->totals($lines);
        if ($totals['total'] < self::MINIMUM_CENTS) {
            throw new CheckoutException('Card payments must be at least '.Money::format(self::MINIMUM_CENTS, $studio->currency).'.');
        }
        if (Order::where('card_reader_id', $reader->id)->where('status', 'pending')->exists()) {
            throw new CheckoutException("{$reader->label} is already waiting for a card. Finish or cancel that sale first.");
        }

        $order = DB::transaction(fn () => $this->orders->createPending($studio, [
            'user_id' => $member?->id,
            'walk_in' => ! $member,
            'channel' => 'in_person',
            'method' => 'reader',
            'currency' => $studio->currency,
            'subtotal_cents' => $totals['subtotal'],
            'tax_cents' => $totals['tax'],
            'total_cents' => $totals['total'],
            'recorded_by_user_id' => $staff->id,
            'note' => $note,
            'card_reader_id' => $reader->id,
        ], $this->pos->items($lines)));

        $intent = null;
        try {
            $intent = $this->stripe->createReaderPaymentIntent($studio, $order);
            $order->update(['stripe_payment_intent_id' => $intent['id']]);
            $this->stripe->processOnReader($studio, $reader->stripe_reader_id, $intent['id']);
        } catch (StripeException $e) {
            if ($intent) {
                try {
                    $this->stripe->cancelPaymentIntent($studio, $intent['id']);
                } catch (StripeException) {
                    // never shown on a reader, so it can't be paid; Stripe cancels it eventually
                }
            }
            $this->orders->release($order, 'failed');
            throw new CheckoutException($this->readerError($e, $reader));
        }

        return $order;
    }

    /**
     * Where a reader sale stands, from Stripe. Paid sales are recorded here (once).
     *
     * @return array{state: 'paid'|'waiting'|'failed'|'closed', message: ?string}
     *
     * @throws StripeException
     */
    public function settle(Order $order): array
    {
        if ($order->status === 'paid') {
            return ['state' => 'paid', 'message' => null];
        }
        if (! $order->awaitsReader()) {
            return ['state' => 'closed', 'message' => null];
        }
        $studio = $order->studio;
        $intent = $this->stripe->retrievePaymentIntent($studio, $order->stripe_payment_intent_id);
        if (($intent['status'] ?? '') === 'requires_capture') {
            $intent = $this->stripe->capturePaymentIntent($studio, $intent['id']);
        }

        return match ($intent['status'] ?? '') {
            'succeeded' => $this->complete($order, $intent),
            'canceled' => $this->closed($order),
            'processing', 'requires_confirmation' => ['state' => 'waiting', 'message' => null],
            default => $this->onReader($order, $intent),
        };
    }

    /**
     * Staff cancelled: clear the reader, cancel the payment, put the stock back. If the card went
     * through meanwhile, the sale is recorded instead.
     *
     * @return array{state: 'paid'|'waiting'|'failed'|'closed', message: ?string}
     *
     * @throws StripeException
     */
    public function cancel(Order $order): array
    {
        if (! $order->awaitsReader()) {
            return $this->settle($order);
        }
        $studio = $order->studio;
        if ($order->cardReader && $this->showing($order)) {
            try {
                $this->stripe->cancelReaderAction($studio, $order->cardReader->stripe_reader_id);
            } catch (StripeException) {
                // offline or already idle: cancelling the payment below is what counts
            }
        }
        try {
            $this->stripe->cancelPaymentIntent($studio, $order->stripe_payment_intent_id);
        } catch (StripeException) {
            return $this->settle($order); // paid in the meantime (or Stripe can't say yet)
        }

        return $this->closed($order);
    }

    /**
     * Show the sale on its reader again: after a decline, or if the reader was cleared.
     *
     * @throws CheckoutException
     */
    public function retry(Order $order): void
    {
        if (! $order->awaitsReader()) {
            return;
        }
        if (! $order->cardReader) {
            throw new CheckoutException('That card reader was removed. Cancel this sale and charge it on another reader.');
        }
        try {
            $this->stripe->processOnReader($order->studio, $order->cardReader->stripe_reader_id, $order->stripe_payment_intent_id);
        } catch (StripeException $e) {
            throw new CheckoutException($this->readerError($e, $order->cardReader));
        }
    }

    /**
     * Test mode: tap a test card on a simulated reader (one that's declined, if asked).
     *
     * @throws StripeException
     */
    public function simulateTap(Order $order, bool $decline = false): void
    {
        if (! $this->stripe->testMode() || ! $order->awaitsReader() || ! $order->cardReader) {
            return;
        }
        $this->stripe->presentTestCard($order->studio, $order->cardReader->stripe_reader_id, $decline ? self::DECLINED_TEST_CARD : null);
    }

    /** Hourly: settle reader sales nobody finished: record the ones that were paid, cancel the rest. */
    public function sweep(): int
    {
        $settled = 0;
        Order::with(['studio', 'cardReader'])->where('status', 'pending')->where('method', 'reader')
            ->where('created_at', '<', now()->subMinutes(self::WAIT_MINUTES))
            ->eachById(function (Order $order) use (&$settled) {
                if (! $order->stripe_payment_intent_id) {
                    $settled += (int) $this->orders->release($order, 'failed');

                    return;
                }
                try {
                    $state = $this->settle($order)['state'];
                    if (in_array($state, ['waiting', 'failed'], true)) {
                        $state = $this->cancel($order)['state'];
                    }
                    $settled += (int) in_array($state, ['paid', 'closed'], true);
                } catch (StripeException $e) {
                    report($e);
                }
            });

        return $settled;
    }

    /** Paid: number the order, grant its plans, email the receipt. Once, however many times it's asked. */
    private function complete(Order $order, array $intent): array
    {
        $paid = DB::transaction(function () use ($order, $intent) {
            if (! $this->orders->markPaid($order, ['stripe_payment_intent_id' => $intent['id']])) {
                return false;
            }
            $member = $order->user;
            $today = $order->studio->localNow()->toDateString();
            foreach ($order->items()->where('kind', 'plan')->whereNull('entitlement_id')->with('plan')->get() as $item) {
                if ($member && $item->plan) {
                    $item->update(['entitlement_id' => $this->payments->grant($item->plan, $member, 'reader', $today, null, $item->unit_price_cents)->id]);
                }
            }

            return true;
        });
        if ($paid && $order->user) {
            $this->messages->email($order->user, $this->pos->receipt($order->studio, $order->load('items')), "receipt:order:{$order->id}");
        }

        return ['state' => 'paid', 'message' => null];
    }

    private function closed(Order $order): array
    {
        $this->orders->release($order, 'cancelled');

        return ['state' => 'closed', 'message' => null];
    }

    /**
     * Not paid yet: is the reader still asking for a card, or did it fail (declined, cancelled on the
     * reader)? The reader comes first: after a decline the payment keeps its error while a retry runs.
     */
    private function onReader(Order $order, array $intent): array
    {
        $action = $order->cardReader ? ($this->stripe->retrieveReader($order->studio, $order->cardReader->stripe_reader_id)['action'] ?? null) : null;
        $mine = $action && ($action['process_payment_intent']['payment_intent'] ?? null) === $order->stripe_payment_intent_id;
        if ($mine && ($action['status'] ?? '') !== 'failed') {
            return ['state' => 'waiting', 'message' => null]; // in progress, or succeeded and the payment is catching up
        }
        $message = match (true) {
            $mine => $action['failure_message'] ?? $intent['last_payment_error']['message'] ?? 'The payment didn’t go through.',
            isset($intent['last_payment_error']['message']) => $intent['last_payment_error']['message'],
            ! $order->cardReader => 'That card reader was removed.',
            default => 'The reader isn’t showing this sale any more.',
        };

        return ['state' => 'failed', 'message' => $message];
    }

    /** Is the order's reader still showing this sale? (So cancelling doesn't clear someone else's.) */
    private function showing(Order $order): bool
    {
        try {
            $action = $this->stripe->retrieveReader($order->studio, $order->cardReader->stripe_reader_id)['action'] ?? null;
        } catch (StripeException) {
            return false;
        }

        return ($action['process_payment_intent']['payment_intent'] ?? null) === $order->stripe_payment_intent_id
            && ($action['status'] ?? '') === 'in_progress';
    }

    private function readerError(StripeException $e, CardReader $reader): string
    {
        return match ($e->stripeCode) {
            'terminal_reader_offline' => "{$reader->label} is offline. Check it’s on and connected to the internet, then try again.",
            'terminal_reader_busy' => "{$reader->label} is busy with another payment. Finish or cancel it on the reader, then try again.",
            'terminal_reader_timeout' => "{$reader->label} didn’t respond in time. Try again.",
            default => 'Stripe: '.$e->getMessage(),
        };
    }
}
