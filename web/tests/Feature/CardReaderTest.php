<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\CardReader;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Studio;
use App\Models\User;
use App\Services\ReaderPayments;
use App\Services\Stripe\StripeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stripe Terminal card readers at the point of sale, against a fake Stripe that keeps readers (and
 * what each is doing), payment intents and refunds, all on the studio's connected account.
 */
class CardReaderTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $readers = [];

    /** @var array<string, array<string, mixed>> */
    private array $intents = [];

    /** @var list<string> payment intents refunded */
    private array $refunds = [];

    private ?array $location = null;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            if ($request->header('Stripe-Account') !== ['acct_A']) {
                return $this->error('Not on the studio’s account', 'wrong_account');
            }
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
            $post = $request->method() === 'POST';

            return match (true) {
                $post && $path === 'terminal/locations' => Http::response($this->location = ['id' => 'tml_1', 'address' => $request['address']]),
                $post && $path === 'terminal/readers' => $this->register($request['registration_code'], $request['label']),
                $request->method() === 'GET' && $path === 'terminal/readers' => Http::response(['data' => array_values($this->readers)]),
                $request->method() === 'DELETE' => $this->forget(Str::after($path, 'terminal/readers/')),
                $post && str_ends_with($path, '/process_payment_intent') => $this->process(Str::between($path, 'terminal/readers/', '/'), $request['payment_intent']),
                $post && str_ends_with($path, '/cancel_action') => $this->clear(Str::between($path, 'terminal/readers/', '/')),
                $post && str_ends_with($path, '/present_payment_method') => $this->tap(Str::between($path, 'test_helpers/terminal/readers/', '/'), $request['card_present']['number'] ?? null),
                str_starts_with($path, 'terminal/readers/') => Http::response($this->readers[Str::after($path, 'terminal/readers/')]),
                $post && $path === 'payment_intents' => $this->createIntent($request->data()),
                $post && str_ends_with($path, '/cancel') => $this->cancelIntent(Str::between($path, 'payment_intents/', '/')),
                $post && $path === 'refunds' => Http::response(['id' => 're_'.count($this->refunds = [...$this->refunds, $request['payment_intent']])]),
                str_starts_with($path, 'payment_intents/') => Http::response($this->intents[Str::after($path, 'payment_intents/')]),
                default => $this->error("Unexpected {$request->method()} {$path}", 'unexpected'),
            };
        }]);
    }

    private function error(string $message, string $code, int $status = 400)
    {
        return Http::response(['error' => ['message' => $message, 'code' => $code]], $status);
    }

    private function register(string $code, string $label)
    {
        if ($code !== 'simulated-wpe') {
            return $this->error('The registration code is invalid or expired.', 'invalid_registration_code');
        }
        $id = 'tmr_'.(count($this->readers) + 1);

        return Http::response($this->readers[$id] = ['id' => $id, 'label' => $label, 'device_type' => 'simulated_wisepos_e',
            'serial_number' => 'SIM-'.$id, 'status' => 'online', 'action' => null]);
    }

    private function forget(string $id)
    {
        unset($this->readers[$id]);

        return Http::response(['id' => $id, 'deleted' => true]);
    }

    private function process(string $reader, string $intent)
    {
        if ($this->readers[$reader]['status'] === 'offline') {
            return $this->error('Reader is currently offline', 'terminal_reader_offline');
        }
        if (($this->readers[$reader]['action']['status'] ?? null) === 'in_progress') {
            return $this->error('Reader is currently busy', 'terminal_reader_busy');
        }
        $this->readers[$reader]['action'] = ['type' => 'process_payment_intent', 'status' => 'in_progress', 'process_payment_intent' => ['payment_intent' => $intent]];

        return Http::response($this->readers[$reader]);
    }

    private function clear(string $reader)
    {
        $this->readers[$reader]['action'] = null;

        return Http::response($this->readers[$reader]);
    }

    /** The customer taps a card: approved, or declined (a decline keeps its error on the intent). */
    private function tap(string $reader, ?string $number)
    {
        $intent = $this->readers[$reader]['action']['process_payment_intent']['payment_intent'];
        if ($number === ReaderPayments::DECLINED_TEST_CARD) {
            $this->readers[$reader]['action'] = ['status' => 'failed', 'failure_code' => 'card_declined', 'failure_message' => 'Your card was declined.'] + $this->readers[$reader]['action'];
            $this->intents[$intent]['last_payment_error'] = ['code' => 'card_declined', 'message' => 'Your card was declined.'];
        } else {
            $this->readers[$reader]['action']['status'] = 'succeeded';
            $this->intents[$intent]['status'] = 'succeeded';
        }

        return Http::response($this->readers[$reader]);
    }

    private function createIntent(array $params)
    {
        $id = 'pi_'.(count($this->intents) + 1);
        $this->intents[$id] = ['id' => $id, 'status' => 'requires_payment_method', 'last_payment_error' => null] + $params;

        return Http::response($this->intents[$id]);
    }

    private function cancelIntent(string $id)
    {
        if ($this->intents[$id]['status'] === 'succeeded') {
            return $this->error('This PaymentIntent could not be canceled because it has a status of succeeded.', 'payment_intent_unexpected_state');
        }
        $this->intents[$id]['status'] = 'canceled';

        return Http::response($this->intents[$id]);
    }

    /** A connected studio with a paired reader, a $25 tee (3 in stock, taxed 8.25%) and a 10-class pack. */
    private function till(): array
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true, 'stripe_terminal_location_id' => 'tml_1',
            'settings' => ['sales_tax_milli' => 8250]]);
        $this->register('simulated-wpe', 'Front desk');
        $reader = $studio->cardReaders()->create(['stripe_reader_id' => 'tmr_1', 'label' => 'Front desk', 'device_type' => 'simulated_wisepos_e']);
        $tee = $studio->products()->create(['name' => 'Studio Tee', 'price_cents' => 2500])->variants()->create(['studio_id' => $studio->id, 'name' => 'Medium', 'stock' => 3]);
        $pack = $this->plan($studio, ['name' => '10-Class Pack', 'kind' => 'pack', 'credits' => 10, 'price_cents' => 11000]);

        return [$studio, $reader, $tee, $pack];
    }

    /** Fill the till's cart and send it to the reader. */
    private function charge(Studio $studio, CardReader $reader, array $lines, ?User $member = null)
    {
        $pos = "/{$studio->slug}/admin/pos";
        $this->post("{$pos}/clear");
        foreach ($lines as [$type, $id, $qty]) {
            $this->post("{$pos}/items", ['type' => $type, 'id' => $id]);
            if ($qty > 1) {
                $this->post("{$pos}/items/".(count(session("pos.cart.{$studio->id}.lines")) - 1), ['qty' => $qty]);
            }
        }
        if ($member) {
            $this->post("{$pos}/customer", ['member_id' => $member->id]);
        }

        return $this->post("{$pos}/reader", ['reader_id' => $reader->id]);
    }

    public function test_setting_up_and_removing_card_readers(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $page = "/{$studio->slug}/admin/payments/readers";
        $this->get($page)->assertOk()->assertSee('Connect Stripe first');

        $studio->update(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->get($page)->assertOk()->assertSee('Where are your readers?');
        $this->post("{$page}/location", ['line1' => '12 Main St', 'country' => 'ZZ'])->assertSessionHasErrors(['city', 'postal_code', 'country']);
        $this->post("{$page}/location", ['line1' => '12 Main St', 'line2' => '', 'city' => 'Denver', 'state' => 'CO', 'postal_code' => '80202', 'country' => 'US'])
            ->assertRedirect($page)->assertSessionHas('success');
        $this->assertSame('tml_1', $studio->fresh()->stripe_terminal_location_id);
        $this->assertSame(['line1' => '12 Main St', 'city' => 'Denver', 'state' => 'CO', 'postal_code' => '80202', 'country' => 'US'], $this->location['address']);

        $this->get($page)->assertOk()->assertSee('No readers yet')->assertSee('simulated-wpe');
        $this->post($page, ['code' => 'wrong-code-here', 'label' => 'Front desk'])->assertSessionHas('error', 'Stripe: The registration code is invalid or expired.');
        $this->post($page, ['code' => ' simulated-wpe ', 'label' => 'Front desk'])->assertSessionHas('success', 'Front desk is paired. It’s ready at the point of sale.');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/terminal/readers') && $r['location'] === 'tml_1' && $r['registration_code'] === 'simulated-wpe');
        $reader = CardReader::sole();
        $this->assertSame([$studio->id, 'tmr_1', 'Front desk', 'Simulated WisePOS E (test mode)', 'SIM-tmr_1'],
            [$reader->studio_id, $reader->stripe_reader_id, $reader->label, $reader->deviceLabel(), $reader->serial_number]);

        // Readers added or removed in the Stripe Dashboard are picked up; status is live.
        $this->register('simulated-wpe', 'Back office');
        $this->readers['tmr_2']['status'] = 'offline';
        $this->get($page)->assertOk()->assertSeeInOrder(['Back office', 'Offline', 'Front desk', 'Online']);
        $this->assertSame(2, CardReader::count());
        unset($this->readers['tmr_2']);
        $this->get($page)->assertOk()->assertDontSee('Back office');

        $this->delete("{$page}/{$reader->id}")->assertSessionHas('success', 'Front desk was removed.');
        $this->assertSame([0, []], [CardReader::count(), $this->readers]);
        $this->actingAs($this->userIn($studio, 'instructor'))->get($page)->assertForbidden();
    }

    public function test_a_sale_paid_on_the_reader_is_recorded_once_and_can_be_refunded(): void
    {
        [$studio, $reader, $tee, $pack] = $this->till();
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $this->actingAs($owner);

        // $50 of tees (+$4.13 tax) and a $110 pack: $164.13.
        $this->charge($studio, $reader, [['variant', $tee->id, 2], ['plan', $pack->id, 1]], $member);
        $order = Order::with('items')->sole();
        $this->assertSame(['pending', null, 'in_person', 'reader', 16413, $reader->id, 'pi_1', 1], [$order->status, $order->number, $order->channel,
            $order->method, $order->total_cents, $order->card_reader_id, $order->stripe_payment_intent_id, $tee->fresh()->stock]);
        $this->assertSame(0, Entitlement::count(), 'plans wait for the payment');
        $this->assertSame([16413, 'usd', ['card_present'], 'automatic', (string) $order->id, 'reader'], [$this->intents['pi_1']['amount'], $this->intents['pi_1']['currency'],
            $this->intents['pi_1']['payment_method_types'], $this->intents['pi_1']['capture_method'], (string) $this->intents['pi_1']['metadata']['loft_order_id'], $this->intents['pi_1']['metadata']['loft_kind']]);
        $this->assertSame('pi_1', $this->readers['tmr_1']['action']['process_payment_intent']['payment_intent']);

        $wait = "/{$studio->slug}/admin/pos/orders/{$order->id}/reader";
        $this->get($wait)->assertOk()->assertSee('Waiting for the card')->assertSee('$164.13')->assertSee('http-equiv="refresh"', false)->assertSee('Tap a test card');
        $this->get("/{$studio->slug}/admin/pos")->assertOk()->assertSee('Waiting for a card on Front desk');
        $this->get("/{$studio->slug}/admin/pos/orders/{$order->id}")->assertRedirect(url($wait));
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/void")->assertSessionHas('error', 'Only paid sales can be voided.');

        // Live keys: no test cards.
        config(['loft.stripe.secret' => 'sk_live_platform']);
        $this->app->forgetInstance(StripeClient::class);
        $this->get($wait)->assertOk()->assertDontSee('Tap a test card');
        $this->post("{$wait}/simulate")->assertNotFound();
        config(['loft.stripe.secret' => 'sk_test_platform']);
        $this->app->forgetInstance(StripeClient::class);

        // The customer taps: recorded, numbered, plan granted, receipt sent, the till's cart emptied.
        $stale = Order::find($order->id); // as the hourly sweep might hold it, at the same moment
        $this->post("{$wait}/simulate")->assertRedirect(url($wait));
        $receipt = "/{$studio->slug}/admin/pos/orders/{$order->id}";
        $this->get($wait)->assertRedirect(url($receipt))->assertSessionHas('success', 'Paid on the card reader. Sale #1001 recorded.');
        $this->get($wait)->assertRedirect(url($receipt));
        $this->assertSame('paid', app(ReaderPayments::class)->settle($stale)['state']);
        $order->refresh();
        $this->assertSame(['paid', 1001], [$order->status, $order->number]);
        $credits = Entitlement::sole();
        $this->assertSame([$member->id, 10, $credits->id], [$credits->user_id, $credits->credits_remaining, $order->items->firstWhere('kind', 'plan')->fresh()->entitlement_id]);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'receipt' && $m->hasTo('pat@example.com') && $m->details['Total'] === '$164.13 · Card reader');
        $this->assertSame(1, Mail::queued(StudioMessage::class)->count());
        $this->get($receipt)->assertOk()->assertSee('Paid by card reader')->assertSee('Refund to the card and void');
        $this->get("/{$studio->slug}/admin/pos")->assertOk()->assertSee('Empty')->assertDontSee('Waiting for a card');
        $this->assertSame('Got 10-Class Pack (paid on a card reader)', DomainEvent::where('type', 'entitlement.granted')->sole()->label());
        $this->get("/{$studio->slug}/admin/sales")->assertOk()->assertSee('Card reader')->assertSee('>Refund</button>', false);

        // Refunded to the card and voided.
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/void")
            ->assertSessionHas('success', 'Sale #1001 refunded to the card and voided: plans it added were cancelled and products went back into stock.');
        $this->assertSame(['pi_1'], $this->refunds);
        $this->assertSame(['void', 'cancelled', 3], [$order->fresh()->status, $credits->fresh()->status, $tee->fresh()->stock]);
        $this->assertTrue(DomainEvent::where('type', 'order.voided')->sole()->payload['refunded']);
    }

    public function test_declines_retries_cancelling_and_readers_that_cannot_take_it(): void
    {
        [$studio, $reader, $tee] = $this->till();
        $this->actingAs($this->userIn($studio, 'owner'));
        $pos = "/{$studio->slug}/admin/pos";

        // Declined: say why, offer to try again; trying again waits for a card again.
        $this->charge($studio, $reader, [['variant', $tee->id, 1]]);
        $first = Order::sole();
        $wait = "{$pos}/orders/{$first->id}/reader";
        $this->post("{$wait}/simulate", ['decline' => '1']);
        $this->get($wait)->assertOk()->assertSee('The payment didn’t go through')->assertSee('Your card was declined.')->assertSee('Try again on Front desk')
            ->assertDontSee('http-equiv="refresh"', false);
        $this->post("{$wait}/retry")->assertRedirect(url($wait));
        $this->get($wait)->assertOk()->assertSee('Waiting for the card');
        $this->post("{$wait}/simulate");
        $this->get($wait)->assertRedirect(url("{$pos}/orders/{$first->id}"));
        $this->assertSame(['paid', 1001, 2], [$first->fresh()->status, $first->fresh()->number, $tee->fresh()->stock]);

        // Cancelled: the reader is cleared, nothing charged, the stock and the cart come back.
        $this->charge($studio, $reader, [['variant', $tee->id, 2]]);
        $second = Order::latest('id')->first();
        $this->assertSame(0, $tee->fresh()->stock);
        $this->post("{$pos}/orders/{$second->id}/reader/cancel")->assertRedirect(url($pos))->assertSessionHas('success', 'Cancelled. Nothing was charged, and the cart is still here.');
        $this->assertSame(['cancelled', null, 2, 'canceled'], [$second->fresh()->status, $this->readers['tmr_1']['action'], $tee->fresh()->stock, $this->intents['pi_2']['status']]);
        $this->get($pos)->assertOk()->assertSee('Studio Tee · Medium');
        $this->get("{$pos}/orders/{$second->id}/reader")->assertRedirect(url($pos));

        // Paid just as staff pressed Cancel: recorded, not cancelled.
        $this->charge($studio, $reader, [['variant', $tee->id, 1]]);
        $third = Order::latest('id')->first();
        $this->tap('tmr_1', null);
        $this->post("{$pos}/orders/{$third->id}/reader/cancel")->assertRedirect(url("{$pos}/orders/{$third->id}"));
        $this->assertSame(['paid', 1002], [$third->fresh()->status, $third->fresh()->number]);

        // One sale per reader at a time; an offline reader leaves nothing behind; too small for a card.
        $this->charge($studio, $reader, [['variant', $tee->id, 1]]);
        $this->assertSame(0, $tee->fresh()->stock);
        $tee->update(['stock' => 5]);
        $this->charge($studio, $reader, [['variant', $tee->id, 1]])->assertSessionHas('error', 'Front desk is already waiting for a card. Finish or cancel that sale first.');
        $this->delete("/{$studio->slug}/admin/payments/readers/{$reader->id}")->assertSessionHas('error', 'Front desk is waiting for a card. Finish or cancel that sale first.');
        $this->post("{$pos}/orders/".Order::latest('id')->value('id').'/reader/cancel');
        $this->readers['tmr_1']['status'] = 'offline';
        $this->charge($studio, $reader, [['variant', $tee->id, 1]])->assertSessionHas('error', 'Front desk is offline. Check it’s on and connected to the internet, then try again.');
        $this->assertSame(['failed', 6], [Order::latest('id')->value('status'), $tee->fresh()->stock]);
        $this->assertSame('canceled', end($this->intents)['status']);
        $this->readers['tmr_1']['status'] = 'online';
        $cheap = $studio->products()->create(['name' => 'Sticker', 'price_cents' => 25, 'taxable' => false])->variants()->create(['studio_id' => $studio->id, 'name' => 'One size']);
        $this->charge($studio, $reader, [['variant', $cheap->id, 1]])->assertSessionHas('error', 'Card payments must be at least $0.50.');
        $this->assertSame(2, Order::where('status', 'paid')->count());
    }

    public function test_the_hourly_sweep_settles_reader_sales_nobody_finished(): void
    {
        [$studio, $reader, $tee] = $this->till();
        $this->register('simulated-wpe', 'Back office');
        $back = $studio->cardReaders()->create(['stripe_reader_id' => 'tmr_2', 'label' => 'Back office']);
        $this->register('simulated-wpe', 'Studio B');
        $third = $studio->cardReaders()->create(['stripe_reader_id' => 'tmr_3', 'label' => 'Studio B']);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->travel(-30)->minutes();
        $this->charge($studio, $reader, [['variant', $tee->id, 1]]);   // tapped, but nobody came back to the page
        $this->charge($studio, $back, [['variant', $tee->id, 1]]);     // walked away
        $this->travelBack();
        $this->charge($studio, $third, [['variant', $tee->id, 1]]);    // still at the reader
        $this->tap('tmr_1', null);
        $this->assertSame(0, $tee->fresh()->stock);

        $this->artisan('loft:housekeeping')->expectsOutputToContain('Reader sales settled: 2')->assertSuccessful();

        $this->assertSame(['paid', 'cancelled', 'pending'], Order::orderBy('id')->pluck('status')->all());
        $this->assertSame([1, null, 'canceled'], [$tee->fresh()->stock, $this->readers['tmr_2']['action'], $this->intents['pi_2']['status']]);
        $this->assertSame(0, app(ReaderPayments::class)->sweep());
    }
}
