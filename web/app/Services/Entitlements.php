<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Program;
use App\Models\SeriesEnrollment;
use App\Models\Studio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What an account may book with. Port of the plugin's Repos\Entitlements + Repos\Plans scope rules.
 */
class Entitlements
{
    public function __construct(private readonly DomainEvents $events) {}

    /**
     * The best entitlement for booking this session, or null.
     * Preference: an active membership in scope (under its weekly limit), then credits in scope
     * that expire soonest (credits that never expire go last).
     */
    public function usableFor(Studio $studio, int $userId, ClassSession $session): ?Entitlement
    {
        $candidates = $studio->entitlements()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->filter(fn (Entitlement $e) => $this->isUsable($e, $session));

        $membership = $candidates->first(fn (Entitlement $e) => ! $e->isCredits());
        if ($membership) {
            return $membership;
        }

        return $candidates
            ->filter(fn (Entitlement $e) => $e->isCredits())
            ->sortBy(fn (Entitlement $e) => $e->expires_at?->getTimestamp() ?? PHP_INT_MAX)
            ->first();
    }

    /** A make-up credit from a series (decision 47) that can pay for this session, soonest to expire first. */
    public function makeupFor(Studio $studio, int $userId, ClassSession $session): ?Entitlement
    {
        return $studio->entitlements()->where('user_id', $userId)->where('status', 'active')->whereNotNull('series_enrollment_id')
            ->orderBy('expires_at')->orderBy('id')->get()
            ->first(fn (Entitlement $e) => $this->isUsable($e, $session));
    }

    /**
     * One make-up credit for a series class cancelled in time (decision 47): good for any class,
     * including the series' own, until two weeks after the series ends.
     */
    public function grantMakeup(SeriesEnrollment $enrollment): Entitlement
    {
        $series = $enrollment->series;
        $entitlement = Entitlement::create([
            'studio_id' => $enrollment->studio_id,
            'user_id' => $enrollment->user_id,
            'series_enrollment_id' => $enrollment->id,
            'kind' => 'credits',
            'status' => 'active',
            'credits_total' => 1,
            'credits_remaining' => 1,
            'expires_at' => $series->makeupsExpireAt(),
        ]);
        $this->events->record('entitlement.granted', $entitlement->studio_id, [
            'entitlement_id' => $entitlement->id,
            'plan_name' => 'a make-up class',
            'kind' => 'credits',
            'source' => 'makeup',
            'series_id' => $series->id,
        ], $entitlement->user_id, $enrollment->attendee_id);

        return $entitlement;
    }

    /** Can this entitlement pay for this session right now? */
    public function isUsable(Entitlement $e, ClassSession $session): bool
    {
        $program = $session->program;
        if ((int) $e->studio_id !== (int) $session->studio_id || $e->status !== 'active' || $e->isExpired()) {
            return false; // past_due can't book until the card is fixed
        }
        if (! $e->covers($program)) {
            return false;
        }
        if ($program->members_only && $e->isCredits()) {
            return false; // "Members only (no drop-in)"
        }
        if ($e->isCredits()) {
            return (int) $e->credits_remaining > 0;
        }

        return $e->weekly_limit === null || $this->weeklyUsage($e, $session) < $e->weekly_limit;
    }

    /** Bookings on a membership in the Monday–Sunday week (studio time) that contains the session. */
    public function weeklyUsage(Entitlement $e, ClassSession $session): int
    {
        [$from, $to] = $this->weekBounds($session->studio, $session->starts_at);

        return Booking::query()
            ->where('entitlement_id', $e->id)
            ->whereIn('status', Booking::WEEKLY_USAGE_STATUSES)
            ->whereHas('classSession', fn ($q) => $q->where('starts_at', '>=', $from)->where('starts_at', '<', $to))
            ->count();
    }

    /** @return array{0: Carbon, 1: Carbon} UTC bounds of the studio-local week containing $at */
    public function weekBounds(Studio $studio, Carbon $at): array
    {
        $monday = $studio->local($at)->startOfWeek(Carbon::MONDAY);

        return [$monday->copy()->utc(), $monday->copy()->addWeek()->utc()];
    }

    /** Plans a member could buy to book this program. */
    public function eligiblePlans(Studio $studio, Program $program): Collection
    {
        return $studio->plans()
            ->where('active', true)
            ->with('season')
            ->orderBy('sort')->orderBy('id')
            ->get()
            ->filter(fn (Plan $p) => $p->covers($program) && (! $program->members_only || $p->isMembership()))
            ->values();
    }

    /**
     * Grant what a plan includes (from a webhook, or staff comping it).
     *
     * @param  array{customer_id?: ?string, subscription_id?: ?string, checkout_session_id?: ?string, current_period_end?: ?Carbon}  $stripe
     */
    /**
     * @param  array{source?: string, amount_cents?: int}  $event  for the entitlement.granted event:
     *                                                             how it was paid (stripe, cash, comp…)
     */
    public function grantFromPlan(Plan $plan, int $userId, array $stripe = [], array $event = []): Entitlement
    {
        $studio = $plan->studio;
        $data = [
            'studio_id' => $plan->studio_id,
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'kind' => $plan->isRecurring() ? 'subscription' : 'credits',
            'status' => 'active',
            'stripe_subscription_id' => $stripe['subscription_id'] ?? null,
            'stripe_customer_id' => $stripe['customer_id'] ?? null,
            'stripe_checkout_session_id' => $stripe['checkout_session_id'] ?? null,
            'program_scope' => $plan->program_scope,
            'weekly_limit' => $plan->weekly_limit,
        ];

        if ($plan->isRecurring()) {
            $data['current_period_end'] = $stripe['current_period_end'] ?? null;
            if ($plan->kind === 'season' && $plan->season) {
                $data['expires_at'] = $this->seasonEnd($studio, $plan);
            }
        } else {
            $credits = $plan->kind === 'dropin' ? 1 : (int) $plan->credits;
            $validity = $plan->credit_validity_days ?? (int) $studio->setting('credit_validity_days');
            $data['credits_total'] = $credits;
            $data['credits_remaining'] = $credits;
            $data['expires_at'] = $validity > 0 ? now()->addDays($validity) : null;
        }

        return DB::transaction(function () use ($data, $plan, $stripe, $event) {
            $entitlement = Entitlement::create($data);
            $entitlement->setRelation('plan', $plan);
            $this->events->forEntitlement('entitlement.granted', $entitlement, [
                'source' => $event['source'] ?? ($stripe ? 'stripe' : 'staff'),
                'amount_cents' => $event['amount_cents'] ?? null,
            ]);

            return $entitlement;
        });
    }

    /** End of the season's last day in studio time, as UTC. */
    public function seasonEnd(Studio $studio, Plan $plan): ?Carbon
    {
        return $plan->season ? $studio->parseLocal($plan->season->ends_on->format('Y-m-d').' 23:59:59') : null;
    }

    /** Take one credit. False if none were left (another booking got there first). */
    public function consumeCredit(Entitlement $e): bool
    {
        $taken = Entitlement::whereKey($e->id)->where('credits_remaining', '>', 0)->decrement('credits_remaining') === 1;
        $e->refresh();

        return $taken;
    }

    public function refundCredit(Entitlement $e): void
    {
        Entitlement::whereKey($e->id)->whereColumn('credits_remaining', '<', 'credits_total')->increment('credits_remaining');
        $e->refresh();
    }
}
