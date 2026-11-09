<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\ProductVariant;
use App\Models\Studio;
use App\Models\User;
use App\Services\Shop;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The online shop (roadmap step 6, second part), against a fake Stripe that keeps its Checkout
 * Sessions: open when created, complete or expired as the test says, expirable only while open.
 */
class ShopTest extends TestCase
{
    /** @var array<string, array<string, mixed>> Checkout Sessions by ID */
    private array $sessions = [];

    /** @var list<array<string, mixed>> the parameters each Checkout Session was created with */
    private array $created = [];

    private int $taxRates = 0;

    private bool $refuseCheckout = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');

            return match (true) {
                $path === 'customers' => Http::response(['id' => 'cus_'.Str::random(8)]),
                $path === 'tax_rates' => Http::response(['id' => 'txr_'.++$this->taxRates]),
                $path === 'checkout/sessions' => $this->createSession($request),
                str_ends_with($path, '/expire') => $this->expireSession(Str::between($path, 'checkout/sessions/', '/expire')),
                isset($this->sessions[Str::after($path, 'checkout/sessions/')]) => $this->showSession(Str::after($path, 'checkout/sessions/'), $request),
                default => Http::response(['error' => ['message' => "No such object: {$path}"]], 404),
            };
        }]);
    }

    private function createSession(Request $request)
    {
        if ($this->refuseCheckout) {
            return Http::response(['error' => ['message' => 'Your account cannot currently make live charges.']], 400);
        }
        $id = 'cs_'.(count($this->created) + 1);
        $this->created[] = $request->data();
        $this->sessions[$id] = ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
            'url' => "https://checkout.stripe.com/c/pay/{$id}", 'expires_at' => (int) $request['expires_at'], 'metadata' => $request['metadata'], 'payment_intent' => null];

        return Http::response($this->sessions[$id]);
    }

    private function expireSession(string $id)
    {
        if (($this->sessions[$id]['status'] ?? null) !== 'open') {
            return Http::response(['error' => ['message' => 'Only Checkout Sessions with a status of open can be expired.']], 400);
        }
        $this->sessions[$id]['status'] = 'expired';

        return Http::response($this->sessions[$id]);
    }

    /** The payment intent is an ID unless the request expands it. */
    private function showSession(string $id, Request $request)
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $cs = $this->sessions[$id];
        if (is_array($cs['payment_intent']) && ! in_array('payment_intent', (array) ($query['expand'] ?? []), true)) {
            $cs['payment_intent'] = $cs['payment_intent']['id'];
        }

        return Http::response($cs);
    }

    /** The member finishes Checkout. A bank debit completes the session unpaid, until it clears. */
    private function pay(string $id, string $paymentStatus = 'paid', string $intentStatus = 'succeeded'): void
    {
        $order = Order::findOrFail($this->sessions[$id]['metadata']['loft_order_id']);
        $this->sessions[$id] = ['status' => 'complete', 'payment_status' => $paymentStatus, 'payment_intent' => ['id' => "pi_{$id}", 'status' => $intentStatus],
            'amount_subtotal' => $order->subtotal_cents, 'amount_total' => $order->total_cents, 'currency' => 'usd',
            'total_details' => ['amount_discount' => 0, 'amount_tax' => $order->tax_cents]] + $this->sessions[$id];
    }

    private function webhook(string $type, string $id, string $eventId): TestResponse
    {
        return $this->postWebhook(['id' => $eventId, 'type' => $type, 'account' => 'acct_A', 'data' => ['object' => ['id' => $id, 'object' => 'checkout.session']]]);
    }

    /** @return array{Studio, ProductVariant, ProductVariant, ProductVariant} the studio, Tee Medium (3 in stock), Tee Small (sold out), Grip Aid (untracked, untaxed) */
    private function shop(array $settings = ['sales_tax_milli' => 8250]): array
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true, 'settings' => $settings]);
        $tee = $studio->products()->create(['name' => 'Studio Tee', 'price_cents' => 2500, 'taxable' => true]);
        $medium = $tee->variants()->create(['studio_id' => $studio->id, 'name' => 'Medium', 'stock' => 3]);
        $small = $tee->variants()->create(['studio_id' => $studio->id, 'name' => 'Small', 'stock' => 0]);
        $grip = $studio->products()->create(['name' => 'Grip Aid', 'price_cents' => 1000, 'taxable' => false])
            ->variants()->create(['studio_id' => $studio->id, 'name' => 'One size']);

        return [$studio, $medium, $small, $grip];
    }

    /** Set the cart to $qty of a variant (the session is shared by whoever is acting) and check out. */
    private function checkout(Studio $studio, User $member, ProductVariant $variant, int $qty = 1): TestResponse
    {
        $this->actingAs($member)->post("/{$studio->slug}/shop/cart/{$variant->id}", ['qty' => $qty]);

        return $this->post("/{$studio->slug}/shop/checkout");
    }

    public function test_anyone_can_browse_and_fill_a_cart_but_checking_out_takes_a_member(): void
    {
        [$studio, $medium, , $grip] = $this->shop();
        $hoodie = $studio->products()->create(['name' => 'Old Hoodie', 'price_cents' => 4000, 'active' => false])
            ->variants()->create(['studio_id' => $studio->id, 'name' => 'One size']);
        $other = $this->studio();
        $elsewhere = $other->products()->create(['name' => 'Their Tee', 'price_cents' => 100])->variants()->create(['studio_id' => $other->id, 'name' => 'One size']);
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $base = "/{$studio->slug}/shop";

        $this->get("/{$studio->slug}")->assertOk()->assertSee('href="'.url($base).'"', false);
        $this->get('/'.$this->studio()->slug)->assertOk()->assertDontSee('/shop"', false); // nothing for sale: no Shop link
        $this->get($base)->assertOk()->assertSee('Studio Tee')->assertSee('Small (sold out)')->assertSee('Medium (only 3 left)')->assertSee('Grip Aid')
            ->assertDontSee('Old Hoodie');

        $this->post("{$base}/cart", ['variant_id' => $elsewhere->id])->assertSessionHasErrors('variant_id');
        $this->post("{$base}/cart", ['variant_id' => $hoodie->id])->assertSessionHasErrors('variant_id'); // off sale
        $this->post("{$base}/cart", ['variant_id' => $medium->id, 'qty' => 2])->assertSessionHas('success', 'Added to your cart.');
        $this->post("{$base}/cart", ['variant_id' => $grip->id])->assertRedirect();
        $this->post("{$base}/cart/{$elsewhere->id}", ['qty' => 1])->assertNotFound();
        // $50 of tees taxed at 8.25% ($4.13), the $10 grip aid untaxed.
        $this->get("{$base}/cart")->assertOk()->assertSee('Studio Tee · Medium')->assertSee('$60.00')->assertSee('Tax (8.25%)')->assertSee('$4.13')
            ->assertSee('$64.13')->assertSee('Sign in to check out')->assertDontSee('Their Tee');
        $this->post("{$base}/cart/{$grip->id}", ['qty' => 0])->assertRedirect("{$base}/cart");
        $this->get($base)->assertSee('Cart (2)');
        $medium->product->update(['active' => false]); // taken off sale while in the cart
        $this->get("{$base}/cart")->assertOk()->assertSee('Your cart is empty');
        $medium->product->update(['active' => true]);
        $this->get("{$base}/cart")->assertOk()->assertDontSee('Grip Aid')->assertSee('$54.13');

        // Signing in comes back to the cart, which is still there.
        $this->post("{$base}/checkout")->assertRedirect("/{$studio->slug}/login");
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'password', 'studio' => $studio->slug])->assertRedirect(url("{$base}/cart"));
        $this->get("{$base}/cart")->assertOk()->assertSee('Check out · $54.13')->assertSee('held for 30 minutes');
        $this->actingAs($this->userIn($other))->get("{$base}/cart")->assertOk()->assertSee('Join '.$studio->name.' to check out');

        // No Stripe yet: browse, but not pay.
        $studio->update(['stripe_charges_enabled' => false]);
        $this->actingAs($member)->get("{$base}/cart")->assertOk()->assertSee('Online payments aren’t set up yet');
        $this->post("{$base}/checkout")->assertSessionHas('error', 'Online payments aren’t set up for this studio yet. Please ask at the front desk.');
        $this->assertSame(0, Order::count());
        Http::assertNothingSent();
    }

    public function test_checkout_holds_the_stock_and_payment_makes_it_a_numbered_order_to_pick_up(): void
    {
        [$studio, $medium, , $grip] = $this->shop();
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $base = "/{$studio->slug}/shop";
        $this->actingAs($member)->post("{$base}/cart", ['variant_id' => $medium->id, 'qty' => 2]);
        $this->post("{$base}/cart", ['variant_id' => $grip->id]);

        $this->post("{$base}/checkout")->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        // Held, not sold: no number yet, the stock reserved, not in anyone's purchases or the sales.
        $order = Order::with('items')->sole();
        $this->assertSame(['pending', null, null, 'online', 'stripe', 'to_pick_up', 6000, 413, 6413, 'cs_1', $member->id],
            [$order->status, $order->number, $order->paid_at, $order->channel, $order->method, $order->fulfillment_status, $order->subtotal_cents,
                $order->tax_cents, $order->total_cents, $order->stripe_checkout_session_id, $order->user_id]);
        $this->assertSame([['Studio Tee · Medium', 2, 5000, 413], ['Grip Aid', 1, 1000, 0]],
            $order->items->map(fn ($i) => [$i->description, $i->quantity, $i->total_cents, $i->tax_cents])->all());
        $this->assertSame([1, null], [$medium->fresh()->stock, $grip->fresh()->stock]);
        $this->get("/{$studio->slug}/account/billing")->assertOk()->assertDontSee('Studio Tee');
        $this->actingAs($owner)->get("/{$studio->slug}/admin/sales")->assertOk()->assertDontSee('Studio Tee')->assertDontSee('waiting to be picked up');

        // What Stripe was asked for: the studio's account, its tax rate on the taxable line, a 30-minute hold.
        $params = $this->created[0];
        $this->assertSame(['payment', 'shop', $order->id, $member->id, $studio->id],
            [$params['mode'], $params['metadata']['loft_kind'], $params['metadata']['loft_order_id'], $params['metadata']['loft_user_id'], $params['metadata']['loft_studio_id']]);
        $this->assertSame([['Studio Tee · Medium', 2, 2500, 'usd', ['txr_1']], ['Grip Aid', 1, 1000, 'usd', null]], collect($params['line_items'])
            ->map(fn ($l) => [$l['price_data']['product_data']['name'], $l['quantity'], $l['price_data']['unit_amount'], $l['price_data']['currency'], $l['tax_rates'] ?? null])->all());
        $this->assertEqualsWithDelta(now()->addMinutes(Shop::HOLD_MINUTES)->getTimestamp(), (int) $params['expires_at'], 5);
        $this->assertStringEndsWith("/shop/cart?cancelled={$order->id}", $params['cancel_url']);
        $this->assertStringEndsWith('/shop/complete?session_id={CHECKOUT_SESSION_ID}', $params['success_url']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/tax_rates') && $r['percentage'] === '8.25' && $r['inclusive'] === 'false'
            && $r->header('Stripe-Account') === ['acct_A']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r->header('Stripe-Account') === ['acct_A']);
        $this->assertSame(['txr_1', 8250], [$studio->fresh()->stripe_tax_rate_id, $studio->fresh()->stripe_tax_rate_milli]);

        // Paid: the webhook numbers it and emails a pickup receipt. The return page finds it done.
        $this->pay('cs_1');
        $this->webhook('checkout.session.completed', 'cs_1', 'evt_1')->assertOk();
        $order->refresh();
        $this->assertSame(['paid', 1001, 'pi_cs_1', 6413, 'to_pick_up'], [$order->status, $order->number, $order->stripe_payment_intent_id, $order->total_cents, $order->fulfillment_status]);
        $this->assertNotNull($order->paid_at);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'shop_order' && $m->hasTo('pat@example.com')
            && $m->details['Studio Tee · Medium ×2'] === '$50.00' && $m->details['Tax'] === '$4.13' && $m->details['Order'] === '#1001');
        $this->actingAs($member)->get("{$base}/complete?session_id=cs_1")->assertRedirect(url("/{$studio->slug}/account/billing"))
            ->assertSessionHas('success', 'Thanks! Order #1001 is ready to pick up at the front desk.');
        $this->assertSame(1, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'shop_order')->count(), 'one receipt');
        $this->assertSame(['order.paid'], DomainEvent::where('type', 'like', 'order.%')->pluck('type')->all());
        $this->get("{$base}/cart")->assertOk()->assertSee('Your cart is empty');
        $this->get("/{$studio->slug}/account/billing")->assertOk()->assertSee('Order #1001')->assertSee('Ready to pick up');
        $this->actingAs($this->userIn($studio))->get("{$base}/complete?session_id=cs_1")->assertNotFound();

        // The next checkout reuses the tax rate.
        $this->checkout($studio, $member, $medium)->assertRedirect('https://checkout.stripe.com/c/pay/cs_2');
        $this->assertSame(1, $this->taxRates);

        // The front desk hands it over.
        $this->actingAs($owner);
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('Orders to pick up');
        $this->get("/{$studio->slug}/admin/sales")->assertOk()->assertSee('1 online order waiting to be picked up');
        $this->get("/{$studio->slug}/admin/sales?pickup=1")->assertOk()->assertSee('#1001')->assertSee('Mark picked up');
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/picked-up")->assertSessionHas('success', 'Order #1001 marked as picked up.');
        $this->assertSame(['picked_up', $owner->id], [$order->fresh()->fulfillment_status, $order->fresh()->picked_up_by_user_id]);
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/picked-up")->assertSessionHas('error', 'Order #1001 isn’t waiting to be picked up.');
        $this->get("/{$studio->slug}/admin")->assertOk()->assertDontSee('Orders to pick up');
        $this->get("/{$studio->slug}/admin/sales?pickup=1")->assertOk()->assertSee('Nothing waiting to be picked up');
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()->assertSee('#1001')->assertSee('Picked up');
        $this->assertSame(['order.paid', 'order.picked_up'], DomainEvent::where('type', 'like', 'order.%')->orderBy('id')->pluck('type')->all());
        $this->actingAs($this->userIn($studio, 'instructor'))->post("/{$studio->slug}/admin/orders/{$order->id}/picked-up")->assertForbidden();
    }

    public function test_stock_goes_back_when_a_checkout_is_abandoned_or_cannot_start(): void
    {
        [$studio, $medium] = $this->shop();
        $sam = $this->userIn($studio);
        $pat = $this->userIn($studio);
        $cart = "/{$studio->slug}/shop/cart";

        // Sam's checkout holds 2 of the 3: Pat can't have 2 more.
        $this->checkout($studio, $sam, $medium, 2)->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        $this->checkout($studio, $pat, $medium, 2)->assertSessionHas('error', 'Only 1 of Studio Tee · Medium left.');
        $this->assertSame([1, 1], [Order::count(), $medium->fresh()->stock]);
        $first = Order::sole();

        // Back from Checkout without paying: the session is closed and the stock goes back. Only Sam can.
        $this->actingAs($pat)->get("{$cart}?cancelled={$first->id}")->assertOk();
        $this->assertSame('pending', $first->fresh()->status);
        $this->actingAs($sam)->get("{$cart}?cancelled={$first->id}")->assertOk();
        $this->assertSame(['expired', null, 3, 'expired'], [$first->fresh()->status, $first->fresh()->fulfillment_status, $medium->fresh()->stock, $this->sessions['cs_1']['status']]);

        // Starting another checkout lets go of the one before it.
        $this->checkout($studio, $sam, $medium, 1);
        $this->checkout($studio, $sam, $medium, 3)->assertRedirect('https://checkout.stripe.com/c/pay/cs_3');
        $this->assertSame(['expired', 'expired', 'pending'], Order::orderBy('id')->pluck('status')->all());
        $this->assertSame(0, $medium->fresh()->stock);
        $this->checkout($studio, $pat, $medium)->assertSessionHas('error', 'Studio Tee · Medium just sold out.');

        // A session that expired on its own (Stripe won't expire it again): still released on the way back.
        $this->sessions['cs_3']['status'] = 'expired';
        $third = Order::latest('id')->first();
        $this->actingAs($sam)->get("{$cart}?cancelled={$third->id}")->assertOk();
        $this->assertSame(['expired', 3], [$third->fresh()->status, $medium->fresh()->stock]);

        // Paid in another tab, then back: left for the webhook, not released.
        $this->checkout($studio, $sam, $medium, 1);
        $this->pay('cs_4');
        $fourth = Order::latest('id')->first();
        $this->get("{$cart}?cancelled={$fourth->id}")->assertOk();
        $this->assertSame(['pending', 2], [$fourth->fresh()->status, $medium->fresh()->stock]);

        // Stripe refuses: nothing stays held.
        $this->refuseCheckout = true;
        $this->checkout($studio, $pat, $medium, 2)->assertSessionHas('error', 'We couldn’t start checkout: Your account cannot currently make live charges.');
        $this->assertSame(['failed', 2], [Order::latest('id')->first()->status, $medium->fresh()->stock]);
        $this->assertSame(0, Order::whereNotNull('number')->count());
    }

    public function test_a_bank_payment_waits_to_clear_and_a_failed_one_releases_the_order(): void
    {
        [$studio, $medium] = $this->shop([]);
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $this->checkout($studio, $member, $medium, 1);

        $this->pay('cs_1', 'unpaid', 'processing');
        $this->webhook('checkout.session.completed', 'cs_1', 'evt_1')->assertOk();
        $this->get("/{$studio->slug}/shop/complete?session_id=cs_1")->assertSessionHas('success', fn ($m) => str_contains($m, 'Your payment is processing'));
        $order = Order::sole();
        $this->assertSame(['pending', null, 2], [$order->status, $order->number, $medium->fresh()->stock]);
        Mail::assertNothingQueued();

        $this->sessions['cs_1']['payment_intent']['status'] = 'requires_payment_method';
        $this->webhook('checkout.session.async_payment_failed', 'cs_1', 'evt_2')->assertOk();
        $this->assertSame(['failed', null, 3], [$order->fresh()->status, $order->fresh()->fulfillment_status, $medium->fresh()->stock]);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'checkout_failed' && $m->hasTo('pat@example.com')
            && $m->details === ['Studio Tee · Medium' => '×1'] && str_contains($m->actionUrl, '/shop'));

        // Told twice: released once.
        $this->webhook('checkout.session.async_payment_failed', 'cs_1', 'evt_retry')->assertOk();
        $this->assertSame(3, $medium->fresh()->stock);

        // A late success event can't bring it back.
        $this->webhook('checkout.session.async_payment_succeeded', 'cs_1', 'evt_3')->assertOk();
        $this->assertSame('failed', $order->fresh()->status);
    }

    public function test_an_abandoned_checkout_is_released_when_stripe_expires_it_without_waiting_for_the_sweep(): void
    {
        [$studio, $medium] = $this->shop([]);
        $this->checkout($studio, $this->userIn($studio), $medium, 2)->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        $order = Order::sole();
        $this->assertSame(1, $medium->fresh()->stock);

        // An early event: Stripe still has the session open, so nothing is released.
        $this->webhook('checkout.session.expired', 'cs_1', 'evt_early')->assertOk();
        $this->assertSame(['pending', 1], [$order->fresh()->status, $medium->fresh()->stock]);

        // The member closed the tab, and Stripe expires the session at the end of the hold.
        $this->sessions['cs_1']['status'] = 'expired';
        // Another studio's account can't release this studio's order.
        $this->studio(['stripe_account_id' => 'acct_B']);
        $this->postWebhook(['id' => 'evt_other', 'type' => 'checkout.session.expired', 'account' => 'acct_B', 'data' => ['object' => ['id' => 'cs_1', 'object' => 'checkout.session']]])->assertOk();
        $this->assertSame(['pending', 1], [$order->fresh()->status, $medium->fresh()->stock]);

        $this->webhook('checkout.session.expired', 'cs_1', 'evt_1')->assertOk();
        $this->assertSame(['expired', null, null, 3], [$order->fresh()->status, $order->fresh()->number, $order->fresh()->fulfillment_status, $medium->fresh()->stock]);
        Mail::assertNothingQueued();

        // Told twice: restocked once, and the sweep finds nothing left to settle.
        $this->webhook('checkout.session.expired', 'cs_1', 'evt_retry')->assertOk();
        $this->travel(Shop::HOLD_MINUTES + 15)->minutes();
        $this->assertSame(0, app(Shop::class)->sweep());
        $this->assertSame(3, $medium->fresh()->stock);

        // A plan's Checkout expiring holds no stock: nothing to do.
        $this->sessions['cs_plan'] = ['id' => 'cs_plan', 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'expired', 'payment_status' => 'unpaid',
            'metadata' => ['loft_plan_id' => '1', 'loft_user_id' => '1'], 'payment_intent' => null];
        $this->webhook('checkout.session.expired', 'cs_plan', 'evt_plan')->assertOk();
        $this->assertSame(['expired'], Order::pluck('status')->all());
    }

    public function test_the_hourly_sweep_settles_checkouts_nobody_came_back_from(): void
    {
        [$studio, $medium] = $this->shop([]);
        $medium->update(['stock' => 10]);
        $members = collect(range(1, 6))->map(fn ($i) => $this->userIn($studio, 'member', ['email' => "m{$i}@example.com"]));
        $this->travel(-2)->hours();
        $members->take(5)->each(fn (User $m) => $this->checkout($studio, $m, $medium)->assertRedirect());
        $this->travelBack();
        $this->checkout($studio, $members[5], $medium)->assertRedirect(); // cs_6: still inside its hold
        $this->assertSame(4, $medium->fresh()->stock);

        $this->pay('cs_1');                                            // paid, but the webhook never came
        $this->sessions['cs_2']['status'] = 'expired';                 // abandoned
        // cs_3: still "open" past its expiry time
        $this->pay('cs_4', 'unpaid', 'processing');                    // a bank debit, still clearing
        $this->pay('cs_5', 'unpaid', 'requires_payment_method');       // a bank debit that failed; the webhook never came

        $this->artisan('loft:housekeeping')->expectsOutputToContain('Shop orders settled: 4')->assertSuccessful();

        $this->assertSame(['paid', 'expired', 'expired', 'pending', 'failed', 'pending'], Order::orderBy('id')->pluck('status')->all());
        $this->assertSame([1001], Order::whereNotNull('number')->pluck('number')->all());
        $this->assertSame(7, $medium->fresh()->stock);
        Mail::assertQueued(StudioMessage::class, fn ($m) => $m->kind === 'shop_order' && $m->hasTo('m1@example.com'));
        Mail::assertQueued(StudioMessage::class, fn ($m) => $m->kind === 'checkout_failed' && $m->hasTo('m5@example.com'));
        $this->assertSame(2, Mail::queued(StudioMessage::class)->count());

        $this->assertSame(0, app(Shop::class)->sweep(), 'nothing left to settle');
        $this->assertSame(7, $medium->fresh()->stock);
    }
}
