<?php

namespace Tests\Feature;

use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\Studio;
use App\Models\User;
use App\Services\Entitlements;
use App\Services\OfflinePayments;
use App\Services\Orders;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** The orders ledger (roadmap step 5). Stripe's side is in StripeWebhookTest. */
class OrdersTest extends TestCase
{
    /** An online one-time sale, as the webhook records it. */
    private function onlineSale(Studio $studio, User $member, int $total, string $checkoutId): Order
    {
        $plan = $this->plan($studio, ['name' => 'Drop-in', 'kind' => 'dropin', 'price_cents' => $total]);
        $entitlement = app(Entitlements::class)->grantFromPlan($plan, $member->id, ['checkout_session_id' => $checkoutId]);

        return app(Orders::class)->recordCheckout($studio, ['id' => $checkoutId, 'mode' => 'payment', 'amount_total' => $total, 'currency' => 'usd'], $entitlement);
    }

    public function test_payments_taken_in_person_are_orders_and_void_together(): void
    {
        Mail::fake();
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio);
        $pack = $this->plan($studio, ['name' => '10-Class Pack', 'kind' => 'pack', 'credits' => 10, 'price_cents' => 15000]);
        $payments = app(OfflinePayments::class);

        $cash = $payments->record($pack, $member, $owner, ['method' => 'cash', 'amount_cents' => 14000, 'paid_on' => $studio->localNow()->toDateString()]);
        $comp = $payments->record($pack, $member, $owner, ['method' => 'comp', 'paid_on' => $studio->localNow()->subDays(3)->toDateString()]);

        $order = $cash->order()->with('items')->sole();
        $this->assertSame([1001, 'in_person', 'cash', 14000, 'paid', $owner->id, $member->id], [$order->number, $order->channel, $order->method,
            $order->total_cents, $order->status, $order->recorded_by_user_id, $order->user_id]);
        $this->assertSame([['plan', '10-Class Pack', $cash->entitlement_id]], $order->items->map(fn ($i) => [$i->kind, $i->description, $i->entitlement_id])->all());
        $this->assertSame([1002, 'comp', 0], [$comp->order->number, $comp->order->method, $comp->order->total_cents]);
        $this->assertSame('12:00', $studio->local($comp->order->paid_at)->format('H:i'), 'an earlier day is dated at noon, studio time');

        $this->actingAs($owner)->post("/{$studio->slug}/admin/payments/{$cash->id}/void")->assertSessionHas('success');
        $this->assertSame(['void', $owner->id], [$order->fresh()->status, $order->fresh()->voided_by_user_id]);
        $this->assertSame(['order.paid', 'order.paid', 'order.voided'], DomainEvent::where('type', 'like', 'order.%')->orderBy('id')->pluck('type')->all());
        $this->assertSame('Paid $140.00 for 10-Class Pack (#1001)', DomainEvent::where('type', 'order.paid')->first()->label());
    }

    public function test_numbers_are_per_studio_and_a_sale_is_recorded_once(): void
    {
        $a = $this->studio();
        $b = $this->studio();
        $this->assertSame(1001, $this->onlineSale($a, $this->userIn($a), 2500, 'cs_a1')->number);
        $this->assertSame(1002, $this->onlineSale($a, $this->userIn($a), 2500, 'cs_a2')->number);
        $this->assertSame(1001, $this->onlineSale($b, $this->userIn($b), 2500, 'cs_b1')->number);

        $order = Order::where('stripe_checkout_session_id', 'cs_a1')->sole();
        $again = app(Orders::class)->recordCheckout($a, ['id' => 'cs_a1', 'mode' => 'payment', 'amount_total' => 2500], $order->items()->sole()->entitlement);
        $this->assertTrue($again->is($order));
        $this->assertNull(app(Orders::class)->recordCheckout($a, ['id' => 'cs_sub', 'mode' => 'subscription'], $order->items()->sole()->entitlement), 'subscriptions are recorded from invoices');
        $this->assertSame(3, Order::count());
    }

    public function test_the_sales_page_totals_a_month_and_downloads_it(): void
    {
        Mail::fake();
        $studio = $this->studio(['timezone' => 'America/Denver', 'currency' => 'usd']);
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio, 'member', ['first_name' => '=SUM(A1)', 'last_name' => 'Lee']);
        $plan = $this->plan($studio, ['name' => 'Monthly', 'kind' => 'subscription', 'price_cents' => 9000]);

        $this->travelTo($studio->parseLocal('2026-10-20 12:00'));
        $this->onlineSale($studio, $member, 2500, 'cs_october');
        $this->travelTo($studio->parseLocal('2026-11-08 12:00'));
        $this->onlineSale($studio, $member, 3000, 'cs_november');
        $payments = app(OfflinePayments::class);
        $payments->record($plan, $member, $owner, ['method' => 'check', 'paid_on' => '2026-11-08', 'note' => 'Check #1042']);
        $payments->record($plan, $member, $owner, ['method' => 'comp', 'paid_on' => '2026-11-08']);
        $voided = $payments->record($plan, $member, $owner, ['method' => 'cash', 'amount_cents' => 5000, 'paid_on' => '2026-11-08']);
        $payments->void($voided, $owner);
        $this->actingAs($owner);

        $this->get("/{$studio->slug}/admin/sales")->assertOk()
            ->assertSee('November 2026')->assertSee('$120.00')->assertSee('$30.00')->assertSee('$90.00')
            ->assertSee('<strong>2</strong><span>Number of sales, plus 1 comp</span>', false)->assertSee('Check #1042')->assertSee('Void')->assertDontSee('#1001');
        $this->get("/{$studio->slug}/admin/sales?month=2026-10")->assertOk()->assertSee('#1001')->assertSee('$25.00');
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('Sales this month')->assertSee('$120.00');
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()->assertSee('Purchases')->assertSee('#1004');

        $csv = $this->get("/{$studio->slug}/admin/sales/export?month=2026-11")->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=sales-'.$studio->slug.'-2026-11.csv')->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame(['Order', 'Date', 'Time zone', 'Member'], array_slice($rows[0], 0, 4));
        $this->assertCount(5, $rows); // header + 4 orders, the voided one included
        $this->assertSame(['1002', '2026-11-08 12:00', 'America/Denver', "'=SUM(A1) Lee"], array_slice($rows[1], 0, 4), 'formulas are escaped');
        $this->assertSame(['Online (Stripe)', '30.00', '30.00', 'USD', 'Paid'], [$rows[1][7], $rows[1][8], $rows[1][11], $rows[1][12], $rows[1][13]]);
        $this->assertSame('Void', $rows[4][13]);

        // Members see their purchases.
        $this->actingAs($member)->get("/{$studio->slug}/account/billing")->assertOk()->assertSee('Purchases')->assertSee('Order #1003')->assertSee('$30.00');
    }
}
