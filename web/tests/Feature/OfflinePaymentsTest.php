<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Entitlement;
use App\Models\Payment;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OfflinePaymentsTest extends TestCase
{
    public function test_staff_record_a_cash_payment_and_the_member_gets_a_receipt(): void
    {
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(18, 0));
        $studio = $this->studio(['timezone' => 'America/Denver', 'currency' => 'usd']);
        $plan = $this->plan($studio, ['name' => 'Monthly Unlimited', 'kind' => 'subscription', 'price_cents' => 9000]);
        $member = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner);

        $this->post("/{$studio->slug}/admin/members/{$member->id}/plans", [
            'plan_id' => $plan->id, 'method' => 'cash', 'amount' => '', 'paid_on' => '2026-11-08', 'note' => 'Front desk',
        ])->assertSessionHas('success', "Recorded \$90.00 (Cash) and added “Monthly Unlimited” for {$member->name}.");

        $payment = Payment::sole();
        $this->assertSame([9000, 'cash', 'usd', '2026-11-08', 'Front desk', $owner->id], [
            $payment->amount_cents, $payment->method, $payment->currency, $payment->paid_on->toDateString(), $payment->note, $payment->recorded_by_user_id,
        ]);
        // A membership paid in person runs a month: through Dec 8, studio time.
        $entitlement = Entitlement::sole();
        $this->assertSame($payment->entitlement_id, $entitlement->id);
        $this->assertSame('2026-12-08 23:59', $studio->local($entitlement->expires_at)->format('Y-m-d H:i'));
        $this->assertSame('active', $entitlement->status);

        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->hasTo('pat@example.com')
            && $m->headline === 'Thanks for your purchase'
            && $m->details === ['Paid' => '$90.00 · Cash', 'Date' => 'Nov 8, 2026']);

        // It shows on the member's page and on Payments, with this month's total.
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()
            ->assertSee('Payments recorded')->assertSee('paid in person (cash)')->assertSee('Front desk')
            ->assertDontSee('stripe_subscription_id'); // no template code leaking into the page
        $this->get("/{$studio->slug}/admin/payments")->assertOk()
            ->assertSee('$90.00')->assertSee('Taken in person this month')->assertSee($member->name);
    }

    public function test_amounts_end_dates_and_comps(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $pack = $this->plan($studio, ['name' => 'Ten pack', 'kind' => 'pack', 'credits' => 10, 'price_cents' => 20000, 'credit_validity_days' => 90]);
        $member = $this->userIn($studio);
        $this->actingAs($this->userIn($studio, 'admin'));
        $url = "/{$studio->slug}/admin/members/{$member->id}/plans";
        $endsOn = now()->addDays(10)->toDateString();

        // A typed amount and a "valid until" date override the plan's price and validity.
        $this->post($url, ['plan_id' => $pack->id, 'method' => 'check', 'amount' => '150.50', 'ends_on' => $endsOn])->assertSessionHas('success');
        $check = Payment::latest('id')->first();
        $this->assertSame(15050, $check->amount_cents);
        $this->assertSame($endsOn, $studio->local($check->entitlement->expires_at)->toDateString());

        // A comp: no charge, no email, and a membership with no end, as before.
        $membership = $this->plan($studio, ['kind' => 'subscription']);
        $this->post($url, ['plan_id' => $membership->id, 'method' => 'comp', 'amount' => '99'])->assertSessionHas('success');
        $comp = Payment::latest('id')->first();
        $this->assertSame([0, 'comp'], [$comp->amount_cents, $comp->method]);
        $this->assertNull($comp->entitlement->expires_at);
        Mail::assertQueuedCount(1); // only the check's receipt

        // Validation: plans from other studios, unknown methods, negative amounts, past end dates.
        $other = $this->plan($this->studio());
        $this->post($url, ['plan_id' => $other->id, 'method' => 'cash'])->assertSessionHasErrors('plan_id');
        $this->post($url, ['plan_id' => $pack->id, 'method' => 'barter'])->assertSessionHasErrors('method');
        $this->post($url, ['plan_id' => $pack->id, 'method' => 'cash', 'amount' => '-5'])->assertSessionHasErrors('amount');
        $this->post($url, ['plan_id' => $pack->id, 'method' => 'cash', 'ends_on' => now()->subDays(2)->toDateString()])->assertSessionHasErrors('ends_on');
        $this->assertSame(2, Payment::count());
    }

    public function test_voiding_cancels_the_plan_and_keeps_the_record(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5, 'price_cents' => 10000]);
        $member = $this->userIn($studio);
        $admin = $this->userIn($studio, 'admin');
        $this->actingAs($admin);
        $this->post("/{$studio->slug}/admin/members/{$member->id}/plans", ['plan_id' => $plan->id, 'method' => 'card']);
        $payment = Payment::sole();

        $this->post("/{$studio->slug}/admin/payments/{$payment->id}/void")->assertSessionHas('success');
        $payment->refresh();
        $this->assertNotNull($payment->voided_at);
        $this->assertSame($admin->id, $payment->voided_by_user_id);
        $this->assertSame('cancelled', $payment->entitlement->status);
        $this->post("/{$studio->slug}/admin/payments/{$payment->id}/void")->assertSessionHas('error');

        // Voided payments don't count toward the month.
        $this->get("/{$studio->slug}/admin/payments")->assertOk()->assertSee('Void')->assertViewHas('monthTotal', 0);

        // Another studio's payments can't be voided from here.
        $elsewhere = $this->studio();
        $this->actingAs($this->userIn($elsewhere, 'owner'))->post("/{$elsewhere->slug}/admin/payments/{$payment->id}/void")->assertNotFound();
    }

    public function test_instructors_cant_record_payments_and_records_outlive_deleted_accounts(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $plan = $this->plan($studio, ['kind' => 'dropin', 'price_cents' => 2500]);
        $member = $this->userIn($studio);

        $this->actingAs($this->userIn($studio, 'instructor'))
            ->post("/{$studio->slug}/admin/members/{$member->id}/plans", ['plan_id' => $plan->id, 'method' => 'cash'])->assertForbidden();

        $this->actingAs($this->userIn($studio, 'owner'))
            ->post("/{$studio->slug}/admin/members/{$member->id}/plans", ['plan_id' => $plan->id, 'method' => 'cash'])->assertSessionHas('success');
        $member->delete();

        // The studio's books keep the amount; the member's link is gone.
        $payment = Payment::sole();
        $this->assertSame([2500, null, null], [$payment->amount_cents, $payment->user_id, $payment->entitlement_id]);
        $this->get("/{$studio->slug}/admin/payments")->assertOk()->assertSee('Deleted account')->assertSee('$25.00');
    }
}
