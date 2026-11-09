<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
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

    public function void(Order $order, User $staff): void
    {
        if ($order->isVoid()) {
            return;
        }
        DB::transaction(function () use ($order, $staff) {
            $order->update(['status' => 'void', 'voided_at' => now(), 'voided_by_user_id' => $staff->id]);
            $this->events->record('order.voided', $order->studio_id, ['order_id' => $order->id, 'number' => $order->number], $order->user_id);
        });
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
                Studio::whereKey($studio->id)->lockForUpdate()->first();
                $number = 1 + (int) (Order::where('studio_id', $studio->id)->max('number') ?? 1000);
                $order = Order::create($attributes + ['studio_id' => $studio->id, 'number' => $number, 'status' => 'paid']);
                $order->items()->createMany($items);
                $this->events->record('order.paid', $studio, [
                    'order_id' => $order->id,
                    'number' => $order->number,
                    'channel' => $order->channel,
                    'method' => $order->method,
                    'total_cents' => $order->total_cents,
                    'currency' => $order->currency,
                    'items' => array_column($items, 'description'),
                ], $order->user_id);

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

    /** Stripe fields can be an ID or an expanded object. */
    private function id(mixed $value): ?string
    {
        return is_array($value) ? ($value['id'] ?? null) : (is_string($value) ? $value : null);
    }
}
