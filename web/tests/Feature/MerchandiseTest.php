<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Studio;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Merchandise and the front-desk point of sale (roadmap step 6, first part). */
class MerchandiseTest extends TestCase
{
    private function product(Studio $studio, string $name, int $price, array $variants = ['One size' => null], bool $taxable = true): Product
    {
        $product = $studio->products()->create(['name' => $name, 'price_cents' => $price, 'taxable' => $taxable]);
        foreach ($variants as $variant => $stock) {
            $product->variants()->create(['studio_id' => $studio->id, 'name' => $variant, 'stock' => $stock]);
        }

        return $product->load('variants');
    }

    private function variant(Product $product, string $name = 'One size'): ProductVariant
    {
        return $product->variants->firstWhere('name', $name);
    }

    public function test_managing_products_variants_and_stock(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $base = "/{$studio->slug}/admin/products";
        $this->get($base)->assertOk()->assertSee('No products yet');

        $this->post($base, ['name' => 'Studio Tee', 'price' => '25', 'taxable' => '1', 'active' => '1', 'new_variants' => [
            ['name' => 'Small', 'stock' => '5'], ['name' => 'Medium', 'stock' => '1', 'price' => '27.50'], ['name' => '', 'stock' => ''],
        ]])->assertRedirect();
        $tee = Product::with('variants')->sole();
        $this->assertSame([2500, true, ['Small', 'Medium'], [5, 1], [null, 2750]], [$tee->price_cents, $tee->taxable,
            $tee->variants->pluck('name')->all(), $tee->variants->pluck('stock')->all(), $tee->variants->pluck('price_cents')->all()]);
        $this->get($base)->assertOk()->assertSee('$25.00 – $27.50')->assertSee('Low: Medium (1)');

        $this->post($base, ['name' => 'Tee 2', 'price' => '25', 'new_variants' => [['name' => 'S'], ['name' => 's']]])->assertSessionHasErrors('variants');
        $this->post($base, ['name' => 'Grip Aid', 'price' => '12'])->assertRedirect();
        $this->assertSame(['One size'], Product::where('name', 'Grip Aid')->sole()->variants()->pluck('name')->all(), 'no variants: one, "One size"');

        [$small, $medium] = $tee->variants->all();
        $this->put("{$base}/{$tee->id}", ['name' => 'Studio Tee', 'price' => '25', 'taxable' => '1', 'active' => '1', 'variants' => [
            $small->id => ['name' => 'Small', 'stock' => '8'],
            $medium->id => ['name' => 'Medium', 'stock' => '1', 'remove' => '1'],
        ]])->assertSessionHas('success');
        $this->assertSame([[$small->id, 8]], $tee->variants()->get()->map(fn ($v) => [$v->id, $v->stock])->all());

        // Never sold: deleted. Sold: hidden.
        $this->delete("{$base}/".Product::where('name', 'Grip Aid')->value('id'))->assertRedirect($base);
        $this->assertSame(1, Product::count());
    }

    public function test_selling_products_and_plans_at_the_front_desk(): void
    {
        Mail::fake();
        $studio = $this->studio(['settings' => ['sales_tax_milli' => 8250]]);
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $tee = $this->product($studio, 'Studio Tee', 2500, ['Medium' => 2]);
        $grip = $this->product($studio, 'Grip Aid', 1000, taxable: false);
        $pack = $this->plan($studio, ['name' => '10-Class Pack', 'kind' => 'pack', 'credits' => 10, 'price_cents' => 11000]);
        $this->actingAs($owner);
        $pos = "/{$studio->slug}/admin/pos";
        $add = fn (string $type, int $id) => $this->post("{$pos}/items", ['type' => $type, 'id' => $id])->assertRedirect($pos);

        $this->get($pos)->assertOk()->assertSee('Studio Tee')->assertSee('2 left')->assertSee('10-Class Pack');
        $add('variant', $this->variant($tee, 'Medium')->id);
        $add('variant', $this->variant($tee, 'Medium')->id); // same line, two now
        $add('variant', $this->variant($grip)->id);
        $add('plan', $pack->id);

        // A plan needs a member.
        $this->post("{$pos}/checkout", ['method' => 'cash'])->assertSessionHas('error', 'Plans go on a member’s account: choose the member first.');
        $this->get("{$pos}?q=pat")->assertOk()->assertSee($member->name);
        $this->post("{$pos}/customer", ['member_id' => $member->id])->assertRedirect($pos);

        // $50 of tees taxed at 8.25% ($4.13), $10 grip aid and the $110 pack untaxed.
        $this->get($pos)->assertOk()->assertSee('Studio Tee · Medium')->assertSee('$170.00')->assertSee('Tax (8.25%)')->assertSee('$4.13')
            ->assertSee('Take payment · $174.13');
        $this->post("{$pos}/checkout", ['method' => 'card', 'note' => 'Terminal 1'])->assertRedirect();

        $order = Order::with('items')->sole();
        $this->assertSame([1001, 'in_person', 'card', 17000, 413, 17413, $member->id, $owner->id, 'Terminal 1', false],
            [$order->number, $order->channel, $order->method, $order->subtotal_cents, $order->tax_cents, $order->total_cents, $order->user_id,
                $order->recorded_by_user_id, $order->note, $order->walk_in]);
        $this->assertSame([['product', 'Studio Tee · Medium', 2, 5000, 413], ['product', 'Grip Aid', 1, 1000, 0], ['plan', '10-Class Pack', 1, 11000, 0]],
            $order->items->map(fn ($i) => [$i->kind, $i->description, $i->quantity, $i->total_cents, $i->tax_cents])->all());
        $this->assertSame(0, $this->variant($tee, 'Medium')->fresh()->stock);
        $credits = Entitlement::where('user_id', $member->id)->sole();
        $this->assertSame([10, $credits->id], [$credits->credits_remaining, $order->items[2]->entitlement_id]);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'receipt' && $m->hasTo('pat@example.com')
            && $m->details['Studio Tee · Medium ×2'] === '$50.00' && $m->details['Tax'] === '$4.13' && $m->details['Total'] === '$174.13 · Card, in person');
        $this->get("{$pos}/orders/{$order->id}")->assertOk()->assertSee('Receipt #1001')->assertSee('$174.13')->assertSee('A copy was emailed to pat@example.com');
        $this->get($pos)->assertOk()->assertSee('Empty. Tap a product')->assertSee('Sold out');

