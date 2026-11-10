<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Studio;
use App\Models\User;
use App\Services\Stripe\CheckoutResult;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use App\Services\Stripe\StripeWebhooks;
use App\Support\CheckoutException;

/**
 * Buying a plan through Stripe Checkout, from the website or the app: start it (optionally finishing
 * a booking once paid), and confirm it when the member comes back, rather than wait for the webhook.
 */
class PlanCheckout
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StripeWebhooks $webhooks,
        private readonly MemberInvitations $invitations,
    ) {}

    /**
     * A booking to finish once the plan is paid for: the class and the person must be this studio's
     * and this member's; the apparatus must be one the class uses. Empty when there's no booking.
     *
     * @return array{session_id?: int, attendee_id?: int, apparatus_id?: int, online?: int}
     */
    public function bookingContext(Studio $studio, User $member, ?int $sessionId, ?int $attendeeId, ?int $apparatusId, bool $online = false): array
    {
        $session = $sessionId ? $studio->sessions()->find($sessionId) : null;
        $attendee = $attendeeId ? $studio->attendees()->where('user_id', $member->id)->find($attendeeId) : null;
        if (! $session || ! $attendee) {
            return [];
        }
        $context = ['session_id' => $session->id, 'attendee_id' => $attendee->id];
        if ($online && $session->isHybrid()) {
            $context['online'] = 1; // a hybrid class's online spot
        } elseif ($apparatusId && $session->apparatusSlots()->has($apparatusId)) {
            $context['apparatus_id'] = $apparatusId;
        }

        return $context;
    }

    /**
     * Open a Stripe Checkout Session for the plan; returns Stripe's session (its id and url).
     *
     * @throws CheckoutException|StripeException
     */
    public function start(Studio $studio, User $member, Plan $plan, array $context, string $successUrl, string $cancelUrl): array
    {
        if (! $plan->active) {
            throw new CheckoutException('That plan isn’t for sale.');
        }
        if ($studio->isPaused()) {
            throw new CheckoutException($studio->name.' isn’t taking online payments right now. Please contact the studio.');
        }
        if (! $studio->stripeReady()) {
            throw new CheckoutException('Online payments aren’t set up for this studio yet. Please contact the studio.');
        }
        if ($plan->kind === 'season' && $plan->season && $plan->season->ends_on->copy()->endOfDay()->isPast()) {
            throw new CheckoutException('That season has ended.');
        }

        // Continuing a membership imported from another system: it's already paid for until its
        // end date, so the card is first charged then (Stripe needs that to be 48+ hours away).
        if ($plan->isRecurring() && ($continues = $this->invitations->continuation($studio, $member->id, $plan->id))) {
            $context['continues'] = $continues->id;
            if ($continues->expires_at->gt(now()->addHours(49))) {
                $context['trial_end'] = $continues->expires_at->getTimestamp();
            }
        }

        return $this->stripe->createCheckout($studio, $member, $plan, $successUrl, $cancelUrl, $context);
    }

    /**
     * The member is back from Checkout: fulfil it now if it's paid (once; the webhook may have done it
     * already). Null if the Checkout Session isn't this member's, at this studio.
     *
     * @return array{cs: array<string, mixed>, result: CheckoutResult}|null
     *
     * @throws StripeException
     */
    public function confirm(Studio $studio, User $member, string $id): ?array
    {
        $cs = $this->stripe->retrieveCheckoutSession($studio, $id);
        $meta = $cs['metadata'] ?? [];
        if ((int) ($meta['loft_user_id'] ?? 0) !== (int) $member->id || (int) ($meta['loft_studio_id'] ?? 0) !== (int) $studio->id) {
            return null;
        }

        return ['cs' => $cs, 'result' => $this->webhooks->fulfillCheckout($studio, $cs)];
    }
}
