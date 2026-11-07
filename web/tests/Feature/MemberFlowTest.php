<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\User;
use App\Models\WaiverSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** The member side of a studio, through HTTP: join → family → waiver → book → pay → cancel. */
class MemberFlowTest extends TestCase
{
    public function test_public_pages_render(): void
    {
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $program = $this->program($studio, ['title' => 'Kids Silks', 'description' => "Line one\n\n<b>Line two</b>"]);
        $this->classSession($program, ['starts_at' => now()->addDay()->setTime(17, 0)]);
        $this->plan($studio, ['name' => 'Ten Pack']);

        $this->get("/{$studio->slug}")->assertOk()->assertSee('Kids Silks')->assertSee('Sign in to book')->assertSee('10 spots left');
        $this->get("/{$studio->slug}/classes/{$program->id}")->assertOk()->assertSee('Line one')->assertSee('&lt;b&gt;Line two&lt;/b&gt;', false);
        $this->get("/{$studio->slug}/plans")->assertOk()->assertSee('Ten Pack')->assertSee('Coming soon');
        $this->get("/{$studio->slug}/join")->assertOk()->assertSee('Create your account');
        $this->get("/{$studio->slug}/login")->assertOk()->assertSee('Skyline Aerial');
        $this->get('/no-such-studio')->assertNotFound();
    }

    public function test_schedule_filters_by_age_group(): void
    {
        $studio = $this->studio();
        $kids = $this->program($studio, ['title' => 'Kids Class']);
        $kids->categories()->attach($studio->categories()->where('type', 'age_group')->where('slug', 'kids')->value('id'));
        $this->classSession($kids, ['starts_at' => now()->addDay()]);
        $this->classSession($this->program($studio, ['title' => 'Adult Class']), ['starts_at' => now()->addDay()]);

        $this->get("/{$studio->slug}?group=kids")->assertOk()->assertSee('Kids Class')->assertDontSee('Adult Class');
    }

