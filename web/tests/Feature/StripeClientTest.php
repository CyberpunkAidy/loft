<?php

namespace Tests\Feature;

use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StripeClientTest extends TestCase
{
    public function test_checkout_runs_on_the_studio_account_with_booking_metadata(): void
    {
        config(['loft.stripe.platform_fee_percent' => 2.5]);
        $this->fakeStripe([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_new']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'price_cents' => 10000, 'stripe_price_id' => 'price_1']);

        $cs = app(StripeClient::class)->createCheckout($studio, $user, $plan, 'https://x.test/done?session_id={CHECKOUT_SESSION_ID}', 'https://x.test/back', ['session_id' => 7, 'attendee_id' => 9, 'apparatus_id' => 3]);

        $this->assertSame('cs_1', $cs['id']);
        $this->assertSame('cus_new', $user->fresh()->membershipIn($studio)->stripe_customer_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions')
            && $r->header('Stripe-Account') === ['acct_A']
            && $r->header('Stripe-Version') === ['2024-06-20']
            && $r['mode'] === 'payment'
            && $r['customer'] === 'cus_new'
            && $r['success_url'] === 'https://x.test/done?session_id={CHECKOUT_SESSION_ID}'
            && $r['metadata']['session_id'] == 7 && $r['metadata']['attendee_id'] == 9 && $r['metadata']['apparatus_id'] == 3 && $r['metadata']['loft_user_id'] == $user->id
            && $r['payment_intent_data']['application_fee_amount'] == 250
            && $r['allow_promotion_codes'] === 'true');
    }

    public function test_season_checkout_adds_the_registration_fee_but_not_cancel_at(): void
    {
        $this->fakeStripe(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_2', 'url' => 'https://checkout.stripe.com/x'])]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $studio->studioUsers()->where('user_id', $user->id)->update(['stripe_customer_id' => 'cus_existing']);
        $season = $studio->seasons()->create(['name' => 'S', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()]);
        $plan = $this->plan($studio, ['kind' => 'season', 'season_id' => $season->id, 'stripe_price_id' => 'price_m', 'stripe_fee_price_id' => 'price_fee']);

        app(StripeClient::class)->createCheckout($studio, $user, $plan, 'https://x.test/ok', 'https://x.test/no');

        Http::assertSent(fn (Request $r) => $r['mode'] === 'subscription'
            && $r['customer'] === 'cus_existing'
            && $r['line_items'][1]['price'] === 'price_fee'
            && ! isset($r['subscription_data']['cancel_at']) // Checkout doesn't accept it
            && $r['subscription_data']['metadata']['loft_plan_id'] == $plan->id);
    }

    public function test_sync_plan_creates_product_and_prices(): void
    {
        $this->fakeStripe([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices' => Http::sequence()->push(['id' => 'price_monthly'])->push(['id' => 'price_fee']),
        ]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $season = $studio->seasons()->create(['name' => 'S', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonths(4)->toDateString()]);
        $plan = $this->plan($studio, ['kind' => 'season', 'season_id' => $season->id, 'price_cents' => 11500, 'registration_fee_cents' => 5000]);

        $fields = app(StripeClient::class)->syncPlan($studio, $plan);

        $this->assertSame(['stripe_product_id' => 'prod_1', 'stripe_price_id' => 'price_monthly', 'stripe_fee_price_id' => 'price_fee'], $fields);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/prices') && $r['unit_amount'] == 11500 && $r['recurring']['interval'] === 'month');
    }

    public function test_errors_surface_stripes_message(): void
    {
        $this->fakeStripe(['*' => Http::response(['error' => ['message' => 'No such customer', 'code' => 'resource_missing']], 404)]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A']);

        try {
            app(StripeClient::class)->retrieveCheckoutSession($studio, 'cs_nope');
            $this->fail('expected an exception');
        } catch (StripeException $e) {
            $this->assertSame('No such customer', $e->getMessage());
            $this->assertSame('resource_missing', $e->stripeCode);
        }
    }

    public function test_signature_verification(): void
    {
        $payload = '{"id":"evt_1"}';
        $t = time();
        $good = hash_hmac('sha256', "{$t}.{$payload}", 'whsec');

        $this->assertTrue(StripeClient::verifySignature($payload, "t={$t},v1=bad,v1={$good}", 'whsec'));
        $this->assertFalse(StripeClient::verifySignature($payload, "t={$t},v1={$good}", 'other'));
        $old = $t - 600;
        $this->assertFalse(StripeClient::verifySignature($payload, "t={$old},v1=".hash_hmac('sha256', "{$old}.{$payload}", 'whsec'), 'whsec'));
        $this->assertFalse(StripeClient::verifySignature($payload, 'garbage', 'whsec'));
    }
}
