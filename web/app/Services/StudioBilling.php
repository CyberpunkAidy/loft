<?php

namespace App\Services;

use App\Mail\LoftNotice;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Support\BillingState;
use App\Support\CheckoutException;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Loft's own subscription (decision 36): each studio pays Loft monthly or yearly (decision 40), on
 * the platform Stripe account. A studio starts with a free trial (no card), subscribes through
 * Stripe Checkout and manages its card and plan in Stripe's billing portal. A subscription keeps
 * the price it started at (the price lock), so changing the price in config affects only new ones. When the trial or subscription ends, or payments
 * fail, it has grace_days of reminders with everything working, then pauses until it pays.
 * Studios can also be comped (free), as every studio already on Loft was.
 *
 * The studio's Stripe subscription is mirrored in its billing_* columns: from the success redirect,
 * the billing webhook (StudioBillingWebhooks), and the hourly sweep for anything a webhook missed.
 */
class StudioBilling
{
    /** Stripe subscription statuses that count as subscribed. */
    public const LIVE = ['active', 'trialing'];

    /** A subscription whose payments are failing: grace from the first failure, then paused. */
    public const FAILING = ['past_due', 'unpaid'];

    /** A subscription that's over for good. */
    public const ENDED = ['canceled', 'incomplete_expired'];

