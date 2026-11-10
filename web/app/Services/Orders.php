<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\ProductVariant;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The orders ledger (roadmap step 5): every sale, however it was paid, as an order with items.
 * - Online, one-time (packs, drop-ins): one order per paid Checkout Session.
 * - Online, memberships and seasonal tuition: one order per paid invoice, the first and each renewal.
 * - In person: one order per payment staff record (comps too, at no charge), voided with it.
 * Amounts are what Stripe actually charged (after discounts and tax), or what staff entered.
 * Each order is recorded once (unique Stripe IDs) and records an order.paid domain event.
 */
class Orders
{
    public function __construct(private readonly DomainEvents $events) {}

    /** A paid one-time Checkout. Subscription checkouts are recorded from their invoices instead. */
    public function recordCheckout(Studio $studio, array $cs, Entitlement $entitlement): ?Order
    {
        if (($cs['mode'] ?? 'payment') !== 'payment' || empty($cs['id'])) {
            return null;
        }
        if ($existing = Order::where('stripe_checkout_session_id', $cs['id'])->first()) {
            return $existing;
        }
        $plan = $entitlement->plan;
        $total = (int) ($cs['amount_total'] ?? $plan?->price_cents ?? 0);
        $subtotal = (int) ($cs['amount_subtotal'] ?? $total);

        return $this->create($studio, [
            'user_id' => $entitlement->user_id,
            'channel' => 'online',
            'method' => 'stripe',
            'currency' => $cs['currency'] ?? $plan?->currency ?? $studio->currency,
            'subtotal_cents' => $subtotal,
            'discount_cents' => (int) ($cs['total_details']['amount_discount'] ?? 0),
            'tax_cents' => (int) ($cs['total_details']['amount_tax'] ?? 0),
            'total_cents' => $total,
            'stripe_checkout_session_id' => $cs['id'],
            'stripe_payment_intent_id' => $this->id($cs['payment_intent'] ?? null),
            'paid_at' => now(),
        ], [[
            'kind' => 'plan',
            'plan_id' => $plan?->id,
            'entitlement_id' => $entitlement->id,
            'description' => $plan?->name ?? 'Plan',
            'quantity' => 1,
            'unit_price_cents' => $subtotal,
            'total_cents' => $subtotal,
        ]]);
    }

    /** A paid membership or seasonal-tuition invoice: the first payment or a renewal. */
    public function recordInvoice(Studio $studio, array $invoice, Entitlement $entitlement): ?Order
    {
        if (empty($invoice['id'])) {
            return null;
        }
        // A subscription's opening $0 invoice (a trial, such as a membership continued from an
        // import that's paid until a later date) isn't a sale; the first real charge is.
        if ((int) ($invoice['total'] ?? 0) === 0 && ($invoice['billing_reason'] ?? null) === 'subscription_create') {
            return null;
        }
        if ($existing = Order::where('stripe_invoice_id', $invoice['id'])->first()) {
            return $existing;
        }
        $plan = $entitlement->plan;
        $items = [];
        foreach ($invoice['lines']['data'] ?? [] as $line) {
            $price = $line['price']['id'] ?? ($line['plan']['id'] ?? null);
            $kind = match (true) {
                $plan && $price === $plan->stripe_price_id => 'plan',
                $plan && $price !== null && $price === $plan->stripe_fee_price_id => 'fee',
                default => 'other',
            };
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $amount = (int) ($line['amount'] ?? 0);
            $items[] = [
                'kind' => $kind,
                'plan_id' => $kind === 'other' ? null : $plan?->id,
                'entitlement_id' => $kind === 'plan' ? $entitlement->id : null,
                'description' => match ($kind) {
                    'plan' => $plan->name,
                    'fee' => 'Registration fee',
                    default => (string) ($line['description'] ?? 'Other'),
                },
                'quantity' => $quantity,
                'unit_price_cents' => intdiv($amount, $quantity),
                'total_cents' => $amount,
            ];
        }
        $paid = (int) ($invoice['amount_paid'] ?? $invoice['total'] ?? $plan?->price_cents ?? 0);
        if (! $items) {
            $items[] = ['kind' => 'plan', 'plan_id' => $plan?->id, 'entitlement_id' => $entitlement->id, 'description' => $plan?->name ?? 'Membership',
                'quantity' => 1, 'unit_price_cents' => $paid, 'total_cents' => $paid];
        }
        $paidAt = $invoice['status_transitions']['paid_at'] ?? null;

        return $this->create($studio, [
            'user_id' => $entitlement->user_id,
            'channel' => 'online',
            'method' => 'stripe',
            'currency' => $invoice['currency'] ?? $plan?->currency ?? $studio->currency,
            'subtotal_cents' => (int) ($invoice['subtotal'] ?? array_sum(array_column($items, 'total_cents'))),
            'discount_cents' => (int) array_sum(array_column($invoice['total_discount_amounts'] ?? [], 'amount')),
            'tax_cents' => (int) ($invoice['tax'] ?? 0),
            'total_cents' => $paid,
            'stripe_invoice_id' => $invoice['id'],
            'stripe_payment_intent_id' => $this->id($invoice['payment_intent'] ?? null),
            'paid_at' => $paidAt ? Carbon::createFromTimestamp((int) $paidAt) : now(),
        ], $items);
    }