    public function test_joining_creates_an_account_membership_and_self_attendee(): void
    {
        Mail::fake();
        $studio = $this->studio();

        $this->post("/{$studio->slug}/join", [
            'first_name' => 'Alex', 'last_name' => 'Kim', 'email' => 'Alex@Example.com', 'password' => 'secret-pass', 'is_student' => '1',
        ])->assertRedirect("/{$studio->slug}/account/family");

        $user = User::where('email', 'alex@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRoleIn($studio, 'member'));
        $this->assertTrue(Attendee::where('user_id', $user->id)->sole()->is_self);
        Mail::assertQueued(StudioMessage::class, fn ($m) => $m->kind === 'welcome' && $m->hasTo('alex@example.com'));
    }

    public function test_signed_in_users_join_another_studio_in_one_click_and_return_where_they_were(): void
    {
        $studio = $this->studio();
        $user = User::factory()->create();
        $session = $this->classSession($this->program($studio));

        $this->actingAs($user)->get("/{$studio->slug}/book/{$session->id}")->assertRedirect("/{$studio->slug}/join");
        $this->post("/{$studio->slug}/join")->assertRedirect("/{$studio->slug}/book/{$session->id}");
        $this->assertTrue($user->fresh()->hasRoleIn($studio));
    }

    public function test_guests_are_sent_to_the_studio_login(): void
    {
        $studio = $this->studio();
        $this->get("/{$studio->slug}/account")->assertRedirect("/{$studio->slug}/login");
    }

    public function test_family_and_profile_management(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->actingAs($user);

        $this->post("/{$studio->slug}/account/family", ['first_name' => 'Maya', 'last_name' => 'Shah', 'dob' => '2017-03-04', 'medical_notes' => 'Asthma'])
            ->assertRedirect("/{$studio->slug}/account/family");
        $maya = Attendee::where('first_name', 'Maya')->sole();
        $this->get("/{$studio->slug}/account/family")->assertOk()->assertSee('Maya Shah');
        $this->put("/{$studio->slug}/account/family/{$maya->id}", ['first_name' => 'Maya', 'last_name' => 'Patel'])->assertRedirect();
        $this->assertSame('Patel', $maya->fresh()->last_name);
        $this->post("/{$studio->slug}/account/family/{$maya->id}/archive")->assertRedirect();
        $this->assertSame('archived', $maya->fresh()->status);

        $this->put("/{$studio->slug}/account/profile", ['first_name' => 'New', 'last_name' => 'Name', 'email' => $user->email, 'phone' => '555'])->assertRedirect();
        $this->assertSame('New', $user->fresh()->first_name);
        foreach (['account', 'account/history', 'account/billing', 'account/waivers', 'account/profile', 'account/family/new'] as $page) {
            $this->get("/{$studio->slug}/{$page}")->assertOk();
        }
    }

    public function test_booking_with_credits_then_cancelling(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 3);
        $attendee = $this->attendee($studio, $user, ['first_name' => 'Maya']);
        $session = $this->classSession($this->program($studio, ['title' => 'Silks']), ['starts_at' => now()->addDays(2)]);
        $this->actingAs($user);

        $this->get("/{$studio->slug}/book/{$session->id}")->assertOk()->assertSee('Who’s coming?')->assertSee('Maya');
        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id])
            ->assertRedirect("/{$studio->slug}/account")
            ->assertSessionHas('success', 'Maya is booked for Silks!');
        $this->get("/{$studio->slug}/account")->assertOk()->assertSee('Silks')->assertSee('Booked');
        $this->get("/{$studio->slug}")->assertSee('Maya: booked');

        $booking = Booking::sole();
        $this->post("/{$studio->slug}/bookings/{$booking->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(3, $credits->fresh()->credits_remaining);
    }

    public function test_waiver_gate_and_signing(): void
    {
        Storage::fake('local');
        $studio = $this->studio();
        $this->waiver($studio, ['title' => 'Release', 'body' => "Para one.\n\nPara two."]);
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $attendee = $this->attendee($studio, $user, ['dob' => now()->subYears(9)]);
        $session = $this->classSession($this->program($studio));
        $this->actingAs($user);

        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id])
            ->assertRedirect("/{$studio->slug}/waiver/{$attendee->id}?return=%2F{$studio->slug}%2Fbook%2F{$session->id}");
        $this->get("/{$studio->slug}/waiver/{$attendee->id}")->assertOk()->assertSee('Para two.')->assertSee('Parent or guardian');

        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 20));
        $this->post("/{$studio->slug}/waiver/{$attendee->id}", ['signed_name' => 'Priya Shah', 'agree' => '1', 'signature_png' => $png, 'return' => "/{$studio->slug}/book/{$session->id}"])
            ->assertRedirect("/{$studio->slug}/book/{$session->id}");
        $signature = WaiverSignature::sole();
        $this->assertSame('Priya Shah', $signature->signed_name);
        Storage::disk('local')->assertExists($signature->signature_path);

        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id])->assertRedirect("/{$studio->slug}/account");
    }

    public function test_waiver_rejects_offsite_return_urls(): void
    {
        $studio = $this->studio();
        $this->waiver($studio);
        $user = $this->userIn($studio);
        $attendee = $this->attendee($studio, $user);

        $this->actingAs($user)->post("/{$studio->slug}/waiver/{$attendee->id}", ['signed_name' => 'Pat Doe', 'agree' => '1', 'return' => '//evil.example/x'])
            ->assertRedirect("/{$studio->slug}/account/waivers");
    }

    public function test_no_plan_shows_eligible_plans_and_checkout_goes_to_stripe(): void
    {
        $this->fakeStripe([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $attendee = $this->attendee($studio, $user);
        $session = $this->classSession($this->program($studio));
        $plan = $this->plan($studio, ['name' => 'Drop-in', 'kind' => 'dropin', 'credits' => 1, 'price_cents' => 2500, 'stripe_price_id' => 'price_d']);
        $this->actingAs($user);

        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id])
            ->assertOk()->assertSee('Choose a plan')->assertSee('Drop-in')->assertSee('$25.00');

        $this->post("/{$studio->slug}/checkout/{$plan->id}", ['session_id' => $session->id, 'attendee_id' => $attendee->id])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions')
            && $r['metadata']['session_id'] == $session->id
            && str_contains($r['success_url'], "/{$studio->slug}/checkout/complete?session_id={CHECKOUT_SESSION_ID}"));
    }

    public function test_returning_from_checkout_fulfils_immediately(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $user = $this->userIn($studio);
        $attendee = $this->attendee($studio, $user, ['first_name' => 'Leo']);
        $session = $this->classSession($this->program($studio, ['title' => 'Trapeze']));
        $plan = $this->plan($studio, ['kind' => 'dropin', 'credits' => 1]);
        $this->fakeStripe(['api.stripe.com/v1/checkout/sessions/cs_ok' => Http::response([
            'id' => 'cs_ok', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid',
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $user->id, 'session_id' => $session->id, 'attendee_id' => $attendee->id],
        ])]);

        $this->actingAs($user)->get("/{$studio->slug}/checkout/complete?session_id=cs_ok")
            ->assertRedirect("/{$studio->slug}/account")
            ->assertSessionHas('success', 'Payment received — Leo is booked for Trapeze.');
        $this->assertSame('booked', Booking::sole()->status);

        // Someone else's checkout session can't be claimed.
        $intruder = $this->userIn($studio);
        $this->actingAs($intruder)->get("/{$studio->slug}/checkout/complete?session_id=cs_ok")->assertNotFound();
    }

    public function test_checkout_is_refused_until_the_studio_connects_stripe(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $plan = $this->plan($studio, ['stripe_price_id' => 'price_x']);

        $this->actingAs($user)->from("/{$studio->slug}/plans")->post("/{$studio->slug}/checkout/{$plan->id}")
            ->assertRedirect("/{$studio->slug}/plans")->assertSessionHas('error');
    }
}
