<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Program;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Bookings;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Studio staff screens (the plugin's wp-admin pages), through HTTP. */
class AdminFlowTest extends TestCase
{
    public function test_every_admin_page_renders(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio);
        $this->attendee($studio, $member);
        $program = $this->program($studio);
        $session = $this->classSession($program, ['starts_at' => $studio->localNow()->setTime(18, 0)->utc()]);
        $season = $studio->seasons()->create(['name' => 'Fall', 'starts_on' => '2026-09-01', 'ends_on' => '2027-05-31']);
        $plan = $this->plan($studio);
        $waiver = $this->waiver($studio);
        $this->actingAs($owner);

        foreach ([
            'admin', 'admin/checkin', 'admin/sessions', "admin/sessions/{$session->id}", 'admin/programs', 'admin/programs/create',
            "admin/programs/{$program->id}/edit", 'admin/seasons', "admin/seasons/{$season->id}/edit", 'admin/plans', 'admin/plans/create',
            "admin/plans/{$plan->id}/edit", 'admin/members', "admin/members/{$member->id}", 'admin/waivers', 'admin/waivers/create',
            "admin/waivers/{$waiver->id}/edit", 'admin/staff', 'admin/payments', 'admin/settings',
        ] as $page) {
            $this->get("/{$studio->slug}/{$page}")->assertOk();
        }
    }

    public function test_creating_a_program_generates_its_sessions(): void
    {
        $studio = $this->studio();
        $season = $studio->seasons()->create(['name' => 'Now', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(4)->subDay()->toDateString(), 'is_current' => true]);
        $instructor = $this->userIn($studio, 'instructor');
        $kids = $studio->categories()->where('type', 'age_group')->where('slug', 'kids')->value('id');
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/programs", [
            'title' => 'Kids Silks', 'type' => 'class', 'weekday' => now()->addDay()->dayOfWeek, 'start_time' => '16:30', 'duration_min' => 60,
            'capacity' => 8, 'season_id' => $season->id, 'instructor_id' => $instructor->id, 'dropin_price' => '25',
            'categories' => [$kids], 'new_categories' => ['plan_tag' => 'recreational, Summer Camp'], 'is_published' => '1',
        ])->assertRedirect()->assertSessionHas('success', 'Saved. 4 sessions created.');

        $program = Program::sole();
        $this->assertSame(2500, $program->dropin_price_cents);
        $this->assertEqualsCanonicalizing(['kids', 'recreational', 'summer-camp'], $program->categories->pluck('slug')->all());
        $this->assertSame([8], $program->sessions->pluck('capacity')->unique()->values()->all());
        $this->assertSame($instructor->id, $program->sessions->first()->instructor_id);

        $this->post("/{$studio->slug}/admin/programs", ['title' => 'Bad', 'type' => 'class', 'start_time' => '25:00', 'duration_min' => 60, 'capacity' => 8])
            ->assertSessionHasErrors(['weekday', 'start_time']);
    }

    public function test_roster_capacity_comp_and_removal(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        $first = $this->userIn($studio);
        $this->credits($studio, $first);
        $seat = app(Bookings::class)->book($session, $this->attendee($studio, $first), $first->id);
        $waiter = $this->userIn($studio);
        $this->credits($studio, $waiter);
        $waiting = app(Bookings::class)->book($session, $this->attendee($studio, $waiter), $waiter->id);
        $walkIn = $this->userIn($studio);
        $walkInKid = $this->attendee($studio, $walkIn, ['first_name' => 'Walk']);
        $this->actingAs($this->userIn($studio, 'admin'));
        $base = "/{$studio->slug}/admin/sessions/{$session->id}";

        $this->get($base)->assertOk()->assertSee($seat->attendee->name)->assertSee('Waitlist');
        $this->post("{$base}/bookings", ['attendee_id' => $walkInKid->id])->assertSessionHas('error');
        $this->post("{$base}/bookings", ['attendee_id' => $walkInKid->id, 'comp' => '1'])->assertSessionHas('success');

        $this->patch($base, ['capacity' => 3])->assertSessionHas('success', 'Session updated. 2 moved in from the waitlist.');
        $this->assertSame('booked', $waiting->fresh()->status);

        $this->post("/{$studio->slug}/admin/bookings/{$seat->id}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $seat->fresh()->status);

        $this->post("{$base}/cancel")->assertSessionHas('success');
        $this->assertSame('cancelled', $session->fresh()->status);
        Mail::assertQueued(StudioMessage::class, fn ($m) => $m->kind === 'session_cancelled');
        $this->delete($base)->assertSessionHas('error');
    }

    public function test_checkin_marks_attendance(): void
    {
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['starts_at' => now()->addMinutes(30)]);
        $user = $this->userIn($studio);
        $this->membership($studio, $user);
        $booking = app(Bookings::class)->book($session, $this->attendee($studio, $user, ['medical_notes' => 'Peanut allergy']), $user->id);
        $this->actingAs($this->userIn($studio, 'instructor'));

        $this->get("/{$studio->slug}/admin/checkin")->assertOk()->assertSee('Peanut allergy');
        $this->post("/{$studio->slug}/admin/checkin/{$booking->id}", ['status' => 'attended'])->assertRedirect();
        $this->assertSame('attended', $booking->fresh()->status);
        $this->post("/{$studio->slug}/admin/checkin/{$booking->id}", ['status' => 'booked']);
        $this->assertSame('booked', $booking->fresh()->status);
    }

    public function test_plans_are_created_and_pushed_to_stripe(): void
    {
        $this->fakeStripe([
            'api.stripe.com/v1/products' => Http::response(['id' => 'prod_1']),
            'api.stripe.com/v1/prices' => Http::response(['id' => 'price_1']),
        ]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/plans", [
            'name' => 'Monthly', 'kind' => 'subscription', 'price' => '90', 'weekly_limit' => '2', 'scope_all' => '0', 'scope' => ['recreational'], 'active' => '1',
            'credits' => '9', // ignored for memberships
        ])->assertRedirect("/{$studio->slug}/admin/plans")->assertSessionHas('success', 'Plan created. Connected to Stripe.');

        $plan = Plan::sole();
        $this->assertSame([9000, 'month', 2, null, ['recreational'], 'price_1'], [$plan->price_cents, $plan->billing_interval, $plan->weekly_limit, $plan->credits, $plan->program_scope, $plan->stripe_price_id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/prices') && $r->header('Stripe-Account') === ['acct_A']);

        // A new price needs a new Stripe price.
        $this->put("/{$studio->slug}/admin/plans/{$plan->id}", ['name' => 'Monthly', 'kind' => 'subscription', 'price' => '95', 'scope_all' => '1', 'active' => '1']);
        $this->assertSame(9500, $plan->fresh()->price_cents);
        $this->assertNull($plan->fresh()->program_scope);
        $this->assertSame('price_1', $plan->fresh()->stripe_price_id);
        Http::assertSentCount(3); // product + price, then only a new price (the product is reused)

        $this->post("/{$studio->slug}/admin/plans", ['name' => 'Pack', 'kind' => 'pack', 'price' => '100'])->assertSessionHasErrors('credits');
    }

    public function test_plans_members_hold_are_hidden_not_deleted_and_comps_work(): void
    {
        $studio = $this->studio();
        $plan = $this->plan($studio, ['name' => 'Ten pack', 'credits' => 10]);
        $member = $this->userIn($studio);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/members/{$member->id}/comp", ['plan_id' => $plan->id])->assertSessionHas('success');
        $this->assertSame(10, Entitlement::where('user_id', $member->id)->sole()->credits_remaining);
        $this->get("/{$studio->slug}/admin/members?q=".urlencode(strtoupper($member->last_name)))->assertOk()->assertSee($member->email);

        $this->delete("/{$studio->slug}/admin/plans/{$plan->id}")->assertRedirect();
        $this->assertFalse($plan->fresh()->active);
    }

    public function test_seasons_and_waiver_versions(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/seasons", ['name' => 'One', 'starts_on' => '2026-09-01', 'ends_on' => '2027-05-31', 'is_current' => '1']);
        $this->post("/{$studio->slug}/admin/seasons", ['name' => 'Two', 'starts_on' => '2027-09-01', 'ends_on' => '2028-05-31', 'is_current' => '1']);
        $this->assertSame('Two', $studio->currentSeason()->name);
        $this->assertSame(1, $studio->seasons()->where('is_current', true)->count());
        $this->post("/{$studio->slug}/admin/seasons", ['name' => 'Bad', 'starts_on' => '2027-09-01', 'ends_on' => '2027-01-01'])->assertSessionHasErrors('ends_on');

        $this->post("/{$studio->slug}/admin/waivers", ['title' => 'Release', 'body' => 'Text', 'is_active' => '1']);
        $waiver = Waiver::sole();
        $this->assertTrue($waiver->is_active);
        $this->put("/{$studio->slug}/admin/waivers/{$waiver->id}", ['title' => 'Release', 'body' => 'Typo fixed', 'is_active' => '1', 'new_version' => '0']);
        $this->assertSame(1, $waiver->fresh()->version);
        $this->put("/{$studio->slug}/admin/waivers/{$waiver->id}", ['title' => 'Release', 'body' => 'New terms', 'is_active' => '1', 'new_version' => '1']);
        $this->assertSame(2, $waiver->fresh()->version);
    }

    public function test_staff_invites_and_roles(): void
    {
        Notification::fake();
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $existing = $this->userIn($studio, 'member');
        $this->actingAs($owner);

        $this->post("/{$studio->slug}/admin/staff", ['email' => 'new@example.com', 'role' => 'instructor'])->assertSessionHasErrors('first_name');
        $this->post("/{$studio->slug}/admin/staff", ['email' => 'new@example.com', 'first_name' => 'Cam', 'last_name' => 'Rivera', 'role' => 'instructor'])->assertSessionHas('success');
        $cam = User::where('email', 'new@example.com')->sole();
        $this->assertTrue($cam->hasRoleIn($studio, 'instructor'));
        Notification::assertSentTo($cam, ResetPassword::class);

        $this->post("/{$studio->slug}/admin/staff", ['email' => $existing->email, 'role' => 'admin'])->assertSessionHas('success');
        $this->assertTrue($existing->fresh()->hasRoleIn($studio, 'admin'));

        $ownerRow = $studio->studioUsers()->where('user_id', $owner->id)->sole();
        $this->delete("/{$studio->slug}/admin/staff/{$ownerRow->id}")->assertSessionHas('error');
        $camRow = $studio->studioUsers()->where('user_id', $cam->id)->sole();
        $this->delete("/{$studio->slug}/admin/staff/{$camRow->id}")->assertSessionHas('success');
        $this->assertSame('member', $camRow->fresh()->role);
    }

    public function test_connecting_stripe(): void
    {
        $this->fakeStripe([
            'api.stripe.com/v1/accounts' => Http::response(['id' => 'acct_new']),
            'api.stripe.com/v1/account_links' => Http::response(['url' => 'https://connect.stripe.com/setup/s/abc']),
            'api.stripe.com/v1/accounts/acct_new' => Http::response(['id' => 'acct_new', 'charges_enabled' => true, 'details_submitted' => true]),
        ]);
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/payments/connect")->assertRedirect('https://connect.stripe.com/setup/s/abc');
        $this->assertSame('acct_new', $studio->fresh()->stripe_account_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/account_links') && str_ends_with($r['return_url'], "/{$studio->slug}/admin/payments/return"));

        $this->get("/{$studio->slug}/admin/payments/return")->assertRedirect("/{$studio->slug}/admin/payments")->assertSessionHas('success');
        $this->assertTrue($studio->fresh()->stripeReady());
    }

    public function test_settings_are_validated_and_saved(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $valid = [
            'name' => 'Renamed', 'accent_color' => '#0F766E', 'timezone' => 'America/New_York', 'currency' => 'cad', 'contact_email' => 'hi@studio.test',
            'late_cancel_hours' => 6, 'booking_opens_days_before' => 14, 'booking_closes_minutes_before' => 30, 'credit_validity_days' => 60,
            'waiver_validity_days' => 0, 'reminder_hours_before' => 12, 'minor_age' => 18, 'schedule_weeks' => 3,
            'late_cancel_keeps_credit' => '1', 'no_show_consumes_credit' => '0', 'waitlist_auto_promote' => '1',
        ];

        $this->put("/{$studio->slug}/admin/settings", ['accent_color' => 'red; background:url(x)'] + $valid)->assertSessionHasErrors('accent_color');
        $this->put("/{$studio->slug}/admin/settings", $valid)->assertSessionHas('success');

        $studio->refresh();
        $this->assertSame(['Renamed', '#0f766e', 'America/New_York', 'cad'], [$studio->name, $studio->accent_color, $studio->timezone, $studio->currency]);
        $this->assertSame(6, $studio->setting('late_cancel_hours'));
        $this->assertTrue($studio->setting('late_cancel_keeps_credit'));
        $this->assertFalse($studio->setting('no_show_consumes_credit'));
        $this->get("/{$studio->slug}")->assertSee('--accent: #0f766e', false);
    }

    public function test_dashboard_counts_and_checklist(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->membership($studio, $user);
        $this->membership($this->studio(), $this->userIn($this->studio())); // another studio's
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->get("/{$studio->slug}/admin")->assertOk()
            ->assertSeeInOrder(['<strong>1</strong><span>Members</span>', '<strong>1</strong><span>Active memberships</span>'], false)
            ->assertSee('Publish your waiver');
        $this->assertSame(0, Booking::count());
    }
}
