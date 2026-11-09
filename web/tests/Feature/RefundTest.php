<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Studio;
use App\Models\User;
use App\Services\Orders;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Refunds (decision 34): from Loft, or from the Stripe Dashboard by webhook, recorded on the order and taking back what it paid for. */
class RefundTest extends TestCase
{
    /** @var array<string, array> charges served by the fake API */
    private array $charges = [];

    /** @var list<array{payment_intent: string, key: ?string}> refunds created */
    private array $refunds = [];

    /** @var list<string> subscriptions cancelled */
    private array $cancelled = [];

    private ?array $refundResponse = null;

    private bool $cancelFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
            if ($request->method() === 'POST' && $path === 'refunds') {
                if ($this->refundResponse && ($this->refundResponse['error'] ?? false)) {
                    return Http::response(['error' => ['message' => $this->refundResponse['error']]], 400);
                }
                $this->refunds[] = ['payment_intent' => $request['payment_intent'], 'key' => $request->header('Idempotency-Key')[0] ?? null];

                return Http::response(($this->refundResponse ?? []) + ['id' => 're_'.count($this->refunds), 'status' => 'succeeded']);
            }
            if ($request->method() === 'DELETE' && str_starts_with($path, 'subscriptions/')) {
                if ($this->cancelFails) {
                    return Http::response(['error' => ['message' => 'Network trouble']], 500);
                }
                $this->cancelled[] = basename($path);

                return Http::response(['id' => basename($path), 'status' => 'canceled']);
            }
            if (str_starts_with($path, 'subscriptions/')) {
                return Http::response(['id' => basename($path), 'status' => 'active']);
            }
            if (str_starts_with($path, 'charges/') && isset($this->charges[basename($path)])) {
                return Http::response($this->charges[basename($path)]);
            }

