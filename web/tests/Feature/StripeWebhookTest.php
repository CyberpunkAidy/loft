<?php

namespace Tests\Feature;

use App\Events\EntitlementGranted;
use App\Events\PaymentFailed;
use App\Models\Entitlement;
use App\Models\StripeEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    private function event(string $type, array $object, string $account, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'account' => $account, 'data' => ['object' => $object]];
    }

    public function test_rejects_bad_signatures(): void
    {
        $this->fakeStripe();
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad'], '{"id":"evt_x","type":"x"}')
            ->assertStatus(400);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_paid_checkout_grants_credits_completes_the_booking_and_is_idempotent(): void
    {
        $this->fakeStripe();
        Event::fake([EntitlementGranted::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5, 'credit_validity_days' => 30]);
        $session = $this->classSession($this->program($studio));
        $attendee = $this->attendee($studio, $user);
        $cs = [
            'id' => 'cs_1', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => $session->id, 'attendee_id' => $attendee->id],
        ];

        $this->postWebhook($this->event('checkout.session.completed', $cs, 'acct_A'))->assertOk()->assertJson(['received' => true]);
        $this->postWebhook($this->event('checkout.session.completed', $cs, 'acct_A'))->assertOk()->assertJson(['duplicate' => true]);

        $entitlement = Entitlement::sole();
        $this->assertSame([5, 4], [$entitlement->credits_total, $entitlement->credits_remaining], 'one credit used by the booking');
        $this->assertTrue($entitlement->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame('booked', $session->bookings()->sole()->status);
        $this->assertNotNull(StripeEvent::sole()->processed_at);
        Event::assertDispatchedTimes(EntitlementGranted::class, 1);
    }

    public function test_season_subscription_sets_cancel_at_and_invoice_paid_first_does_not_duplicate(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true, 'timezone' => 'America/Denver']);
        $season = $studio->seasons()->create(['name' => 'Season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(5)->toDateString(), 'is_current' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'season', 'season_id' => $season->id, 'price_cents' => 11500]);
        $meta = ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id];
        $periodEnd = now()->addMonth()->getTimestamp();
        $this->fakeStripe([
            'api.stripe.com/v1/subscriptions/sub_1' => Http::sequence()
                ->push(['id' => 'sub_1', 'customer' => 'cus_1', 'current_period_end' => $periodEnd, 'metadata' => $meta]) // GET in invoice.paid
                ->push(['id' => 'sub_1']), // POST cancel_at
        ]);

        // invoice.paid can beat checkout.session.completed.
        $this->postWebhook($this->event('invoice.paid', ['id' => 'in_1', 'subscription' => 'sub_1', 'lines' => ['data' => []]], 'acct_A', 'evt_inv'))->assertOk();
        $this->postWebhook($this->event('checkout.session.completed', [
            'id' => 'cs_9', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid', 'subscription' => 'sub_1', 'customer' => 'cus_1', 'metadata' => $meta,
        ], 'acct_A', 'evt_cs'))->assertOk();

        $entitlement = Entitlement::sole();
        $this->assertSame('subscription', $entitlement->kind);
        $this->assertSame('cs_9', $entitlement->stripe_checkout_session_id);
        $this->assertSame($periodEnd, $entitlement->current_period_end->getTimestamp());
        $seasonEnd = $studio->parseLocal($season->ends_on->format('Y-m-d').' 23:59:59');
        $this->assertTrue($entitlement->expires_at->eq($seasonEnd));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_1'
            && $r['cancel_at'] == $seasonEnd->getTimestamp()
            && $r->header('Stripe-Account') === ['acct_A']);
    }

    public function test_failed_renewal_marks_past_due_once_and_paid_invoice_restores(): void
    {
        $this->fakeStripe();
        Event::fake([PaymentFailed::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $user = $this->userIn($studio);
        $e = $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_2']);

        $this->postWebhook($this->event('invoice.payment_failed', ['subscription' => 'sub_2'], 'acct_A', 'evt_f1'))->assertOk();
        $this->postWebhook($this->event('invoice.payment_failed', ['subscription' => 'sub_2'], 'acct_A', 'evt_f2'))->assertOk();
        $this->assertSame('past_due', $e->fresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);

        $end = now()->addMonth()->getTimestamp();
        $this->postWebhook($this->event('invoice.paid', ['subscription' => 'sub_2', 'lines' => ['data' => [['period' => ['end' => $end]]]]], 'acct_A', 'evt_p'))->assertOk();
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame($end, $e->fresh()->current_period_end->getTimestamp());
    }

    /** API versions from 2025-03-31 move invoice.subscription under parent, and current_period_end onto the items. */
    public function test_current_api_version_payload_shapes(): void
    {
        $this->fakeStripe();
        Event::fake([PaymentFailed::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $e = $this->membership($studio, $this->userIn($studio), null, ['stripe_subscription_id' => 'sub_4']);
        $parent = ['parent' => ['subscription_details' => ['subscription' => 'sub_4']]];

        $this->postWebhook($this->event('invoice.payment_failed', $parent, 'acct_A', 'evt_n1'))->assertOk();
        $this->assertSame('past_due', $e->fresh()->status);

        $end = now()->addMonth()->getTimestamp();
        $this->postWebhook($this->event('invoice.paid', $parent + ['lines' => ['data' => [['period' => ['end' => $end]]]]], 'acct_A', 'evt_n2'))->assertOk();
        $this->assertSame('active', $e->fresh()->status);

        $itemEnd = now()->addDays(40)->getTimestamp();
        $this->postWebhook($this->event('customer.subscription.updated', [
            'id' => 'sub_4', 'status' => 'active', 'cancel_at_period_end' => true,
            'items' => ['data' => [['id' => 'si_1', 'current_period_end' => $itemEnd]]],
        ], 'acct_A', 'evt_n3'))->assertOk();
        $this->assertSame($itemEnd, $e->fresh()->current_period_end->getTimestamp());
        $this->assertSame($itemEnd, $e->fresh()->expires_at->getTimestamp());
    }

    public function test_subscription_updates_and_deletion(): void
    {
        $this->fakeStripe();
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $e = $this->membership($studio, $this->userIn($studio), null, ['stripe_subscription_id' => 'sub_3']);
        $end = now()->addDays(20)->getTimestamp();

        $this->postWebhook($this->event('customer.subscription.updated', ['id' => 'sub_3', 'status' => 'active', 'cancel_at_period_end' => true, 'current_period_end' => $end], 'acct_A', 'evt_u1'))->assertOk();
        $this->assertSame($end, $e->fresh()->expires_at->getTimestamp());

        $this->postWebhook($this->event('customer.subscription.updated', ['id' => 'sub_3', 'status' => 'active', 'cancel_at_period_end' => false, 'current_period_end' => $end], 'acct_A', 'evt_u2'))->assertOk();
        $this->assertNull($e->fresh()->expires_at, 'resuming clears the end date');

        $this->postWebhook($this->event('customer.subscription.updated', ['id' => 'sub_3', 'status' => 'unpaid'], 'acct_A', 'evt_u3'))->assertOk();
        $this->assertSame('past_due', $e->fresh()->status);

        $this->postWebhook($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'acct_A', 'evt_d'))->assertOk();
        $this->assertSame('cancelled', $e->fresh()->status);
    }

    public function test_events_cannot_cross_studios(): void
    {
        $this->fakeStripe();
        $a = $this->studio(['stripe_account_id' => 'acct_A']);
        $b = $this->studio(['stripe_account_id' => 'acct_B']);
        $user = $this->userIn($a);
        $planInA = $this->plan($a);
        $subInA = $this->membership($a, $user, null, ['stripe_subscription_id' => 'sub_A']);

        // A checkout on B's account that claims A's plan is rejected (and left for Stripe to retry).
        $this->postWebhook($this->event('checkout.session.completed', [
            'id' => 'cs_x', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
            'metadata' => ['loft_plan_id' => $planInA->id, 'loft_user_id' => $user->id],
        ], 'acct_B', 'evt_x'))->assertStatus(500);
        $this->assertSame(0, Entitlement::where('studio_id', $b->id)->count());
        $this->assertNotNull(StripeEvent::where('event_id', 'evt_x')->value('error'));

        // B can't touch A's subscription either.
        $this->postWebhook($this->event('customer.subscription.deleted', ['id' => 'sub_A'], 'acct_B', 'evt_y'))->assertOk();
        $this->assertSame('active', $subInA->fresh()->status);

        // Unknown accounts are logged and ignored.
        $this->postWebhook($this->event('customer.subscription.deleted', ['id' => 'sub_A'], 'acct_unknown', 'evt_z'))->assertOk();
        $this->assertSame('active', $subInA->fresh()->status);
    }

    public function test_failed_events_are_retried(): void
    {
        $this->fakeStripe();
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $user = $this->userIn($studio);
        $event = $this->event('checkout.session.completed', [
            'id' => 'cs_r', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
            'metadata' => ['loft_plan_id' => 999999, 'loft_user_id' => $user->id],
        ], 'acct_A', 'evt_r');

        $this->postWebhook($event)->assertStatus(500);
        $plan = $this->plan($studio);
        $event['data']['object']['metadata']['loft_plan_id'] = $plan->id; // e.g. a fix deployed between retries
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, Entitlement::count());
        $this->assertNull(StripeEvent::sole()->error);
    }

    public function test_account_updates_track_connect_onboarding(): void
    {
        $this->fakeStripe();
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);

        $this->postWebhook($this->event('account.updated', ['id' => 'acct_A', 'charges_enabled' => true, 'details_submitted' => true], 'acct_A', 'evt_a1'))->assertOk();
        $this->assertTrue($studio->fresh()->stripeReady());

        $this->postWebhook($this->event('account.application.deauthorized', ['id' => 'ca_1'], 'acct_A', 'evt_a2'))->assertOk();
        $this->assertFalse($studio->fresh()->stripeReady());
        $this->assertNull($studio->fresh()->stripe_account_id);
    }
}
