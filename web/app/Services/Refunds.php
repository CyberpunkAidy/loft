<?php

namespace App\Services;

use App\Models\Entitlement;
use App\Models\Order;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Support\RefundException;
use Illuminate\Support\Facades\DB;

/**
 * Refunds from Loft (decision 34): staff give back what's left of a sale paid through Stripe (online
 * or on a card reader), on the studio's account. The order records it and takes back what it paid for
 * (Orders::refunded). A renewing membership keeps running unless staff choose to end it too.
 * Refunds made in the Stripe Dashboard arrive by webhook instead (charge.refunded).
 */
class Refunds
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly Orders $orders,
        private readonly DomainEvents $events,
    ) {}

    /**
     * @throws RefundException when it can't be refunded from Loft, or the membership couldn't be ended
     * @throws StripeException when Stripe refuses the refund (nothing changes)
     */
    public function refund(Order $order, User $staff, bool $endMembership = false): void
    {
        if (! $order->canRefund()) {
            throw new RefundException($order->isUndone() ? "Sale #{$order->number} was already ".strtolower($order->statusLabel()).'.' : "Sale #{$order->number} can’t be refunded from Loft. Refund it in the Stripe Dashboard.");
        }
        $membership = $endMembership ? $this->membership($order) : null;
        $refund = $this->stripe->refundPaymentIntent($order->studio, $order->stripe_payment_intent_id);
        if (in_array($refund['status'] ?? 'succeeded', ['failed', 'canceled'], true)) {
            throw new StripeException('The refund didn’t go through'.(isset($refund['failure_reason']) ? ' ('.str_replace('_', ' ', $refund['failure_reason']).')' : '').'.');
        }
        $this->orders->refunded($order, $order->total_cents, $staff, ['refund_id' => $refund['id'] ?? null]);

        if ($membership) {
            try {
                $this->stripe->cancelSubscription($order->studio, $membership->stripe_subscription_id);
            } catch (StripeException $e) {
                report($e);
                throw new RefundException('Refunded, but Stripe didn’t end the membership ('.$e->getMessage().'). Cancel it in the Stripe Dashboard; Loft follows.', refunded: true);
            }
            DB::transaction(function () use ($membership) {
                $membership->update(['status' => 'cancelled', 'expires_at' => now()]);
                $this->events->forEntitlement('entitlement.ended', $membership, ['reason' => 'refunded']);
            });
        }
    }

    /** The renewing membership (a Stripe subscription) a sale paid for, if it's still running. */
    public function membership(Order $order): ?Entitlement
    {
        $entitlement = $order->items()->with('entitlement.plan')->get()->pluck('entitlement')->filter()
            ->first(fn (Entitlement $e) => filled($e->stripe_subscription_id) && in_array($e->status, ['active', 'past_due'], true));

        return $entitlement ?: null;
    }
}