            return Http::response(['error' => ['message' => 'No such object: '.$path]], 404);
        }]);
    }

    private function stripeStudio(array $attributes = []): Studio
    {
        return $this->studio($attributes + ['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
    }

    /** A class pack bought online through Stripe Checkout. */
    private function packOrder(Studio $studio, User $member, string $intent = 'pi_1', int $total = 9000): Order
    {
        $plan = $this->plan($studio, ['name' => '5-Class Pack', 'kind' => 'pack', 'credits' => 5, 'price_cents' => $total]);
        $pack = $this->credits($studio, $member, 5, ['plan_id' => $plan->id]);

        return app(Orders::class)->recordCheckout($studio, [
            'id' => 'cs_'.$intent, 'mode' => 'payment', 'amount_total' => $total, 'amount_subtotal' => $total, 'currency' => 'usd', 'payment_intent' => $intent,
        ], $pack);
    }

    /** A membership invoice paid through Stripe. */
    private function membershipOrder(Studio $studio, Entitlement $membership, string $invoice, ?string $intent): Order
    {
        return app(Orders::class)->recordInvoice($studio, [
            'id' => $invoice, 'amount_paid' => 12000, 'subtotal' => 12000, 'currency' => 'usd', 'payment_intent' => $intent,
            'lines' => ['data' => [['price' => ['id' => 'price_m'], 'quantity' => 1, 'amount' => 12000]]],
        ], $membership);
    }

    private function chargeRefunded(string $charge, array $object, string $event): void
    {
        $this->charges[$charge] = $object + ['id' => $charge];
        $this->postWebhook(['id' => $event, 'type' => 'charge.refunded', 'account' => 'acct_A', 'data' => ['object' => ['id' => $charge, 'object' => 'charge']]])->assertOk();
    }

    public function test_refunds_in_the_stripe_dashboard_are_recorded_and_a_full_one_takes_the_pack_back(): void
    {
        $studio = $this->stripeStudio();
        $member = $this->userIn($studio, 'member', ['first_name' => 'Pat']);
        $order = $this->packOrder($studio, $member);
        $pack = Entitlement::sole();
        $booked = Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($this->program($studio))->id,
            'attendee_id' => $this->attendee($studio, $member)->id, 'user_id' => $member->id, 'entitlement_id' => $pack->id, 'status' => 'booked', 'booked_at' => now()]);
        $this->actingAs($this->userIn($studio, 'owner'));

        // Part of it: recorded, nothing taken back.
        $this->chargeRefunded('ch_1', ['payment_intent' => 'pi_1', 'amount_refunded' => 2000], 'evt_1');
        $order->refresh();
        $this->assertSame(['paid', 2000, 'Partly refunded'], [$order->status, $order->refunded_cents, $order->statusLabel()]);
        $this->assertSame('active', $pack->fresh()->status);
        $this->get("/{$studio->slug}/admin/sales")->assertOk()->assertSee('$70.00')->assertSee('−$20.00 refunded')->assertSee('>Refund</a>', false);
        $this->get("/{$studio->slug}")->assertOk();
        $this->actingAs($member)->get("/{$studio->slug}/account/billing")->assertSee('$90.00 · $20.00 refunded');

        // The rest: refunded in full, so the pack is taken back. Its booking stays.
        $this->chargeRefunded('ch_1', ['payment_intent' => 'pi_1', 'amount_refunded' => 9000], 'evt_2');
        $order->refresh();
        $this->assertSame(['refunded', 9000, 'Refunded'], [$order->status, $order->refunded_cents, $order->statusLabel()]);
        $this->assertSame(['cancelled', 'booked'], [$pack->fresh()->status, $booked->fresh()->status]);
        $this->assertNull($order->refunded_by_user_id);
        $this->assertSame([[2000, false, 'stripe'], [7000, true, 'stripe']], DomainEvent::where('type', 'order.refunded')->orderBy('id')->get()
            ->map(fn ($e) => [$e->payload['amount_cents'], $e->payload['full'], $e->payload['by']])->all());
        $this->assertSame('refunded', DomainEvent::where('type', 'entitlement.ended')->sole()->payload['reason']);
        $this->actingAs($member)->get("/{$studio->slug}/account/billing")->assertSee('Refunded');

        // An old event arriving late, or one for a payment Loft didn't sell, changes nothing.
        $this->chargeRefunded('ch_1', ['payment_intent' => 'pi_1', 'amount_refunded' => 2000], 'evt_3');
        $this->chargeRefunded('ch_9', ['payment_intent' => 'pi_unknown', 'amount_refunded' => 500], 'evt_4');
        $this->assertSame([9000, 2], [$order->fresh()->refunded_cents, DomainEvent::where('type', 'order.refunded')->count()]);

        // Staff see it all.
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()
            ->assertSee('Refunded $20.00 (part of the payment) (#1001) in Stripe')->assertSee('Refunded $70.00 (#1001) in Stripe')
            ->assertSee('5-Class Pack taken back (refunded)');
        $this->get("/{$studio->slug}/admin/orders/{$order->id}/refund")->assertRedirect()->assertSessionHas('error', 'Sale #1001 was already refunded.');
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/refund")->assertRedirect()->assertSessionHas('error', 'Sale #1001 was already refunded.');
        $this->assertSame([], $this->refunds, 'Stripe isn’t asked twice');

        // Another studio's payment can't touch this studio's orders, and a voided sale stays void.
        $other = $this->stripeStudio(['stripe_account_id' => 'acct_B']);
        $theirs = $this->packOrder($other, $this->userIn($other), 'pi_B');
        $this->chargeRefunded('ch_B', ['payment_intent' => 'pi_B', 'amount_refunded' => 9000], 'evt_5'); // sent for acct_A
        $this->assertSame(['paid', 0], [$theirs->fresh()->status, $theirs->fresh()->refunded_cents]);
        $cash = Order::create(['studio_id' => $studio->id, 'number' => 1009, 'channel' => 'in_person', 'method' => 'reader', 'status' => 'void', 'currency' => 'usd',
            'subtotal_cents' => 1000, 'total_cents' => 1000, 'paid_at' => now(), 'stripe_payment_intent_id' => 'pi_void']);
        $this->chargeRefunded('ch_V', ['payment_intent' => 'pi_void', 'amount_refunded' => 1000], 'evt_6');
        $this->assertSame(['void', 0], [$cash->fresh()->status, $cash->fresh()->refunded_cents]);
    }

    public function test_staff_refund_online_sales_from_loft(): void
    {
        $studio = $this->stripeStudio();
        $member = $this->userIn($studio, 'member', ['first_name' => 'Pat']);
        $order = $this->packOrder($studio, $member);
        $this->actingAs($this->userIn($studio, 'owner'));
        $sales = "/{$studio->slug}/admin/sales";
        $refund = "/{$studio->slug}/admin/orders/{$order->id}/refund";

        $this->get($sales)->assertOk()->assertSee($refund.'?return=', false);
        $this->get($refund.'?return='.urlencode($sales))->assertOk()->assertSee('Refund sale #1001')->assertSee('Refund $90.00')
            ->assertSee('5-Class Pack</strong> is cancelled (5 credits left on it)', false)->assertDontSee('Refund this payment only');

        // Stripe says no: nothing changes.
        $this->refundResponse = ['error' => 'Charge ch_1 has already been refunded.'];
        $this->post($refund)->assertSessionHas('error', 'Stripe couldn’t refund it: Charge ch_1 has already been refunded.');
        $this->refundResponse = ['status' => 'failed', 'failure_reason' => 'expired_or_canceled_card'];
        $this->post($refund)->assertSessionHas('error', 'Stripe couldn’t refund it: The refund didn’t go through (expired or canceled card).');
        $this->assertSame(['paid', 0, 'active'], [$order->fresh()->status, $order->fresh()->refunded_cents, Entitlement::sole()->status]);

        $this->refundResponse = null;
        $this->post($refund, ['return' => $sales])->assertRedirect($sales)
            ->assertSessionHas('success', 'Sale #1001 refunded: $90.00 back to the customer; plans it added were cancelled.');
        $this->assertSame([['payment_intent' => 'pi_1', 'key' => 'loft-refund-pi_1']], array_slice($this->refunds, -1));
        $order->refresh();
        $this->assertSame(['refunded', 9000, 'cancelled'], [$order->status, $order->refunded_cents, Entitlement::sole()->status]);
        $this->assertNotNull($order->refunded_by_user_id);
        $this->assertSame('staff', DomainEvent::where('type', 'order.refunded')->sole()->payload['by']);
        // Stripe's webhook for the same refund then changes nothing.
        $this->chargeRefunded('ch_1', ['payment_intent' => 'pi_1', 'amount_refunded' => 9000], 'evt_1');
        $this->assertSame(1, DomainEvent::where('type', 'order.refunded')->count());
        $this->get($sales)->assertSee('<span class="badge badge-muted">Refunded</span>', false);

        // A shop order waiting to be picked up: back into stock, and nothing to pick up.
        $product = $studio->products()->create(['name' => 'Studio Tee', 'price_cents' => 2500]);
        $tee = $product->variants()->create(['studio_id' => $studio->id, 'name' => 'M', 'stock' => 4]);
        $shop = Order::create(['studio_id' => $studio->id, 'number' => 1002, 'user_id' => $member->id, 'channel' => 'online', 'method' => 'stripe', 'status' => 'paid',
            'currency' => 'usd', 'subtotal_cents' => 5000, 'total_cents' => 5000, 'stripe_payment_intent_id' => 'pi_shop', 'paid_at' => now(), 'fulfillment_status' => 'to_pick_up']);
        $shop->items()->create(['kind' => 'product', 'product_variant_id' => $tee->id, 'description' => 'Studio Tee · M', 'quantity' => 2, 'unit_price_cents' => 2500, 'total_cents' => 5000]);
        $this->get("/{$studio->slug}/admin/orders/{$shop->id}/refund")->assertSee('Studio Tee · M ×2 go back into stock.');
        $this->post("/{$studio->slug}/admin/orders/{$shop->id}/refund")
            ->assertSessionHas('success', 'Sale #1002 refunded: $50.00 back to the customer; products went back into stock.');
        $this->assertSame([6, 'refunded', null], [$tee->fresh()->stock, $shop->fresh()->status, $shop->fresh()->fulfillment_status]);

        // Part refunded in the Dashboard already: the rest is refunded.
        $other = $this->packOrder($studio, $member, 'pi_2', 4000);
        $other->update(['refunded_cents' => 1500]);
        $this->get("/{$studio->slug}/admin/orders/{$other->id}/refund")->assertSee('Already refunded')->assertSee('−$15.00')->assertSee('Refund $25.00');

        // Sales paid in person without Stripe are voided instead; instructors and other studios can't refund.
        $cash = Order::create(['studio_id' => $studio->id, 'number' => 1004, 'channel' => 'in_person', 'method' => 'cash', 'status' => 'paid', 'currency' => 'usd',
            'subtotal_cents' => 1000, 'total_cents' => 1000, 'paid_at' => now(), 'walk_in' => true]);
        $this->get("/{$studio->slug}/admin/orders/{$cash->id}/refund")->assertRedirect()->assertSessionHas('error', 'Sale #1004 can’t be refunded from Loft. Refund it in the Stripe Dashboard.');
        $this->actingAs($this->userIn($studio, 'instructor'))->get($refund)->assertForbidden();
        $elsewhere = $this->stripeStudio(['stripe_account_id' => 'acct_B']);
        $this->actingAs($this->userIn($elsewhere, 'owner'))->get("/{$elsewhere->slug}/admin/orders/{$order->id}/refund")->assertNotFound();
    }

    public function test_membership_payments_are_refunded_with_or_without_ending_the_membership(): void
    {
        $studio = $this->stripeStudio();
        $member = $this->userIn($studio, 'member', ['first_name' => 'Pat']);
        $plan = $this->plan($studio, ['name' => 'Unlimited', 'kind' => 'subscription', 'price_cents' => 12000, 'credits' => null, 'billing_interval' => 'month', 'stripe_price_id' => 'price_m']);
        $membership = $this->membership($studio, $member, null, ['plan_id' => $plan->id, 'stripe_subscription_id' => 'sub_1']);
        $october = $this->membershipOrder($studio, $membership, 'in_1', 'pi_m1');
        $november = $this->membershipOrder($studio, $membership, 'in_2', 'pi_m2');
        $this->actingAs($this->userIn($studio, 'owner'));

        // This payment only: the membership keeps renewing.
        $this->get("/{$studio->slug}/admin/orders/{$october->id}/refund")->assertOk()->assertSee('Refund this payment only')->assertSee('Refund and end the membership now')
            ->assertDontSee('is cancelled');
        $this->post("/{$studio->slug}/admin/orders/{$october->id}/refund", ['end_membership' => '0'])
            ->assertSessionHas('success', 'Sale #1001 refunded: $120.00 back to the customer; the membership keeps renewing.');
        $this->assertSame(['refunded', 'active', []], [$october->fresh()->status, $membership->fresh()->status, $this->cancelled]);

        // Ending it too, when Stripe can't be reached: the refund stands, the membership doesn't end.
        $this->cancelFails = true;
        $this->post("/{$studio->slug}/admin/orders/{$november->id}/refund", ['end_membership' => '1'])
            ->assertSessionHas('warning', 'Refunded, but Stripe didn’t end the membership (Network trouble). Cancel it in the Stripe Dashboard; Loft follows.');
        $this->assertSame(['refunded', 'active'], [$november->fresh()->status, $membership->fresh()->status]);

        // And when it can.
        $this->cancelFails = false;
        $december = $this->membershipOrder($studio, $membership, 'in_3', 'pi_m3');
        $this->post("/{$studio->slug}/admin/orders/{$december->id}/refund", ['end_membership' => '1'])
            ->assertSessionHas('success', 'Sale #1003 refunded: $120.00 back to the customer; the membership has ended.');
        $this->assertSame(['sub_1'], $this->cancelled);
        $this->assertSame('cancelled', $membership->fresh()->status);
        $this->assertSame(['refunded'], DomainEvent::where('type', 'entitlement.ended')->get()->map(fn ($e) => $e->payload['reason'])->all());

        // Refunded in the Dashboard (found by its invoice): recorded, and the membership isn't ended by it.
        $other = $this->userIn($studio);
        $running = $this->membership($studio, $other, null, ['plan_id' => $plan->id, 'stripe_subscription_id' => 'sub_2']);
        $invoiceOrder = $this->membershipOrder($studio, $running, 'in_9', null);
        $this->get("/{$studio->slug}/admin/members/{$other->id}")->assertDontSee('>Refund</a>', false); // no payment to refund from Loft
        $this->chargeRefunded('ch_9', ['payment_intent' => 'pi_elsewhere', 'invoice' => 'in_9', 'amount_refunded' => 12000], 'evt_9');
        $this->assertSame(['refunded', 'active'], [$invoiceOrder->fresh()->status, $running->fresh()->status]);
    }

    public function test_totals_and_the_api_count_what_the_studio_kept(): void
    {
        $studio = $this->stripeStudio(['timezone' => 'UTC']);
        $member = $this->userIn($studio);
        $kept = $this->packOrder($studio, $member, 'pi_1', 9000);
        $part = $this->packOrder($studio, $member, 'pi_2', 5000);
        $gone = $this->packOrder($studio, $member, 'pi_3', 3000);
        app(Orders::class)->refunded($part, 1000);
        app(Orders::class)->refunded($gone, 3000);
        $this->assertFalse(app(Orders::class)->refunded($gone, 3000), 'once');
        $this->assertFalse(app(Orders::class)->refunded($kept, 0));
        $overpaid = $this->packOrder($studio, $member, 'pi_4', 2000);
        app(Orders::class)->refunded($overpaid, 2500);
        $this->assertSame([2000, 'refunded'], [$overpaid->fresh()->refunded_cents, $overpaid->fresh()->status], 'never more than the sale');

        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/sales")->assertOk()
            ->assertSee('<strong>$130.00</strong><span>Total sales</span>', false)
            ->assertSee('<strong>−$60.00</strong><span>Refunded</span>', false)
            ->assertSee('<strong>2</strong><span>Number of sales', false);
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('$130.00');
        $csv = $this->get("/{$studio->slug}/admin/sales/export")->streamedContent();
        $rows = collect(array_map('str_getcsv', array_filter(explode("\n", trim($csv)))))->keyBy(0);
        $this->assertSame(['10.00', 'Partly refunded'], [$rows['1002'][12], $rows['1002'][14]]);
        $this->assertSame(['30.00', 'Refunded'], [$rows['1003'][12], $rows['1003'][14]]);

        $token = $member->createToken('Phone', ['member'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $orders = $this->getJson("/api/v1/studios/{$studio->slug}/orders", ['Authorization' => "Bearer {$token}"])->assertOk()->json('data');
        $this->assertSame([['refunded', 2000], ['refunded', 3000], ['paid', 1000], ['paid', 0]], collect($orders)->map(fn ($o) => [$o['status'], $o['refunded_cents']])->all());
    }
}
