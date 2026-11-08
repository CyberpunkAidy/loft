<?php

namespace Tests\Feature;

use App\Events\EntitlementGranted;
use App\Events\PaymentFailed;
use App\Mail\StudioMessage;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\StripeEvent;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Webhooks carry only the object's id as far as the app is concerned; it fetches the object from
 * the Stripe API (faked here) in its pinned API version, so any endpoint API version works.
 */
class StripeWebhookTest extends TestCase
{
    /** @var array<string, array<string, mixed>> "{account}|{path}" => object served by the fake API */
    private array $objects = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
            if ($request->method() === 'POST') {
                return Http::response(['id' => basename($path)]); // e.g. setting cancel_at on a subscription
            }
            $key = ($request->header('Stripe-Account')[0] ?? '').'|'.$path;

            return isset($this->objects[$key])
                ? Http::response($this->objects[$key])
                : Http::response(['error' => ['message' => 'No such object: '.$path, 'code' => 'resource_missing']], 404);
        }]);
    }

    private function serve(string $account, string $path, array $object): void
    {
        $this->objects[$account.'|'.$path] = $object;
    }

    /** A webhook as Stripe sends it; $object is whatever shape the endpoint's API version uses. */
    private function event(string $type, array $object, string $account, string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => $type, 'account' => $account, 'data' => ['object' => $object]];
    }

    public function test_rejects_bad_signatures(): void
    {
        $this->call('POST', '/stripe/webhook', [], [], [], ['HTTP_STRIPE_SIGNATURE' => 't='.time().',v1=bad'], '{"id":"evt_x","type":"x"}')
            ->assertStatus(400);
        $this->assertSame(0, StripeEvent::count());
    }

    public function test_paid_checkout_grants_credits_completes_the_booking_and_is_idempotent(): void
    {
        Event::fake([EntitlementGranted::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5, 'credit_validity_days' => 30]);
        $session = $this->classSession($this->program($studio));
        $attendee = $this->attendee($studio, $user);
        $this->serve('acct_A', 'checkout/sessions/cs_1', [
            'id' => 'cs_1', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => $session->id, 'attendee_id' => $attendee->id],
        ]);
        $event = $this->event('checkout.session.completed', ['id' => 'cs_1', 'object' => 'checkout.session'], 'acct_A');

        $this->postWebhook($event)->assertOk()->assertJson(['received' => true]);
        $this->postWebhook($event)->assertOk()->assertJson(['duplicate' => true]);

        $entitlement = Entitlement::sole();
        $this->assertSame([5, 4], [$entitlement->credits_total, $entitlement->credits_remaining], 'one credit used by the booking');
        $this->assertTrue($entitlement->expires_at->between(now()->addDays(29), now()->addDays(31)));
        $this->assertSame('booked', $session->bookings()->sole()->status);
        $this->assertNotNull(StripeEvent::sole()->processed_at);
        Event::assertDispatchedTimes(EntitlementGranted::class, 1);
        $this->assertSame(['entitlement.granted', 'booking.booked'], DomainEvent::orderBy('id')->pluck('type')->all(), 'recorded once, despite the retry');
        $this->assertSame(['stripe', $plan->id, $user->id], [DomainEvent::first()->payload['source'], DomainEvent::first()->payload['plan_id'], DomainEvent::first()->user_id]);
    }

    public function test_the_booking_after_checkout_takes_the_chosen_apparatus(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5]);
        $room = $studio->rooms()->create(['name' => 'Main', 'max_capacity' => 10]);
        $silks = $room->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Silks', 'quantity' => 4, 'max_per_item' => 1]);
        $lyra = $room->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Lyra', 'quantity' => 2, 'max_per_item' => 1]);
        $session = $this->classSession($this->program($studio), ['room_id' => $room->id]);
        $session->apparatus()->sync([$silks->id => ['quantity' => 4, 'max_per_item' => 1], $lyra->id => ['quantity' => 2, 'max_per_item' => 1]]);
        $attendee = $this->attendee($studio, $user);
        $this->serve('acct_A', 'checkout/sessions/cs_1', [
            'id' => 'cs_1', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1',
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => $session->id, 'attendee_id' => $attendee->id, 'apparatus_id' => $lyra->id],
        ]);

        $this->postWebhook($this->event('checkout.session.completed', ['id' => 'cs_1'], 'acct_A'))->assertOk();

        $this->assertSame('Lyra 1', $session->bookings()->sole()->apparatusLabel(), 'not Silks, which had the most room');
    }

    public function test_a_delayed_payment_is_granted_when_it_clears(): void
    {
        Event::fake([EntitlementGranted::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5]);
        $session = $this->classSession($this->program($studio));
        $attendee = $this->attendee($studio, $user);
        $cs = [
            'id' => 'cs_bank', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'unpaid', 'customer' => 'cus_1',
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => $session->id, 'attendee_id' => $attendee->id],
        ];
        $this->serve('acct_A', 'checkout/sessions/cs_bank', $cs);

        // Checkout completes while the bank debit is still processing: nothing is granted yet.
        $this->postWebhook($this->event('checkout.session.completed', ['id' => 'cs_bank'], 'acct_A', 'evt_done'))->assertOk();
        $this->assertSame(0, Entitlement::count());
        $this->assertSame(0, $session->bookings()->count());

        // Days later the payment clears.
        $this->serve('acct_A', 'checkout/sessions/cs_bank', ['payment_status' => 'paid'] + $cs);
        $this->postWebhook($this->event('checkout.session.async_payment_succeeded', ['id' => 'cs_bank'], 'acct_A', 'evt_paid'))->assertOk();

        $this->assertSame(4, Entitlement::sole()->credits_remaining, 'one credit used by the booking');
        $this->assertSame('booked', $session->bookings()->sole()->status);
        Event::assertDispatchedTimes(EntitlementGranted::class, 1);
    }

    public function test_a_failed_delayed_payment_tells_the_member(): void
    {
        Mail::fake();
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $plan = $this->plan($studio, ['kind' => 'pack', 'name' => '5-Class Pack']);
        $meta = ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => 7, 'attendee_id' => 1];
        $this->serve('acct_A', 'checkout/sessions/cs_bank', ['id' => 'cs_bank', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'unpaid', 'metadata' => $meta]);
        $this->serve('acct_A', 'checkout/sessions/cs_sub', ['id' => 'cs_sub', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'unpaid', 'metadata' => $meta]);

        $this->postWebhook($this->event('checkout.session.async_payment_failed', ['id' => 'cs_bank'], 'acct_A', 'evt_1'))->assertOk();
        // Subscriptions report failures through invoice.payment_failed, so this one sends nothing.
        $this->postWebhook($this->event('checkout.session.async_payment_failed', ['id' => 'cs_sub'], 'acct_A', 'evt_2'))->assertOk();

        $this->assertSame(0, Entitlement::count());
        Mail::assertQueuedCount(1);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->hasTo('pat@example.com')
            && $m->headline === "Your payment didn't go through"
            && str_contains($m->lines[0], 'for 5-Class Pack')
            && $m->lines[1] === "The class you were booking wasn't booked either.");
        $this->assertSame([$user->id, 'cs_bank'], [DomainEvent::where('type', 'checkout.failed')->sole()->user_id, DomainEvent::where('type', 'checkout.failed')->sole()->payload['checkout_session_id']]);
    }

    public function test_season_subscription_sets_cancel_at_and_invoice_paid_first_does_not_duplicate(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true, 'timezone' => 'America/Denver']);
        $season = $studio->seasons()->create(['name' => 'Season', 'starts_on' => now()->subMonth()->toDateString(), 'ends_on' => now()->addMonths(5)->toDateString(), 'is_current' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'season', 'season_id' => $season->id, 'price_cents' => 11500]);
        $meta = ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id];
        $periodEnd = now()->addMonth()->getTimestamp();
        $this->serve('acct_A', 'subscriptions/sub_1', ['id' => 'sub_1', 'customer' => 'cus_1', 'status' => 'active', 'current_period_end' => $periodEnd, 'metadata' => $meta]);
        $this->serve('acct_A', 'invoices/in_1', ['id' => 'in_1', 'subscription' => 'sub_1', 'lines' => ['data' => []]]);
        $this->serve('acct_A', 'checkout/sessions/cs_9', [
            'id' => 'cs_9', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'paid', 'subscription' => 'sub_1', 'customer' => 'cus_1', 'metadata' => $meta,
        ]);

        // invoice.paid can beat checkout.session.completed.
        $this->postWebhook($this->event('invoice.paid', ['id' => 'in_1'], 'acct_A', 'evt_inv'))->assertOk();
        $this->postWebhook($this->event('checkout.session.completed', ['id' => 'cs_9'], 'acct_A', 'evt_cs'))->assertOk();

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
        Event::fake([PaymentFailed::class]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $user = $this->userIn($studio);
        $e = $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_2']);
        $end = now()->addMonth()->getTimestamp();
        $this->serve('acct_A', 'invoices/in_f1', ['id' => 'in_f1', 'subscription' => 'sub_2']);
        $this->serve('acct_A', 'invoices/in_f2', ['id' => 'in_f2', 'subscription' => 'sub_2']);
        $this->serve('acct_A', 'invoices/in_p', ['id' => 'in_p', 'subscription' => 'sub_2', 'lines' => ['data' => [['period' => ['end' => $end]]]]]);

        $this->postWebhook($this->event('invoice.payment_failed', ['id' => 'in_f1'], 'acct_A', 'evt_f1'))->assertOk();
        $this->postWebhook($this->event('invoice.payment_failed', ['id' => 'in_f2'], 'acct_A', 'evt_f2'))->assertOk();
        $this->assertSame('past_due', $e->fresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
        $this->assertSame('in_f1', DomainEvent::where('type', 'payment.failed')->sole()->payload['invoice_id']);

        $this->postWebhook($this->event('invoice.paid', ['id' => 'in_p'], 'acct_A', 'evt_p'))->assertOk();
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame($end, $e->fresh()->current_period_end->getTimestamp());
    }

    public function test_subscription_updates_and_deletion(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $e = $this->membership($studio, $this->userIn($studio), null, ['stripe_subscription_id' => 'sub_3']);
        $end = now()->addDays(20)->getTimestamp();
        $updated = fn (string $eventId) => $this->postWebhook($this->event('customer.subscription.updated', ['id' => 'sub_3'], 'acct_A', $eventId))->assertOk();

        $this->serve('acct_A', 'subscriptions/sub_3', ['id' => 'sub_3', 'status' => 'active', 'cancel_at_period_end' => true, 'current_period_end' => $end]);
        $updated('evt_u1');
        $this->assertSame($end, $e->fresh()->expires_at->getTimestamp());

        $this->serve('acct_A', 'subscriptions/sub_3', ['id' => 'sub_3', 'status' => 'active', 'cancel_at_period_end' => false, 'current_period_end' => $end]);
        $updated('evt_u2');
        $this->assertNull($e->fresh()->expires_at, 'resuming clears the end date');

        $this->serve('acct_A', 'subscriptions/sub_3', ['id' => 'sub_3', 'status' => 'unpaid', 'current_period_end' => $end]);
        $updated('evt_u3');
        $this->assertSame('past_due', $e->fresh()->status);

        $this->postWebhook($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'acct_A', 'evt_d'))->assertOk();
        $this->assertSame('cancelled', $e->fresh()->status);
        $this->postWebhook($this->event('customer.subscription.deleted', ['id' => 'sub_3'], 'acct_A', 'evt_d2'))->assertOk();

        // The history for churn: choosing to leave, changing their mind, and the end (once).
        $this->assertSame(
            ['entitlement.cancel_scheduled', 'entitlement.cancel_unscheduled', 'entitlement.ended'],
            DomainEvent::where('studio_id', $studio->id)->orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame('cancelled', DomainEvent::where('type', 'entitlement.ended')->sole()->payload['reason']);
    }

    public function test_newer_api_version_payloads_work(): void
    {
        // Since API version 2025-03-31, invoices name their subscription under parent.subscription_details
        // and subscriptions keep period ends on their items. The app reads neither from the payload.
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $e = $this->membership($studio, $this->userIn($studio), null, ['stripe_subscription_id' => 'sub_4', 'status' => 'past_due']);
        $end = now()->addMonth()->getTimestamp();
        $this->serve('acct_A', 'invoices/in_4', ['id' => 'in_4', 'subscription' => 'sub_4', 'lines' => ['data' => [['period' => ['end' => $end]]]]]);
        $this->serve('acct_A', 'subscriptions/sub_4', ['id' => 'sub_4', 'status' => 'active', 'cancel_at' => $end, 'current_period_end' => $end]);

        $this->postWebhook($this->event('invoice.paid', [
            'id' => 'in_4', 'object' => 'invoice',
            'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_4']],
        ], 'acct_A', 'evt_new_inv'))->assertOk();
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame($end, $e->fresh()->current_period_end->getTimestamp());

        $this->postWebhook($this->event('customer.subscription.updated', [
            'id' => 'sub_4', 'object' => 'subscription', 'status' => 'active', 'cancel_at' => $end,
            'items' => ['data' => [['current_period_end' => $end]]],
        ], 'acct_A', 'evt_new_sub'))->assertOk();
        $this->assertSame($end, $e->fresh()->expires_at->getTimestamp());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.stripe.com/v1/invoices/in_4'
            && $r->header('Stripe-Version') === ['2024-06-20']
            && $r->header('Stripe-Account') === ['acct_A']);
    }

    public function test_events_cannot_cross_studios(): void
    {
        $a = $this->studio(['stripe_account_id' => 'acct_A']);
        $b = $this->studio(['stripe_account_id' => 'acct_B']);
        $user = $this->userIn($a);
        $planInA = $this->plan($a);
        $subInA = $this->membership($a, $user, null, ['stripe_subscription_id' => 'sub_A']);

        // A checkout on B's account that claims A's plan is rejected (and left for Stripe to retry).
        $this->serve('acct_B', 'checkout/sessions/cs_x', [
            'id' => 'cs_x', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
            'metadata' => ['loft_plan_id' => $planInA->id, 'loft_user_id' => $user->id],
        ]);
        $this->postWebhook($this->event('checkout.session.completed', ['id' => 'cs_x'], 'acct_B', 'evt_x'))->assertStatus(500);
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
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $user = $this->userIn($studio);
        $session = ['id' => 'cs_r', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'metadata' => ['loft_plan_id' => 999999, 'loft_user_id' => $user->id]];
        $this->serve('acct_A', 'checkout/sessions/cs_r', $session);
        $event = $this->event('checkout.session.completed', ['id' => 'cs_r'], 'acct_A', 'evt_r');

        $this->postWebhook($event)->assertStatus(500);
        $session['metadata']['loft_plan_id'] = $this->plan($studio)->id; // e.g. a fix deployed between retries
        $this->serve('acct_A', 'checkout/sessions/cs_r', $session);
        $this->postWebhook($event)->assertOk();

        $this->assertSame(1, Entitlement::count());
        $this->assertNull(StripeEvent::sole()->error);
    }

    public function test_account_updates_track_connect_onboarding(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);
        $this->serve('', 'accounts/acct_A', ['id' => 'acct_A', 'charges_enabled' => true, 'details_submitted' => true]);

        $this->postWebhook($this->event('account.updated', ['id' => 'acct_A'], 'acct_A', 'evt_a1'))->assertOk();
        $this->assertTrue($studio->fresh()->stripeReady());

        $this->postWebhook($this->event('account.application.deauthorized', ['id' => 'ca_1'], 'acct_A', 'evt_a2'))->assertOk();
        $this->assertFalse($studio->fresh()->stripeReady());
        $this->assertNull($studio->fresh()->stripe_account_id);
    }
}