    /** A payment staff recorded (or a comp). Called inside OfflinePayments' transaction. */
    public function recordInPerson(Payment $payment, Entitlement $entitlement, Plan $plan): Order
    {
        $studio = $plan->studio;
        $paidOn = $payment->paid_on->toDateString();
        $order = $this->create($studio, [
            'user_id' => $payment->user_id,
            'channel' => 'in_person',
            'method' => $payment->method,
            'currency' => $payment->currency,
            'subtotal_cents' => $payment->amount_cents,
            'total_cents' => $payment->amount_cents,
            'recorded_by_user_id' => $payment->recorded_by_user_id,
            'paid_at' => $paidOn === $studio->localNow()->toDateString() ? now() : $studio->parseLocal("{$paidOn} 12:00:00"),
        ], [[
            'kind' => 'plan',
            'plan_id' => $plan->id,
            'entitlement_id' => $entitlement->id,
            'description' => $plan->name,
            'quantity' => 1,
            'unit_price_cents' => $payment->amount_cents,
            'total_cents' => $payment->amount_cents,
        ]]);
        $payment->update(['order_id' => $order->id]);

        return $order;
    }

    /** A sale at the point of sale (App\Services\PointOfSale), already priced and fulfilled. */
    public function recordSale(Studio $studio, array $attributes, array $items): Order
    {
        return $this->create($studio, $attributes, $items);
    }

    /**
     * Undo an in-person sale made by mistake: the order stays, marked void; plans it granted are
     * cancelled, products go back into stock, and its payment record (if any) is voided too. Sales
     * paid through Stripe are refunded instead (Refunds).
     */
    public function void(Order $order, User $staff, array $details = []): void
    {
        if ($order->isUndone() || $order->channel !== 'in_person') {
            return;
        }
        DB::transaction(function () use ($order, $staff, $details) {
            $order->update(['status' => 'void', 'voided_at' => now(), 'voided_by_user_id' => $staff->id]);
            $this->takeBack($order, 'voided');
            $payment = $order->payment;
            if ($payment && ! $payment->isVoided()) {
                $payment->update(['voided_at' => now(), 'voided_by_user_id' => $staff->id]);
            }
            $this->events->record('order.voided', $order->studio_id, ['order_id' => $order->id, 'number' => $order->number] + $details, $order->user_id);
        });
    }

