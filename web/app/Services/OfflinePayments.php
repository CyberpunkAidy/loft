<?php

namespace App\Services;

use App\Events\EntitlementGranted;
use App\Models\Entitlement;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Plans staff hand out at the front desk: paid in cash, by check, on a card terminal and so on, or
 * comped. Each grant leaves a Payment record (who, what, how much, who recorded it), which can be
 * voided to undo a mistake.
 */
class OfflinePayments
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly DomainEvents $events,
        private readonly Orders $orders,
    ) {}

    /**
     * Grant $plan to $member and record how it was paid. paid_on and ends_on are studio-local dates
     * (Y-m-d); amount_cents defaults to the plan's price.
     *
     * @param  array{method: string, amount_cents?: ?int, paid_on: string, ends_on?: ?string, note?: ?string}  $data
     */
    public function record(Plan $plan, User $member, User $staff, array $data): Payment
    {
        $studio = $plan->studio;
        $comp = $data['method'] === 'comp';

        $payment = DB::transaction(function () use ($plan, $member, $staff, $data, $studio, $comp) {
            $entitlement = $this->grant($plan, $member, $data['method'], $data['paid_on'], $data['ends_on'] ?? null,
                $comp ? 0 : ($data['amount_cents'] ?? $plan->price_cents));

            $payment = Payment::create([
                'studio_id' => $studio->id,
                'user_id' => $member->id,
                'plan_id' => $plan->id,
                'entitlement_id' => $entitlement->id,
                'method' => $data['method'],
                'amount_cents' => $comp ? 0 : ($data['amount_cents'] ?? $plan->price_cents),
                'currency' => $plan->currency,
                'paid_on' => $data['paid_on'],
                'note' => $data['note'] ?? null,
                'recorded_by_user_id' => $staff->id,
            ]);
            $this->orders->recordInPerson($payment, $entitlement, $plan);

            return $payment;
        });

        if (! $comp) {
            EntitlementGranted::dispatch($payment->entitlement); // the member's "thanks for your purchase" email
        }

        return $payment;
    }

    /**
     * Grant a plan sold in person (here, or at the point of sale). paid_on and ends_on are studio-local
     * dates. Paid in person, a monthly membership covers a month; comps stay open-ended; packs follow
     * their validity; ends_on overrides all of that.
     */
    public function grant(Plan $plan, User $member, string $method, string $paidOn, ?string $endsOn, int $amountCents): Entitlement
    {
        $studio = $plan->studio;
        $entitlement = $this->entitlements->grantFromPlan($plan, $member->id, [], ['source' => $method, 'amount_cents' => $amountCents]);
        if ($endsOn) {
            $entitlement->update(['expires_at' => $studio->parseLocal("{$endsOn} 23:59:59")]);
        } elseif ($method !== 'comp' && $plan->kind === 'subscription') {
            $entitlement->update(['expires_at' => $studio->parseLocal("{$paidOn} 23:59:59")->addMonthNoOverflow()]);
        }

        return $entitlement;
    }

    /** Undo a recorded payment: the record stays, marked void, and the plan it granted is cancelled. */
    public function void(Payment $payment, User $staff): void
    {
        if ($payment->order) {
            $this->orders->void($payment->order, $staff); // voids this payment record too

            return;
        }
        DB::transaction(function () use ($payment, $staff) {
            $payment->update(['voided_at' => now(), 'voided_by_user_id' => $staff->id]);
            $entitlement = $payment->entitlement;
            if ($entitlement instanceof Entitlement && in_array($entitlement->status, ['active', 'past_due'], true)) {
                $entitlement->update(['status' => 'cancelled', 'expires_at' => now()]);
                $this->events->forEntitlement('entitlement.ended', $entitlement, ['reason' => 'voided']);
            }
        });
    }
}
