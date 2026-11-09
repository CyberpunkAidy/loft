<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Where a studio stands with its Loft subscription (decision 36), worked out by StudioBilling::state():
 *
 * - comped: free; never asked to pay.
 * - subscribed: a live subscription (active, or in a Stripe trial before the first payment).
 * - trial: Loft's free trial, no subscription yet.
 * - grace: the trial ended, the subscription ended, or a payment failed; everything still works
 *   until $pausesAt, with reminders.
 * - paused: past $pausesAt. Staff see only the subscription page, and members can't book or buy
 *   online. Nothing is deleted; subscribing (or a successful payment) restores it all.
 *
 * $reason, for grace and paused: trial_ended, cancelled or payment_failed.
 */
final class BillingState
{
    public const COMPED = 'comped';

    public const SUBSCRIBED = 'subscribed';

    public const TRIAL = 'trial';

    public const GRACE = 'grace';

    public const PAUSED = 'paused';

    public function __construct(
        public readonly string $status,
        public readonly ?Carbon $trialEndsAt = null,
        public readonly ?Carbon $pausesAt = null,
        public readonly ?string $reason = null,
        public readonly ?Carbon $periodEndsAt = null,
        public readonly ?Carbon $cancelsAt = null,
        public readonly bool $inStripeTrial = false,
    ) {}

    public function paused(): bool
    {
        return $this->status === self::PAUSED;
    }

    /** Should be asked to subscribe: in the trial, in grace or paused without a subscription. */
    public function canSubscribe(): bool
    {
        return in_array($this->status, [self::TRIAL, self::GRACE, self::PAUSED], true) && $this->reason !== 'payment_failed';
    }

    /** A subscription whose payments are failing: the fix is a new card in the billing portal. */
    public function paymentFailing(): bool
    {
        return $this->reason === 'payment_failed';
    }

    /** Whole days left of the free trial (0 on its last day). */
    public function trialDaysLeft(?Carbon $now = null): int
    {
        if (! $this->trialEndsAt) {
            return 0;
        }

        return max(0, (int) floor(($now ?? now())->diffInDays($this->trialEndsAt, false)));
    }
}