    /**
     * Stripe gave money back for a sale, from Loft or the Stripe Dashboard (decision 34).
     * $refundedTotal is Stripe's running total for the payment, so a repeat or an out-of-order event
     * changes nothing. A full refund marks the order refunded and takes back what it paid for: plans
     * it granted are cancelled and products go back into stock. A renewing membership (a Stripe
     * subscription) keeps running, since one payment back doesn't end it; staff can end it when they
     * refund from Loft. A partial refund is recorded only.
     *
     * @return bool whether anything changed
     */
    public function refunded(Order $order, int $refundedTotal, ?User $staff = null, array $details = []): bool
    {
        return DB::transaction(function () use ($order, $refundedTotal, $staff, $details) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            $total = $locked ? min($refundedTotal, $locked->total_cents) : 0;
            if (! $locked || ! in_array($locked->status, ['paid', 'refunded'], true) || $total <= $locked->refunded_cents) {
                return false;
            }
            $amount = $total - $locked->refunded_cents;
            $full = $total >= $locked->total_cents;
            $locked->update([
                'refunded_cents' => $total,
                'refunded_at' => now(),
                'refunded_by_user_id' => $staff?->id,
            ] + ($full ? [
                'status' => 'refunded',
                'fulfillment_status' => $locked->fulfillment_status === 'to_pick_up' ? null : $locked->fulfillment_status,
            ] : []));
            if ($full) {
                $this->takeBack($locked, 'refunded');
            }
            $this->events->record('order.refunded', $locked->studio_id, [
                'order_id' => $locked->id,
                'number' => $locked->number,
                'amount_cents' => $amount,
                'currency' => $locked->currency,
                'full' => $full,
                'by' => $staff ? 'staff' : 'stripe',
            ] + $details, $locked->user_id);
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Cancel the plans an undone sale granted and restock its products. When it was refunded, a
     * renewing Stripe membership is left alone (Refunds ends it if staff ask).
     */
    private function takeBack(Order $order, string $reason): void
    {
        foreach ($order->items()->with(['entitlement.plan'])->get() as $item) {
            $entitlement = $item->entitlement;
            $renewing = $reason === 'refunded' && filled($entitlement?->stripe_subscription_id);
            if ($entitlement && ! $renewing && in_array($entitlement->status, ['active', 'past_due'], true)) {
                $entitlement->update(['status' => 'cancelled', 'expires_at' => now()]);
                $this->events->forEntitlement('entitlement.ended', $entitlement, ['reason' => $reason]);
            }
            if ($item->product_variant_id) {
                ProductVariant::whereKey($item->product_variant_id)->whereNotNull('stock')->increment('stock', $item->quantity);
            }
        }
    }

    /**
     * Insert the order and its items, numbered under a lock on the studio row, and record order.paid.
     * If the webhook and the checkout return page record the same sale at once, the second finds the first.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function create(Studio $studio, array $attributes, array $items): Order
    {
        try {
            return DB::transaction(function () use ($studio, $attributes, $items) {
                $order = Order::create($attributes + ['studio_id' => $studio->id, 'number' => $this->nextNumber($studio), 'status' => 'paid']);
                $order->items()->createMany($items);
                $this->recordPaid($order);

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            $existing = match (true) {
                ! empty($attributes['stripe_checkout_session_id']) => Order::where('stripe_checkout_session_id', $attributes['stripe_checkout_session_id'])->first(),
                ! empty($attributes['stripe_invoice_id']) => Order::where('stripe_invoice_id', $attributes['stripe_invoice_id'])->first(),
                default => null,
            };

            return $existing ?? throw $e;
        }
    }

    /**
     * An order waiting for payment (the online shop holds its stock meanwhile). It has no number or
     * paid_at until markPaid; it isn't a sale yet, so it stays out of totals and lists.
     */
    public function createPending(Studio $studio, array $attributes, array $items): Order
    {
        return DB::transaction(function () use ($studio, $attributes, $items) {
            $order = Order::create($attributes + ['studio_id' => $studio->id, 'status' => 'pending']);
            $order->items()->createMany($items);

            return $order;
        });
    }

    /**
     * A pending order was paid: number it, take Stripe's amounts, record order.paid. Returns false if
     * it wasn't pending (already paid, or released), so callers can act once.
     *
     * @param  array<string, mixed>  $attributes  amounts and Stripe IDs
     */
    public function markPaid(Order $order, array $attributes): bool
    {
        return DB::transaction(function () use ($order, $attributes) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }
            $locked->update($attributes + ['status' => 'paid', 'number' => $this->nextNumber($locked->studio), 'paid_at' => now()]);
            $this->recordPaid($locked);
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * A pending order that won't be paid (a checkout abandoned or failed, a card reader sale cancelled):
     * it gets $status and its products go back into stock. False if it wasn't pending.
     */
    public function release(Order $order, string $status): bool
    {
        return DB::transaction(function () use ($order, $status) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return false;
            }
            $locked->update(['status' => $status, 'fulfillment_status' => null]);
            foreach ($locked->items as $item) {
                if ($item->product_variant_id) {
                    ProductVariant::whereKey($item->product_variant_id)->whereNotNull('stock')->increment('stock', $item->quantity);
                }
            }
            $order->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    public function markPickedUp(Order $order, User $staff): void
    {
        if ($order->fulfillment_status !== 'to_pick_up' || $order->status !== 'paid') {
            return;
        }
        DB::transaction(function () use ($order, $staff) {
            $order->update(['fulfillment_status' => 'picked_up', 'picked_up_at' => now(), 'picked_up_by_user_id' => $staff->id]);
            $this->events->record('order.picked_up', $order->studio_id, ['order_id' => $order->id, 'number' => $order->number], $order->user_id);
        });
    }

    /** The studio's next order number. Call inside a transaction: it locks the studio row. */
    private function nextNumber(Studio $studio): int
    {
        Studio::whereKey($studio->id)->lockForUpdate()->first();

        return 1 + (int) (Order::where('studio_id', $studio->id)->max('number') ?? 1000);
    }

    private function recordPaid(Order $order): void
    {
        $this->events->record('order.paid', $order->studio_id, [
            'order_id' => $order->id,
            'number' => $order->number,
            'channel' => $order->channel,
            'method' => $order->method,
            'total_cents' => $order->total_cents,
            'currency' => $order->currency,
            'items' => $order->items()->pluck('description')->all(),
        ], $order->user_id);
    }

    /** Stripe fields can be an ID or an expanded object. */
    private function id(mixed $value): ?string
    {
        return is_array($value) ? ($value['id'] ?? null) : (is_string($value) ? $value : null);
    }
}