        // Out of stock: nothing is recorded, nothing granted.
        $this->variant($tee, 'Medium')->update(['stock' => 1]);
        $add('variant', $this->variant($tee, 'Medium')->id);
        $this->post("{$pos}/items/0", ['qty' => 3]);
        $add('plan', $pack->id);
        $this->post("{$pos}/customer", ['member_id' => $member->id]);
        $this->post("{$pos}/checkout", ['method' => 'cash'])->assertSessionHas('error', 'Only 1 of Studio Tee · Medium left.');
        $this->assertSame([1, 1, 1], [Order::count(), Entitlement::count(), $this->variant($tee, 'Medium')->fresh()->stock]);

        // A walk-in, comped: no account, no email, nothing charged.
        $this->post("{$pos}/clear");
        $add('variant', $this->variant($grip)->id);
        $this->post("{$pos}/customer", ['walk_in' => '1']);
        $this->post("{$pos}/checkout", ['method' => 'comp'])->assertRedirect();
        $walkIn = Order::latest('id')->first();
        $this->assertSame([true, null, 0, 'comp'], [$walkIn->walk_in, $walkIn->user_id, $walkIn->total_cents, $walkIn->method]);
        $this->assertSame(1, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'receipt')->count());
        $this->get("/{$studio->slug}/admin/sales")->assertOk()->assertSee('Walk-in')->assertSee('Studio Tee · Medium ×2 + Grip Aid + 10-Class Pack');
        $this->get("/{$studio->slug}/admin/payments")->assertOk()->assertSee('$174.13')->assertSee('Walk-in');
    }

    public function test_voiding_a_sale_restocks_and_cancels_its_plans(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio);
        $tee = $this->product($studio, 'Studio Tee', 2500, ['Small' => 4]);
        $pack = $this->plan($studio, ['kind' => 'pack', 'credits' => 5, 'price_cents' => 9000]);
        $this->actingAs($owner);
        $pos = "/{$studio->slug}/admin/pos";
        $this->post("{$pos}/items", ['type' => 'variant', 'id' => $this->variant($tee, 'Small')->id]);
        $this->post("{$pos}/items/0", ['qty' => 3]);
        $this->post("{$pos}/items", ['type' => 'plan', 'id' => $pack->id]);
        $this->post("{$pos}/customer", ['member_id' => $member->id]);
        $this->post("{$pos}/checkout", ['method' => 'cash']);
        $order = Order::sole();
        $this->assertSame(1, $this->variant($tee, 'Small')->fresh()->stock);

        $this->post("/{$studio->slug}/admin/orders/{$order->id}/void")->assertSessionHas('success', 'Sale #1001 voided: plans it added were cancelled and products went back into stock.');
        $this->assertSame(['void', $owner->id], [$order->fresh()->status, $order->fresh()->voided_by_user_id]);
        $this->assertSame(4, $this->variant($tee, 'Small')->fresh()->stock);
        $this->assertSame('cancelled', Entitlement::sole()->status);
        $this->assertSame(['entitlement.ended', 'order.voided'], DomainEvent::whereIn('type', ['entitlement.ended', 'order.voided'])->orderBy('id')->pluck('type')->all());
        $this->post("/{$studio->slug}/admin/orders/{$order->id}/void")->assertSessionHas('error', 'That sale is already void.');
        $this->assertSame(4, $this->variant($tee, 'Small')->fresh()->stock, 'not restocked twice');

        // Online sales are refunded, not voided; instructors can't use the till.
        $online = Order::create(['studio_id' => $studio->id, 'number' => 2000, 'channel' => 'online', 'method' => 'stripe', 'status' => 'paid',
            'currency' => 'usd', 'subtotal_cents' => 100, 'total_cents' => 100, 'paid_at' => now()]);
        $this->post("/{$studio->slug}/admin/orders/{$online->id}/void")->assertSessionHas('error', 'Online sales are refunded, not voided.');
        $this->actingAs($this->userIn($studio, 'instructor'))->get($pos)->assertForbidden();
    }

    public function test_the_sales_tax_rate_is_a_setting(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $valid = [
            'name' => $studio->name, 'accent_color' => '#0f766e', 'timezone' => 'America/Denver', 'currency' => 'usd',
            'late_cancel_hours' => 12, 'booking_opens_days_before' => 30, 'booking_closes_minutes_before' => 0, 'credit_validity_days' => 90,
            'waiver_validity_days' => 365, 'reminder_hours_before' => 24, 'minor_age' => 18, 'schedule_weeks' => 2,
        ];
        $this->put("/{$studio->slug}/admin/settings", $valid + ['sales_tax_percent' => '45'])->assertSessionHasErrors('sales_tax_percent');
        $this->put("/{$studio->slug}/admin/settings", $valid + ['sales_tax_percent' => '8.875'])->assertSessionHas('success');
        $this->assertSame([8875, 8.875], [$studio->fresh()->setting('sales_tax_milli'), $studio->fresh()->salesTaxPercent()]);
        $this->get("/{$studio->slug}/admin/settings")->assertOk()->assertSee('value="8.875"', false);
    }
}