    /** Billing periods a studio can subscribe for. */
    public const INTERVALS = ['month', 'year'];

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly Messages $messages,
    ) {}

    public function state(Studio $studio, ?Carbon $now = null): BillingState
    {
        $now ??= now();
        if ($studio->comped_at) {
            return new BillingState(BillingState::COMPED);
        }
        $subscription = [
            'periodEndsAt' => $studio->billing_period_ends_at,
            'cancelsAt' => $studio->billing_cancels_at,
        ];
        if (in_array($studio->billing_status, self::LIVE, true)) {
            return new BillingState(BillingState::SUBSCRIBED, ...$subscription, inStripeTrial: $studio->billing_status === 'trialing');
        }

        $trialEndsAt = $this->trialEndsAt($studio);
        if (in_array($studio->billing_status, self::FAILING, true)) {
            $pausesAt = ($studio->billing_payment_failed_at ?? $now)->copy()->addDays($this->graceDays());

            return new BillingState($now->lt($pausesAt) ? BillingState::GRACE : BillingState::PAUSED, $trialEndsAt, $pausesAt, 'payment_failed', ...$subscription);
        }
        if ($now->lt($trialEndsAt)) {
            return new BillingState(BillingState::TRIAL, $trialEndsAt);
        }

        // No subscription, or it ended: grace runs from whichever came last, the trial's end or the subscription's.
        $cancelled = $studio->billing_ended_at && $studio->billing_ended_at->gt($trialEndsAt);
        $pausesAt = ($cancelled ? $studio->billing_ended_at : $trialEndsAt)->copy()->addDays($this->graceDays());

        return new BillingState($now->lt($pausesAt) ? BillingState::GRACE : BillingState::PAUSED, $trialEndsAt, $pausesAt, $cancelled ? 'cancelled' : 'trial_ended');
    }

    /** When the free trial ends: set at signup; studios from before then count from when they were created. */
    public function trialEndsAt(Studio $studio): Carbon
    {
        return ($studio->trial_ends_at ?? ($studio->created_at ?? now())->copy()->addDays($this->trialDays()))->copy();
    }

    public function trialDays(): int
    {
        return max(0, (int) config('loft.billing.trial_days'));
    }

    public function graceDays(): int
    {
        return max(0, (int) config('loft.billing.grace_days'));
    }

    /** "$49", or "$49.50": the monthly price of a new subscription. */
    public function priceLabel(): string
    {
        return $this->label((int) config('loft.billing.monthly_cents'));
    }

    /** The yearly price in cents, or null when there's no yearly plan (LOFT_YEARLY_PRICE_CENTS=0). */
    public function yearlyCents(): ?int
    {
        $cents = (int) config('loft.billing.yearly_cents');

        return $cents > 0 ? $cents : null;
    }

    /** "$490", or null when there's no yearly plan. */
    public function yearlyPriceLabel(): ?string
    {
        return ($cents = $this->yearlyCents()) ? $this->label($cents) : null;
    }

    /** What yearly works out at each month: "$40.83". */
    public function yearlyMonthlyLabel(): ?string
    {
        return ($cents = $this->yearlyCents()) ? $this->label((int) round($cents / 12)) : null;
    }

    /**
     * What a year on the yearly plan saves over twelve months: "2 months free" when it's a whole
     * number of months, otherwise "save $98". Null without a yearly plan or a saving.
     */
    public function yearlySaving(): ?string
    {
        $monthly = (int) config('loft.billing.monthly_cents');
        $saved = $monthly * 12 - (int) $this->yearlyCents();
        if (! $this->yearlyCents() || $monthly <= 0 || $saved <= 0) {
            return null;
        }

        return $saved % $monthly === 0 ? ($saved / $monthly === 1 ? '1 month free' : ($saved / $monthly).' months free') : 'save '.$this->label($saved);
    }

    /** "$49 a month" or, with a yearly plan, "$49 a month or $490 a year". */
    public function offerLabel(): string
    {
        return $this->priceLabel().' a month'.(($yearly = $this->yearlyPriceLabel()) ? ' or '.$yearly.' a year' : '');
    }

    /**
     * The plan a subscribed studio is on, as Stripe charges it (which can differ from today's
     * prices): "$49 a month", "$490 a year". Studios synced before plans were recorded show today's
     * monthly price.
     */
    public function planLabel(Studio $studio): string
    {
        $interval = $studio->billing_interval === 'year' ? 'year' : 'month';
        $cents = $studio->billing_amount_cents ?? (int) config('loft.billing.'.($interval === 'year' ? 'yearly_cents' : 'monthly_cents'));

        return $this->label($cents).' a '.$interval;
    }

    private function label(int $cents): string
    {
        return str_replace('.00', '', Money::format($cents, (string) config('loft.billing.currency')));
    }

    /* ------------------------------------------------------------------ Subscribing */

    /**
     * A Stripe Checkout page for the studio's subscription. In the trial, the card is saved now and
     * first charged when the trial ends (Stripe needs that to be at least 48 hours away).
     *
     * @throws CheckoutException
     */
    public function checkoutUrl(Studio $studio, User $owner, string $successUrl, string $cancelUrl, string $interval = 'month'): string
    {
        if (! in_array($interval, self::INTERVALS, true) || ($interval === 'year' && ! $this->yearlyCents())) {
            throw new CheckoutException('Choose monthly'.($this->yearlyCents() ? ' or yearly' : '').' billing.');
        }
        $state = $this->state($studio);
        if (! $state->canSubscribe()) {
            throw new CheckoutException(match (true) {
                $state->status === BillingState::COMPED => 'Loft is free for '.$studio->name.'. There’s nothing to pay.',
                $state->paymentFailing() => 'Update the card in the billing portal instead.',
                default => $studio->name.' already has a subscription.',
            });
        }
        if (! $this->stripe->configured()) {
            throw new CheckoutException('Payments aren’t set up on Loft yet. Please try again later.');
        }

        if (! $studio->billing_customer_id) {
            $studio->update(['billing_customer_id' => $this->stripe->createBillingCustomer($studio, $owner)]);
        }
        $trialEnd = $state->status === BillingState::TRIAL && $state->trialEndsAt->gt(now()->addHours(49)) ? $state->trialEndsAt : null;
        $price = $this->planPrice($interval);

        return (string) $this->stripe->createBillingCheckout($studio, $studio->billing_customer_id, $price, $trialEnd, $successUrl, $cancelUrl,
            (bool) config('loft.billing.automatic_tax'), $this->checkoutMessage($studio, $interval, $trialEnd))['url'];
    }

    /**
     * The line by Stripe Checkout's pay button. Stripe shows the rest of Loft's trial as days free,
     * which owners can mistake for the yearly plan's free months; this says which is which.
     */
    public function checkoutMessage(Studio $studio, string $interval, ?Carbon $trialEnd): ?string
    {
        $plan = $interval === 'year' ? $this->yearlyPriceLabel().' a year' : $this->priceLabel().' a month';
        $saving = $interval === 'year' ? $this->yearlySaving() : null;
        $saving = match (true) {
            $saving === null => '',
            str_starts_with($saving, 'save ') => ', which saves '.substr($saving, 5).' compared with paying monthly',
            default => ', which is '.$saving.' compared with paying monthly',
        };
        if ($trialEnd) {
            return 'Your Loft free trial carries on until '.$studio->local($trialEnd)->format('F j').', so your card isn’t charged until then. After that it’s '.$plan.$saving.'.';
        }

        return $saving !== '' ? ucfirst($plan).$saving.'.' : null;
    }

    /** "$588": twelve months on the monthly plan, to set the yearly price against. */
    public function monthlyForAYearLabel(): string
    {
        return $this->label(12 * (int) config('loft.billing.monthly_cents'));
    }

    /** Back from Checkout: record the subscription now rather than wait for the webhook. True if it was this studio's. */
    public function complete(Studio $studio, string $checkoutSessionId): bool
    {
        $cs = $this->stripe->retrieveBillingCheckout($checkoutSessionId);
        if (! $studio->billing_customer_id || ($cs['customer'] ?? null) !== $studio->billing_customer_id || ($cs['client_reference_id'] ?? null) !== (string) $studio->id) {
            return false;
        }
        if (! is_array($cs['subscription'] ?? null)) {
            return false;
        }
        $this->sync($studio, $cs['subscription']);

        return true;
    }

    /**
     * Today's Stripe prices for new subscriptions, by interval (found or created by lookup key).
     *
     * @return array<string, string> interval => price ID
     */
    public function planPrices(): array
    {
        $prices = [];
        foreach ($this->yearlyCents() ? self::INTERVALS : ['month'] as $interval) {
            $prices[$interval] = $this->planPrice($interval);
        }

        return $prices;
    }

    /** Today's Stripe price for a new monthly or yearly subscription. */
    private function planPrice(string $interval): string
    {
        $cents = $interval === 'year' ? (int) $this->yearlyCents() : (int) config('loft.billing.monthly_cents');

        return $this->stripe->loftPrice($cents, (string) config('loft.billing.currency'), $interval);
    }

    /**
     * Stripe's billing portal: change the card, see invoices, cancel at the end of the period and,
     * with a yearly plan, switch between monthly and yearly ($switch opens straight on that).
     */
    public function portalUrl(Studio $studio, string $returnUrl, bool $switch = false): string
    {
        if (! $studio->billing_customer_id) {
            throw new CheckoutException('There’s no subscription to manage yet.');
        }
        $plans = $this->yearlyCents() ? array_values($this->planPrices()) : [];
        $subscription = $switch && in_array($studio->billing_status, self::LIVE, true) ? $studio->billing_subscription_id : null;

        return $this->stripe->createBillingPortal($studio->billing_customer_id, $returnUrl, $plans, $subscription);
    }

    /* ------------------------------------------------------------------ Keeping in step with Stripe */

    /** The studio a subscription (or anything with a customer) belongs to. */
    public function studioFor(array $object): ?Studio
    {
        $customer = $object['customer'] ?? null;
        $customer = is_array($customer) ? ($customer['id'] ?? null) : $customer;

        return is_string($customer) && $customer !== '' ? Studio::where('billing_customer_id', $customer)->first() : null;
    }

    /**
     * Mirror a Stripe subscription onto the studio. Safe to repeat and to receive out of order: it
     * always records the subscription as Stripe has it now. A studio has one subscription; if
     * another one turns up while the current one is still live, the current one stays.
     */
    public function sync(Studio $studio, array $subscription): void
    {
        $id = (string) ($subscription['id'] ?? '');
        $status = (string) ($subscription['status'] ?? '');
        if ($id === '' || $status === '') {
            return;
        }
        $current = $studio->billing_subscription_id;
        if ($current && $current !== $id && $this->stillLive($studio->billing_status)) {
            $fresh = $this->stripe->retrieveBillingSubscription($current);
            if ($this->stillLive($fresh['status'] ?? null)) {
                if ($this->stillLive($status)) {
                    Log::warning('A studio has two live Loft subscriptions; keeping the first', ['studio_id' => $studio->id, 'kept' => $current, 'other' => $id]);
                }

                return;
            }
        }

        $item = $subscription['items']['data'][0] ?? [];
        $periodEnd = $subscription['current_period_end'] ?? $item['current_period_end'] ?? null;
        $price = $item['price'] ?? $subscription['plan'] ?? [];
        $interval = $price['recurring']['interval'] ?? $price['interval'] ?? null;
        $cancelAt = $subscription['cancel_at'] ?? (! empty($subscription['cancel_at_period_end']) ? $periodEnd : null);
        $live = $this->stillLive($status);
        $customer = $subscription['customer'] ?? null;

        $studio->update([
            'billing_subscription_id' => $id,
            'billing_customer_id' => $studio->billing_customer_id ?? (is_string($customer) ? $customer : null),
            'billing_status' => $status,
            'billing_interval' => in_array($interval, self::INTERVALS, true) ? $interval : $studio->billing_interval,
            'billing_amount_cents' => is_numeric($price['unit_amount'] ?? $price['amount'] ?? null) ? (int) ($price['unit_amount'] ?? $price['amount']) : $studio->billing_amount_cents,
            'billing_period_ends_at' => self::time($periodEnd),
            'billing_cancels_at' => $live ? self::time($cancelAt) : null,
            'billing_payment_failed_at' => in_array($status, self::FAILING, true) ? ($studio->billing_payment_failed_at ?? now()) : null,
            'billing_ended_at' => match (true) {
                $live => null,
                in_array($status, self::ENDED, true) => self::time($subscription['ended_at'] ?? null) ?? now(),
                default => $studio->billing_ended_at, // incomplete or paused: not over, not running
            },
        ]);
    }

    /**
     * Hourly, for anything a webhook missed: subscriptions with failing payments, and live ones
     * past the end of their period (they should have renewed or ended).
     */
    public function sweep(): int
    {
        if (! $this->stripe->configured()) {
            return 0;
        }
        $synced = 0;
        Studio::query()
            ->whereNotNull('billing_subscription_id')
            ->where(fn ($q) => $q->whereIn('billing_status', [...self::FAILING, 'incomplete'])
                ->orWhere(fn ($q) => $q->whereIn('billing_status', self::LIVE)->where('billing_period_ends_at', '<', now()->subHour())))
            ->each(function (Studio $studio) use (&$synced) {
                rescue(function () use ($studio, &$synced) {
                    $this->sync($studio, $this->stripe->retrieveBillingSubscription($studio->billing_subscription_id));
                    $synced++;
                });
            });

        return $synced;
    }

    /** The studio is closing: stop charging it. */
    public function cancelForClosing(Studio $studio): void
    {
        if ($studio->billing_subscription_id && $this->stillLive($studio->billing_status) && $this->stripe->configured()) {
            $this->stripe->cancelBillingSubscription($studio->billing_subscription_id);
        }
    }

    private function stillLive(?string $status): bool
    {
        return in_array($status, [...self::LIVE, ...self::FAILING], true);
    }

    private static function time(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    /* ------------------------------------------------------------------ Reminders to owners */

    /**
     * Hourly: email each studio's owners when its trial is about to end (3 days before), when it
     * enters grace, and when it pauses. Each email goes once (dedupe keys).
     */
    public function remind(?Carbon $now = null): int
    {
        $now ??= now();
        $sent = 0;
        Studio::query()
            ->whereNull('comped_at')
            ->where(fn ($q) => $q->whereNull('billing_status')->orWhereNotIn('billing_status', self::LIVE))
            ->chunkById(100, function ($studios) use (&$sent, $now) {
                foreach ($studios as $studio) {
                    $sent += $this->remindStudio($studio, $now);
                }
            });

        return $sent;
    }

    private function remindStudio(Studio $studio, Carbon $now): int
    {
        $state = $this->state($studio, $now);
        [$kind, $key] = match (true) {
            $state->status === BillingState::TRIAL && $state->trialEndsAt->lte($now->copy()->addDays(3)) => ['billing_trial_ending', $state->trialEndsAt->getTimestamp()],
            $state->status === BillingState::GRACE => ['billing_grace', $state->reason.':'.$state->pausesAt->getTimestamp()],
            $state->status === BillingState::PAUSED && $state->pausesAt->gt($now->copy()->subDays(3)) => ['billing_paused', $state->pausesAt->getTimestamp()],
            default => [null, null],
        };
        if (! $kind) {
            return 0;
        }

        $sent = 0;
        foreach ($this->owners($studio) as $owner) {
            if ($this->messages->email($owner, $this->notice($studio, $state, $kind), "{$kind}:studio:{$studio->id}:user:{$owner->id}:{$key}")) {
                $sent++;
            }
        }

        return $sent;
    }

    /** @return Collection<int, User> */
    public function owners(Studio $studio): Collection
    {
        return User::whereIn('id', StudioUser::where('studio_id', $studio->id)->where('role', 'owner')->select('user_id'))->get();
    }

    public function notice(Studio $studio, BillingState $state, string $kind): LoftNotice
    {
        $date = fn (?Carbon $at) => $at ? $studio->local($at)->format('l, F j') : '';
        $offer = $this->offerLabel();
        $url = route('studio.admin.subscription', $studio);

        return match ($kind) {
            'billing_trial_ending' => new LoftNotice($studio, 'Your Loft trial ends '.$studio->local($state->trialEndsAt)->format('F j'), [
                "Your free trial of Loft for {$studio->name} ends on {$date($state->trialEndsAt)}.",
                "Subscribe for {$offer} to keep everything running. If you subscribe now, your card isn’t charged until the trial ends.",
            ], [], [], $url, 'Subscribe', $kind),
            'billing_grace' => match ($state->reason) {
                'payment_failed' => new LoftNotice($studio, 'Your Loft payment didn’t go through', [
                    "We couldn’t charge the card for {$studio->name}’s Loft subscription.",
                    "Update your card by {$date($state->pausesAt)} to keep online booking, payments and staff access running.",
                ], [], [], $url, 'Update your card', $kind),
                'cancelled' => new LoftNotice($studio, 'Your Loft subscription has ended', [
                    "{$studio->name}’s Loft subscription has ended.",
                    "Online booking, payments and staff access keep working until {$date($state->pausesAt)}. Subscribe again for {$offer} to keep them running.",
                ], [], [], $url, 'Subscribe', $kind),
                default => new LoftNotice($studio, 'Your Loft trial has ended', [
                    "Your free trial of Loft for {$studio->name} has ended.",
                    "Online booking, payments and staff access keep working until {$date($state->pausesAt)}. Subscribe for {$offer} to keep them running.",
                ], [], [], $url, 'Subscribe', $kind),
            },
            default => new LoftNotice($studio, $studio->name.' is paused on Loft', [
                'Members can’t book or pay online, and staff can only see the subscription page.',
                'Nothing has been deleted. Subscribe and everything comes back straight away.',
            ], [], [], $url, $state->paymentFailing() ? 'Update your card' : 'Subscribe', $kind),
        };
    }

    /* ------------------------------------------------------------------ Comps and trials (loft:billing) */

    public function comp(Studio $studio, ?string $note = null): void
    {
        $studio->update(['comped_at' => now(), 'comp_note' => $note]);
    }

    /** Ends a comp. The studio gets a trial of $trialDays from now (0: it's in grace straight away). */
    public function endComp(Studio $studio, int $trialDays): void
    {
        $studio->update(['comped_at' => null, 'comp_note' => null, 'trial_ends_at' => now()->addDays($trialDays)]);
    }

    /** Sets the trial to end $days from now: to extend it, or to start a new one. */
    public function setTrial(Studio $studio, int $days): void
    {
        $studio->update(['trial_ends_at' => now()->addDays($days)]);
    }
}
