<?php

namespace Tests\Feature;

use App\Jobs\SendAutomatedMessage;
use App\Mail\LoftNotice;
use App\Models\Automation;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\StripeEvent;
use App\Models\Studio;
use App\Services\Automations;
use App\Services\Consent;
use App\Services\Shop;
use App\Services\StudioBilling;
use App\Support\BillingState;
use App\Support\CheckoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Loft's own subscription (decision 36): trial, subscribing, grace, pause, comps, and keeping in step with Stripe. */
class StudioBillingTest extends TestCase
{
    /** @var array<string, array> subscriptions the fake API serves */
    private array $subs = [];

    /** @var array<string, array> checkout sessions the fake API serves */
    private array $sessions = [];

    /** @var array<string, array> invoices the fake API serves */
    private array $invoices = [];

    /** @var array<string, string> lookup key => price id */
    private array $prices = [];

    private bool $productExists = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => fn (Request $request) => $this->stripeApi($request)]);
        config(['loft.billing.webhook_secret' => 'whsec_billing']);
    }

    private function stripeApi(Request $request)
    {
        $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
        $method = $request->method();
        $id = basename($path);

        return match (true) {
            $method === 'POST' && $path === 'customers' => Http::response(['id' => 'cus_loft_1']),
            $method === 'GET' && $path === 'prices' => Http::response(['data' => isset($this->prices[$request['lookup_keys'][0] ?? '']) ? [['id' => $this->prices[$request['lookup_keys'][0]]]] : []]),
            $method === 'GET' && $path === 'products/loft_studio' => $this->productExists
                ? Http::response(['id' => 'loft_studio'])
                : Http::response(['error' => ['message' => 'No such product']], 404),
            $method === 'POST' && $path === 'products' => Http::response(['id' => $this->productExists = 'loft_studio']),
            $method === 'POST' && $path === 'prices' => Http::response(['id' => $this->prices[$request['lookup_key']] = 'price_'.(count($this->prices) + 1)]),
            $method === 'POST' && $path === 'checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/cs_1']),
            $method === 'GET' && str_starts_with($path, 'checkout/sessions/') => Http::response($this->sessions[$id]),
            $method === 'GET' && str_starts_with($path, 'invoices/') => Http::response($this->invoices[$id]),
            $method === 'DELETE' && str_starts_with($path, 'subscriptions/') => Http::response($this->sub($id, 'canceled', ['ended_at' => now()->getTimestamp()])),
            $method === 'GET' && str_starts_with($path, 'subscriptions/') => isset($this->subs[$id])
                ? Http::response($this->subs[$id])
                : Http::response(['error' => ['message' => 'No such subscription']], 404),
            $method === 'POST' && $path === 'billing_portal/configurations' => Http::response(['id' => 'bpc_1']),
            $method === 'POST' && $path === 'billing_portal/sessions' => Http::response(['url' => 'https://billing.stripe.com/p/session_1']),
            default => Http::response(['error' => ['message' => "Unexpected {$method} {$path}"]], 500),
        };
    }

    /** A subscription as the fake API serves it. */
    private function sub(string $id, string $status, array $attributes = []): array
    {
        return $this->subs[$id] = $attributes + [
            'id' => $id, 'object' => 'subscription', 'customer' => 'cus_loft_1', 'status' => $status,
            'current_period_end' => now()->addMonth()->getTimestamp(), 'cancel_at_period_end' => false, 'cancel_at' => null, 'ended_at' => null,
        ];
    }

    /** A studio that pays for Loft (not comped), with its trial ending $trialDays from now. */
    private function payingStudio(int|float $trialDays = 30, array $attributes = []): Studio
    {
        return $this->studio($attributes + ['comped_at' => null, 'trial_ends_at' => now()->addDays($trialDays)]);
    }

    /** A studio subscribed through Stripe: customer cus_loft_1, subscription sub_1. */
    private function subscribedStudio(string $status = 'active'): Studio
    {
        $studio = $this->payingStudio(-60);
        app(StudioBilling::class)->sync($studio->forceFill(['billing_customer_id' => 'cus_loft_1']), $this->sub('sub_1', $status));

        return $studio->refresh();
    }

    private function billingWebhook(array $event, string $secret = 'whsec_billing'): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return $this->call('POST', '/stripe/billing-webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function event(string $type, string $objectId, ?string $id = null): array
    {
        return ['id' => $id ?? 'evt_'.Str::random(10), 'type' => $type, 'data' => ['object' => ['id' => $objectId, 'object' => 'thing']]];
    }

    private function state(Studio $studio): BillingState
    {
        return $studio->refresh()->billing();
    }

    /** @return list<Request> */
    private function sent(string $method, string $path): array
    {
        return Http::recorded(fn (Request $r) => $r->method() === $method && str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/v1/'.$path))
            ->map(fn ($pair) => $pair[0])->values()->all();
    }

    public function test_a_new_studio_starts_a_free_trial_with_no_card(): void
    {
        $this->freezeSecond();
        $this->get('/signup')->assertOk()->assertSee('Free for 30 days, no card needed. Then $49 a month');

        $this->post('/signup', [
            'studio_name' => 'Pilates Loft', 'slug' => 'pilates', 'timezone' => 'America/Denver', 'currency' => 'usd',
            'first_name' => 'Ola', 'last_name' => 'Owner', 'email' => 'ola@example.com', 'password' => 'secret-pass', 'terms' => '1',
        ])->assertRedirect('/pilates/admin');

        $studio = Studio::where('slug', 'pilates')->sole();
        $this->assertTrue($studio->trial_ends_at->eq(now()->addDays(30)));
        $state = $studio->billing();
        $this->assertSame(BillingState::TRIAL, $state->status);
        $this->assertSame(30, $state->trialDaysLeft());
        Http::assertNothingSent(); // no Stripe customer until they subscribe

        $this->get('/pilates/admin')->assertOk()->assertSee('Free trial: 30 days left.')->assertSee('Subscribe');
        $this->get('/pilates/admin/subscription')->assertOk()
            ->assertSee('Your trial ends on')
            ->assertSee('Subscribe for $49 a month')
            ->assertSee('No Loft fees on your members’ payments.');
    }

    public function test_studios_already_on_loft_are_comped(): void
    {
        $studio = $this->payingStudio();
        $migration = require database_path('migrations/2026_11_24_000001_studio_subscriptions.php');
        $migration->down();
        $migration->up();

        $studio->refresh();
        $this->assertNotNull($studio->comped_at);
        $this->assertSame('Already on Loft when subscriptions began', $studio->comp_note);
        $this->assertSame(BillingState::COMPED, $studio->billing()->status);

        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner)->get("/{$studio->slug}/admin")->assertOk()->assertDontSee('Free trial');
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('Loft is free for')->assertDontSee('Subscribe for');
        $this->post("/{$studio->slug}/admin/subscription/checkout")->assertSessionHas('error', 'Loft is free for '.$studio->name.'. There’s nothing to pay.');
        Http::assertNothingSent();
    }

    public function test_the_pricing_page_follows_the_configured_price(): void
    {
        $this->get('/')->assertOk()->assertSee('Free for 30 days')->assertSee('No card needed')->assertSee('/pricing');
        $this->get('/pricing')->assertOk()
            ->assertSee('$49')
            ->assertSee('30-day free trial')
            ->assertSee('everything keeps working for 7 more days', false)
            ->assertSee('No. Members pay you directly')
            ->assertSee('up to 10 hours of stored video');

        config(['loft.billing.monthly_cents' => 5950, 'loft.billing.trial_days' => 14, 'loft.stripe.platform_fee_percent' => 1.5]);
        $this->get('/pricing')->assertOk()->assertSee('$59.50')->assertSee('14-day free trial')->assertSee('Loft adds 1.5% to online sales.');
    }

    public function test_when_the_trial_runs_out_the_studio_has_a_week_then_pauses(): void
    {
        $studio = $this->payingStudio(2, ['stripe_account_id' => 'acct_S', 'stripe_charges_enabled' => true]);
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio, 'member');
        $attendee = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $this->credits($studio, $member, 5);
        $program = $this->program($studio);
        $plan = $this->plan($studio);
        $trialEnds = $studio->trial_ends_at->copy();

        // Grace: everything still works, with a warning for staff.
        $this->travelTo($trialEnds->copy()->addDay());
        $state = $this->state($studio);
        $this->assertSame([BillingState::GRACE, 'trial_ended'], [$state->status, $state->reason]);
        $this->assertTrue($state->pausesAt->eq($trialEnds->copy()->addDays(7)));
        $this->actingAs($owner)->get("/{$studio->slug}/admin")->assertOk()
            ->assertSee('Your free trial has ended.')
            ->assertSee('pause on '.$studio->local($state->pausesAt)->format('F j'));
        $session = $this->classSession($program, ['starts_at' => now()->addDays(10)]);
        $this->actingAs($member)->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id])->assertSessionHas('success');

        // Paused: staff see only the subscription page; members can't book or buy online.
        $this->travelTo($trialEnds->copy()->addDays(7)->addMinute());
        $this->assertTrue($studio->refresh()->isPaused());
        $this->actingAs($owner)->get("/{$studio->slug}/admin")->assertRedirect("/{$studio->slug}/admin/subscription");
        $this->get("/{$studio->slug}/admin/programs")->assertRedirect("/{$studio->slug}/admin/subscription");
        $this->post("/{$studio->slug}/admin/sessions/{$session->id}/cancel")->assertRedirect("/{$studio->slug}/admin/subscription");
        $this->assertSame('scheduled', $session->refresh()->status);
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()
            ->assertSee($studio->name.' is paused')
            ->assertSee('Nothing has been deleted')
            ->assertDontSee('Programs</a>', false);

        $later = $this->classSession($program, ['starts_at' => now()->addDays(3)]);
        $this->actingAs($member)->get("/{$studio->slug}")->assertOk()->assertSee('isn’t taking online bookings or payments right now')->assertSee('Booking paused')->assertDontSee('>Book</a>', false);
        $this->get("/{$studio->slug}/book/{$later->id}")->assertOk()->assertSee('isn’t taking online bookings right now');
        $this->post("/{$studio->slug}/book/{$later->id}", ['attendee_id' => $attendee->id])
            ->assertSessionHas('error', $studio->name.' isn’t taking online bookings right now. Please contact the studio.');
        $this->assertFalse(Booking::where('class_session_id', $later->id)->exists());
        $this->post("/{$studio->slug}/checkout/{$plan->id}")
            ->assertSessionHas('error', $studio->name.' isn’t taking online payments right now. Please contact the studio.');

        try {
            app(Shop::class)->checkout($studio, $member, [], 'https://example.com/ok', 'https://example.com/back');
            $this->fail('The shop took a paused studio’s checkout.');
        } catch (CheckoutException $e) {
            $this->assertSame($studio->name.' isn’t taking online payments right now. Please contact the studio.', $e->getMessage());
        }

        $token = $member->createToken('phone', ['member'])->plainTextToken;
        $this->withToken($token)->postJson("/api/v1/studios/{$studio->slug}/sessions/{$later->id}/bookings", ['attendee_id' => $attendee->id], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'studio_paused');

        // Members can still cancel what they booked before.
        $booking = Booking::where('class_session_id', $session->id)->sole();
        $this->actingAs($member)->post("/{$studio->slug}/bookings/{$booking->id}/cancel")->assertSessionHas('success');
        Http::assertNothingSent();
    }

    public function test_a_paused_studio_sends_no_automations(): void
    {
        $studio = $this->payingStudio(-30, ['timezone' => 'America/Denver']);
        $studio->automations()->create(['trigger' => 'birthday', 'active' => true] + Automation::defaults('birthday'));
        $parent = $this->userIn($studio);
        app(Consent::class)->setMarketingEmail($studio->studioUsers()->where('user_id', $parent->id)->sole(), true, 'join');
        $this->travelTo($studio->localNow()->setTime(11, 0));
        $this->attendee($studio, $parent, ['first_name' => 'Maya', 'dob' => now()->subYears(8)->toDateString()]);

        $studio->automations()->create(['trigger' => 'first_class', 'active' => true] + Automation::defaults('first_class'));
        $attended = new DomainEvent(['type' => 'booking.attended', 'studio_id' => $studio->id, 'user_id' => $parent->id, 'payload' => ['first_visit' => true, 'program_title' => 'Silks']]);
        Bus::fake([SendAutomatedMessage::class]);

        $this->assertTrue($studio->isPaused());
        $this->assertSame(0, app(Automations::class)->runScheduled($studio));
        app(Automations::class)->onEvent($attended);
        Bus::assertNotDispatched(SendAutomatedMessage::class);

        app(StudioBilling::class)->comp($studio);
        $this->assertSame(1, app(Automations::class)->runScheduled($studio->refresh()));
        app(Automations::class)->onEvent($attended);
        Bus::assertDispatched(SendAutomatedMessage::class);
    }

    public function test_subscribing_in_the_trial_keeps_the_rest_of_it(): void
    {
        $this->freezeSecond();
        $studio = $this->payingStudio(20);
        $owner = $this->userIn($studio, 'owner', ['email' => 'owner@example.com']);
        $admin = $this->userIn($studio, 'admin');

        $this->actingAs($admin)->post("/{$studio->slug}/admin/subscription/checkout")->assertForbidden();
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('Only the studio’s owner can subscribe: '.$owner->name);

        $this->actingAs($owner)->post("/{$studio->slug}/admin/subscription/checkout")->assertRedirect('https://checkout.stripe.com/c/cs_1');

        $customer = $this->sent('POST', 'customers')[0];
        $this->assertSame([], $customer->header('Stripe-Account'), 'on Loft’s own account');
        $this->assertSame(['owner@example.com', $studio->id], [$customer['email'], $customer['metadata']['loft_studio_id']]);
        $this->assertSame('loft_studio_monthly_usd_4900', $this->sent('GET', 'prices')[0]['lookup_keys'][0]);
        $this->assertSame('loft_studio', $this->sent('POST', 'products')[0]['id']);
        $price = $this->sent('POST', 'prices')[0];
        $this->assertSame([4900, 'usd', 'month', 'loft_studio_monthly_usd_4900'], [$price['unit_amount'], $price['currency'], $price['recurring']['interval'], $price['lookup_key']]);
        $checkout = $this->sent('POST', 'checkout/sessions')[0];
        $this->assertSame([], $checkout->header('Stripe-Account'));
        $this->assertSame('subscription', $checkout['mode']);
        $this->assertSame('cus_loft_1', $checkout['customer']);
        $this->assertSame('price_1', $checkout['line_items'][0]['price']);
        $this->assertSame($studio->trial_ends_at->getTimestamp(), $checkout['subscription_data']['trial_end']);
        $this->assertSame('true', $checkout['allow_promotion_codes']);
        $this->assertSame((string) $studio->id, $checkout['client_reference_id']);
        $this->assertStringEndsWith('/admin/subscription/complete?session_id={CHECKOUT_SESSION_ID}', $checkout['success_url']);
        $this->assertArrayNotHasKey('automatic_tax', $checkout->data());

        // Coming back to it later reuses the customer and the price.
        $this->post("/{$studio->slug}/admin/subscription/checkout")->assertRedirect('https://checkout.stripe.com/c/cs_1');
        $this->assertCount(1, $this->sent('POST', 'customers'));
        $this->assertCount(1, $this->sent('GET', 'prices'));
        $this->assertCount(2, $this->sent('POST', 'checkout/sessions'));

        $this->sessions['cs_1'] = ['id' => 'cs_1', 'customer' => 'cus_loft_1', 'client_reference_id' => (string) $studio->id,
            'subscription' => $this->sub('sub_1', 'trialing', ['current_period_end' => $studio->trial_ends_at->getTimestamp()])];
        $this->get("/{$studio->slug}/admin/subscription/complete?session_id=cs_1")
            ->assertRedirect("/{$studio->slug}/admin/subscription")
            ->assertSessionHas('success', 'Thanks for subscribing! Your card is first charged on '.$studio->local($studio->trial_ends_at)->format('F j').', when your trial ends.');

        $state = $this->state($studio);
        $this->assertSame(BillingState::SUBSCRIBED, $state->status);
        $this->assertTrue($state->inStripeTrial);
        $this->assertSame(['sub_1', 'trialing'], [$studio->billing_subscription_id, $studio->billing_status]);
        $this->get("/{$studio->slug}/admin")->assertOk()->assertDontSee('Free trial');
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('Your free trial runs until')->assertSee('Manage billing');
        $this->post("/{$studio->slug}/admin/subscription/checkout")->assertSessionHas('error', $studio->name.' already has a subscription.');
    }

    public function test_after_the_trial_or_near_its_end_the_first_charge_is_now(): void
    {
        $studio = $this->payingStudio(1.5);
        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner)->post("/{$studio->slug}/admin/subscription/checkout")->assertRedirect('https://checkout.stripe.com/c/cs_1');
        $this->assertArrayNotHasKey('trial_end', $this->sent('POST', 'checkout/sessions')[0]['subscription_data']);

        $this->travelTo(now()->addDays(20)); // paused: subscribing is the one thing left to do
        $this->assertTrue($studio->refresh()->isPaused());
        $this->post("/{$studio->slug}/admin/subscription/checkout")->assertRedirect('https://checkout.stripe.com/c/cs_1');
        $this->assertArrayNotHasKey('trial_end', $this->sent('POST', 'checkout/sessions')[1]['subscription_data']);

        $this->sessions['cs_1'] = ['id' => 'cs_1', 'customer' => 'cus_loft_1', 'client_reference_id' => (string) $studio->id, 'subscription' => $this->sub('sub_1', 'active')];
        $this->get("/{$studio->slug}/admin/subscription/complete?session_id=cs_1")->assertSessionHas('success', 'Thanks for subscribing! Everything’s running.');
        $this->assertFalse($studio->refresh()->isPaused());
        $this->get("/{$studio->slug}/admin")->assertOk();
    }

    public function test_automatic_tax_and_a_new_price_follow_config(): void
    {
        config(['loft.billing.automatic_tax' => true, 'loft.billing.monthly_cents' => 5900]);
        $this->productExists = true;
        $studio = $this->payingStudio();
        $this->actingAs($this->userIn($studio, 'owner'))->post("/{$studio->slug}/admin/subscription/checkout");

        $this->assertSame('loft_studio_monthly_usd_5900', $this->sent('POST', 'prices')[0]['lookup_key']);
        $this->assertSame([], $this->sent('POST', 'products'), 'the product is reused');
        $checkout = $this->sent('POST', 'checkout/sessions')[0];
        $this->assertSame(['true', 'required', 'auto'], [$checkout['automatic_tax']['enabled'], $checkout['billing_address_collection'], $checkout['customer_update']['address']]);
    }

    public function test_coming_back_from_someone_elses_checkout_changes_nothing(): void
    {
        $studio = $this->payingStudio(10, ['billing_customer_id' => 'cus_loft_1']);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->sessions['cs_x'] = ['id' => 'cs_x', 'customer' => 'cus_other', 'client_reference_id' => (string) $studio->id, 'subscription' => $this->sub('sub_x', 'active', ['customer' => 'cus_other'])];
        $this->sessions['cs_y'] = ['id' => 'cs_y', 'customer' => 'cus_loft_1', 'client_reference_id' => '999', 'subscription' => $this->sub('sub_y', 'active')];
        foreach (['cs_x', 'cs_y'] as $id) {
            $this->get("/{$studio->slug}/admin/subscription/complete?session_id={$id}")
                ->assertSessionHas('warning', 'We couldn’t confirm your subscription yet. It should show here within a few minutes.');
        }
        $this->get("/{$studio->slug}/admin/subscription/complete")->assertSessionHas('warning');
        $this->assertNull($studio->refresh()->billing_subscription_id);
        $this->assertSame(BillingState::TRIAL, $studio->billing()->status);
    }

    public function test_the_billing_webhook_keeps_the_studio_in_step_with_stripe(): void
    {
        $studio = $this->subscribedStudio();
        $owner = $this->userIn($studio, 'owner');
        $this->assertSame(BillingState::SUBSCRIBED, $this->state($studio)->status);

        // A renewal fails: a week's grace from the first failure.
        $this->sub('sub_1', 'past_due');
        $this->billingWebhook($this->event('customer.subscription.updated', 'sub_1'))->assertOk();
        $state = $this->state($studio);
        $this->assertSame([BillingState::GRACE, 'payment_failed'], [$state->status, $state->reason]);
        $this->assertTrue($state->pausesAt->eq($studio->billing_payment_failed_at->copy()->addDays(7)));
        $this->actingAs($owner)->get("/{$studio->slug}/admin")->assertOk()->assertSee('Your last Loft payment didn’t go through.')->assertSee('Update your card');
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('Your last payment didn’t go through')->assertSee('Update your card');

        $this->post("/{$studio->slug}/admin/subscription/checkout")->assertSessionHas('error', 'Update the card in the billing portal instead.');

        $this->travelTo(now()->addDays(2));
        $this->invoices['in_1'] = ['id' => 'in_1', 'subscription' => 'sub_1', 'customer' => 'cus_loft_1'];
        $this->invoices['in_2'] = ['id' => 'in_2', 'customer' => 'cus_loft_1', 'parent' => ['subscription_details' => ['subscription' => 'sub_1']]]; // newer API versions
        $this->billingWebhook($this->event('invoice.payment_failed', 'in_2'))->assertOk();
        $this->assertSame('evt', substr((string) StripeEvent::latest('id')->first()->event_id, 0, 3));
        $this->assertSame($studio->id, StripeEvent::latest('id')->first()->studio_id);
        $this->billingWebhook($this->event('invoice.payment_failed', 'in_1'))->assertOk();
        $this->assertTrue($this->state($studio)->pausesAt->eq($state->pausesAt), 'grace runs from the first failure');

        $this->travelTo(now()->addDays(6));
        $this->assertTrue($studio->refresh()->isPaused());
        $this->post("/{$studio->slug}/admin/subscription/portal")->assertRedirect('https://billing.stripe.com/p/session_1');

        // The new card works.
        $this->sub('sub_1', 'active');
        $this->billingWebhook($this->event('invoice.paid', 'in_1'))->assertOk();
        $this->assertSame(BillingState::SUBSCRIBED, $this->state($studio)->status);
        $this->assertNull($studio->billing_payment_failed_at);

        // Cancelled at the end of the month, then over: a week's grace from the end.
        $end = now()->addDays(10);
        $this->sub('sub_1', 'active', ['cancel_at_period_end' => true, 'current_period_end' => $end->getTimestamp()]);
        $this->billingWebhook($this->event('customer.subscription.updated', 'sub_1'))->assertOk();
        $this->assertSame($end->getTimestamp(), $this->state($studio)->cancelsAt->getTimestamp());
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('Your subscription ends on');

        $this->travelTo($end);
        $this->sub('sub_1', 'canceled', ['ended_at' => $end->getTimestamp(), 'current_period_end' => $end->getTimestamp()]);
        $this->billingWebhook($this->event('customer.subscription.deleted', 'sub_1', 'evt_deleted'))->assertOk();
        $state = $this->state($studio);
        $this->assertSame([BillingState::GRACE, 'cancelled'], [$state->status, $state->reason]);
        $this->assertSame($end->copy()->addDays(7)->getTimestamp(), $state->pausesAt->getTimestamp());
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('Your Loft subscription has ended.');
        $this->assertSame(['cus_loft_1', 'canceled'], [$studio->billing_customer_id, $studio->billing_status]);

        // Each event once; signed by this endpoint's secret only; other customers ignored.
        $this->billingWebhook($this->event('customer.subscription.deleted', 'sub_1', 'evt_deleted'))->assertOk()->assertJson(['duplicate' => true]);
        $this->billingWebhook($this->event('customer.subscription.updated', 'sub_1'), 'whsec_test')->assertStatus(400);
        $this->sub('sub_other', 'active', ['customer' => 'cus_stranger']);
        $this->billingWebhook($this->event('customer.subscription.created', 'sub_other'))->assertOk();
        $this->assertSame('sub_1', $studio->refresh()->billing_subscription_id);
        $this->billingWebhook($this->event('checkout.session.completed', 'cs_shop'))->assertOk(); // not a subscription checkout
    }

    public function test_a_checkout_completed_webhook_can_arrive_before_the_owner_is_back(): void
    {
        $studio = $this->payingStudio(10, ['billing_customer_id' => 'cus_loft_1']);
        $this->sessions['cs_1'] = ['id' => 'cs_1', 'mode' => 'subscription', 'customer' => 'cus_loft_1', 'client_reference_id' => (string) $studio->id,
            'subscription' => $this->sub('sub_1', 'trialing', ['current_period_end' => $studio->trial_ends_at->getTimestamp()])];
        $this->billingWebhook(['id' => 'evt_cs', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'mode' => 'subscription']]])->assertOk();

        $this->assertSame(['sub_1', 'trialing'], [$studio->refresh()->billing_subscription_id, $studio->billing_status]);
        $this->assertSame(BillingState::SUBSCRIBED, $studio->billing()->status);
    }

    public function test_a_second_subscription_does_not_replace_a_live_one(): void
    {
        $studio = $this->subscribedStudio();
        $this->sub('sub_2', 'active');
        $this->billingWebhook($this->event('customer.subscription.created', 'sub_2'))->assertOk();
        $this->assertSame('sub_1', $studio->refresh()->billing_subscription_id);

        // Unless the first one has in fact ended (its webhook went missing).
        $this->sub('sub_1', 'canceled', ['ended_at' => now()->getTimestamp()]);
        $this->billingWebhook($this->event('customer.subscription.updated', 'sub_2'))->assertOk();
        $this->assertSame(['sub_2', 'active'], [$studio->refresh()->billing_subscription_id, $studio->billing_status]);
    }

    public function test_the_billing_portal_is_set_up_once(): void
    {
        $studio = $this->subscribedStudio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->post("/{$studio->slug}/admin/subscription/portal")->assertRedirect('https://billing.stripe.com/p/session_1');
        $this->post("/{$studio->slug}/admin/subscription/portal")->assertRedirect('https://billing.stripe.com/p/session_1');

        $this->assertCount(1, $this->sent('POST', 'billing_portal/configurations'));
        $config = $this->sent('POST', 'billing_portal/configurations')[0];
        $this->assertSame(['true', 'at_period_end'], [$config['features']['subscription_cancel']['enabled'], $config['features']['subscription_cancel']['mode']]);
        $session = $this->sent('POST', 'billing_portal/sessions')[1];
        $this->assertSame(['cus_loft_1', 'bpc_1'], [$session['customer'], $session['configuration']]);
        $this->assertStringEndsWith("/{$studio->slug}/admin/subscription", $session['return_url']);
        $this->assertSame([], $session->header('Stripe-Account'));

        $this->actingAs($this->userIn($studio, 'admin'))->post("/{$studio->slug}/admin/subscription/portal")->assertForbidden();
    }

    public function test_owners_are_reminded_before_the_trial_ends_when_it_ends_and_when_the_studio_pauses(): void
    {
        $studio = $this->payingStudio(5, ['name' => 'Skyline']);
        $owner = $this->userIn($studio, 'owner');
        $this->userIn($studio, 'admin');
        $this->userIn($this->subscribedStudio(), 'owner'); // nothing for studios that pay…
        $this->userIn($this->studio(), 'owner'); // …or are comped
        $this->userIn($this->payingStudio(-30), 'owner'); // …or paused long ago
        $trialEnds = $studio->trial_ends_at->copy();
        $notices = fn (string $kind) => Mail::queued(LoftNotice::class, fn (LoftNotice $m) => $m->kind === $kind);

        $this->artisan('loft:housekeeping')->assertSuccessful();
        Mail::assertNothingQueued();

        $this->travelTo($trialEnds->copy()->subDays(2));
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $mail = $notices('billing_trial_ending')->sole();
        $this->assertTrue($mail->hasTo($owner->email));
        $this->assertSame('Your Loft trial ends '.$studio->local($trialEnds)->format('F j'), $mail->envelope()->subject);
        $this->assertSame('Loft', $mail->envelope()->from->name);
        $this->assertStringContainsString("/{$studio->slug}/admin/subscription", $mail->actionUrl);
        $this->assertStringContainsString('You’re getting this because you own Skyline on Loft.', $mail->render());

        $this->travelTo($trialEnds->copy()->addHour());
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame('Your Loft trial has ended', $notices('billing_grace')->sole()->headline);
        $this->assertStringContainsString('keep working until '.$studio->local($trialEnds->copy()->addDays(7))->format('l, F j'), $notices('billing_grace')->sole()->lines[1]);

        $this->travelTo($trialEnds->copy()->addDays(7)->addHour());
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame('Skyline is paused on Loft', $notices('billing_paused')->sole()->headline);
        $this->assertCount(3, Mail::queued(LoftNotice::class));

        $this->travelTo(now()->addDays(10)); // no more for a studio that stays paused
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertCount(3, Mail::queued(LoftNotice::class));
    }

    public function test_a_failed_payment_emails_the_owner_once(): void
    {
        $studio = $this->subscribedStudio('past_due');
        $owner = $this->userIn($studio, 'owner');
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();

        $mail = Mail::queued(LoftNotice::class)->sole();
        $this->assertTrue($mail->hasTo($owner->email));
        $this->assertSame(['Your Loft payment didn’t go through', 'Update your card'], [$mail->headline, $mail->actionLabel]);
    }

    public function test_the_hourly_sweep_catches_what_webhooks_missed(): void
    {
        $studio = $this->subscribedStudio();
        $this->travelTo(now()->addMonth()->addHours(2)); // the renewal's webhook never came
        $this->sub('sub_1', 'active', ['current_period_end' => now()->addMonth()->getTimestamp()]);
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertTrue($studio->refresh()->billing_period_ends_at->isFuture());

        $this->sub('sub_1', 'past_due');
        $this->billingWebhook($this->event('customer.subscription.updated', 'sub_1'))->assertOk();
        $this->sub('sub_1', 'canceled', ['ended_at' => now()->getTimestamp()]); // Stripe gave up; that webhook never came either
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame([BillingState::GRACE, 'cancelled'], [$this->state($studio)->status, $this->state($studio)->reason]);
        $this->assertNull($studio->billing_payment_failed_at);

        // Nothing to ask Stripe about: no calls.
        $before = count(Http::recorded());
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertCount($before, Http::recorded());
    }

    public function test_closing_a_studio_ends_its_loft_subscription(): void
    {
        $studio = $this->subscribedStudio();
        $owner = $this->userIn($studio, 'owner');
        $owner->forceFill(['password' => 'password'])->save();

        $this->actingAs($owner)->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => 'close']])->assertRedirect();
        $this->assertNull(Studio::find($studio->id));
        $cancel = $this->sent('DELETE', 'subscriptions/sub_1');
        $this->assertCount(1, $cancel);
        $this->assertSame([], $cancel[0]->header('Stripe-Account'));
    }

    public function test_the_loft_billing_command(): void
    {
        $this->freezeSecond();
        $studio = $this->payingStudio(30, ['slug' => 'skyline', 'name' => 'Skyline']);
        $ends = fn () => $studio->local($studio->refresh()->trial_ends_at)->format('M j, Y');

        $this->artisan('loft:billing skyline')->expectsOutput('Skyline (skyline): in the free trial until '.$ends().'.')->assertSuccessful();
        $this->artisan('loft:billing skyline --trial-days=45')->assertSuccessful();
        $this->assertTrue($studio->refresh()->trial_ends_at->eq(now()->addDays(45)));

        $this->artisan('loft:billing skyline --comp --note=Pilot')
            ->expectsOutput('Skyline is free from now on.')
            ->expectsOutput('Skyline (skyline): comped (Pilot).')
            ->assertSuccessful();
        $this->assertSame(BillingState::COMPED, $this->state($studio)->status);

        $this->artisan('loft:billing skyline --end-comp --trial-days=0')->assertSuccessful();
        $this->assertSame([BillingState::GRACE, 'trial_ended'], [$this->state($studio)->status, $this->state($studio)->reason]);
        $this->assertNull($studio->comp_note);

        $this->artisan('loft:billing skyline --comp')->assertSuccessful();
        $this->artisan('loft:billing skyline --end-comp')->assertSuccessful();
        $this->assertSame(30, $this->state($studio)->trialDaysLeft(), 'a fresh trial');

        $this->artisan('loft:billing nowhere')->expectsOutput('No studio at nowhere.')->assertFailed();
        $this->artisan('loft:billing skyline --trial-days=soon')->assertFailed();
        $this->artisan('loft:billing skyline --comp --end-comp')->assertFailed();

        $subscribed = $this->subscribedStudio();
        $this->artisan("loft:billing {$subscribed->slug} --comp")
            ->expectsOutputToContain('It also has a live subscription (sub_1)')
            ->assertSuccessful();
    }

    public function test_states_at_their_edges(): void
    {
        $billing = app(StudioBilling::class);
        $studio = $this->payingStudio(0);
        $at = fn (Carbon $now) => $billing->state($studio, $now);
        $ends = $studio->trial_ends_at->copy();

        $this->assertSame(BillingState::TRIAL, $at($ends->copy()->subSecond())->status);
        $this->assertSame(0, $at($ends->copy()->subHour())->trialDaysLeft($ends->copy()->subHour()));
        $this->assertSame(BillingState::GRACE, $at($ends)->status);
        $this->assertSame(BillingState::GRACE, $at($ends->copy()->addDays(7)->subSecond())->status);
        $this->assertSame(BillingState::PAUSED, $at($ends->copy()->addDays(7))->status);

        // Studios from before trials were recorded count from when they were created.
        $old = $this->payingStudio(0, ['trial_ends_at' => null]);
        $this->assertTrue($billing->trialEndsAt($old)->eq($old->created_at->copy()->addDays(30)));

        // A subscription that's incomplete (its first payment needs the member's bank) isn't running.
        $studio->forceFill(['billing_customer_id' => 'cus_loft_1'])->save();
        $billing->sync($studio, $this->sub('sub_9', 'incomplete'));
        $this->assertSame(BillingState::PAUSED, $at($ends->copy()->addDays(8))->status);
        $this->assertNull($studio->billing_ended_at);
        $this->assertTrue($at($ends)->canSubscribe());
    }
}
