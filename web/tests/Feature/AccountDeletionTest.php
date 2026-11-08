<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\StudioUser;
use App\Models\WaiverSignature;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    public function test_a_member_deletes_their_account_at_every_studio(): void
    {
        Storage::fake('local');
        $this->fakeStripe([
            'api.stripe.com/v1/subscriptions/sub_live' => Http::response(['id' => 'sub_live', 'status' => 'canceled']),
            'api.stripe.com/v1/subscriptions/sub_gone' => Http::response(['error' => ['message' => 'No such subscription', 'code' => 'resource_missing']], 404),
        ]);
        config(['session.driver' => 'database']);

        $studio = $this->studio(['stripe_account_id' => 'acct_studio', 'stripe_charges_enabled' => true]);
        $other = $this->studio();
        $user = $this->userIn($studio, 'instructor');
        $other->studioUsers()->create(['user_id' => $user->id, 'role' => 'member']);
        $child = $this->attendee($studio, $user);
        $this->attendee($other, $user);
        $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_live']);
        $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_gone', 'status' => 'past_due']);
        $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_old', 'status' => 'cancelled']);
        $this->credits($other, $user);

        // They teach the class and hold its only seat; a neighbor is waiting for it.
        $program = $this->program($studio, ['instructor_id' => $user->id]);
        $session = $this->classSession($program, ['capacity' => 1, 'instructor_id' => $user->id]);
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $child->id, 'user_id' => $user->id, 'status' => 'booked', 'booked_at' => now()]);
        $neighbor = $this->userIn($studio);
        $waiting = Booking::create([
            'studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $this->attendee($studio, $neighbor)->id,
            'user_id' => $neighbor->id, 'status' => 'waitlisted', 'position' => 1, 'booked_at' => now(),
        ]);

        $waiver = $this->waiver($studio, ['is_active' => true]);
        Storage::disk('local')->put('signatures/1/sig-1.png', 'png');
        WaiverSignature::create([
            'studio_id' => $studio->id, 'waiver_id' => $waiver->id, 'waiver_version' => 1, 'attendee_id' => $child->id,
            'signed_by_user_id' => $user->id, 'signed_name' => $user->name, 'signature_path' => 'signatures/1/sig-1.png', 'signed_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'x', 'created_at' => now()]);
        DB::table('sessions')->insert([
            ['id' => 'their-phone', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'neighbor', 'user_id' => $neighbor->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($user)->get("/{$studio->slug}/account/profile")->assertOk()->assertSee('Delete my account');
        $this->delete("/{$studio->slug}/account", ['delete_password' => 'password'])
            ->assertRedirect("/{$studio->slug}")
            ->assertSessionHas('success', 'Your account has been deleted.');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertSame(0, StudioUser::where('user_id', $user->id)->count());
        $this->assertSame(0, Attendee::where('user_id', $user->id)->count());
        $this->assertSame(0, Entitlement::where('user_id', $user->id)->count());
        $this->assertSame(0, Booking::where('user_id', $user->id)->count());
        $this->assertSame(0, WaiverSignature::count());
        Storage::disk('local')->assertMissing('signatures/1/sig-1.png');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('sessions', ['id' => 'their-phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'neighbor']);

        // Billing stopped on the studio's Stripe account; the cancelled membership was left alone.
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_live'
            && $r->header('Stripe-Account') === ['acct_studio']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_gone');
        $this->assertCount(2, Http::recorded());

        // The studio keeps the class, without an instructor, and the seat went to the waitlist.
        $this->assertNull($program->fresh()->instructor_id);
        $this->assertNull($session->fresh()->instructor_id);
        $this->assertSame('booked', $waiting->fresh()->status);
    }

    public function test_the_password_is_required(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);

        $this->actingAs($user)->from("/{$studio->slug}/account/profile")
            ->delete("/{$studio->slug}/account", ['delete_password' => 'wrong'])
            ->assertRedirect("/{$studio->slug}/account/profile")
            ->assertSessionHasErrors('delete_password');

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_failed_stripe_cancellation_keeps_the_account(): void
    {
        $this->fakeStripe([
            'api.stripe.com/v1/subscriptions/sub_live' => Http::sequence()
                ->push(['error' => ['message' => 'Something went wrong']], 500)
                ->push(['id' => 'sub_live', 'status' => 'active']),
        ]);
        $studio = $this->studio(['stripe_account_id' => 'acct_studio']);
        $user = $this->userIn($studio);
        $membership = $this->membership($studio, $user, null, ['stripe_subscription_id' => 'sub_live']);

        $this->actingAs($user)->delete("/{$studio->slug}/account", ['delete_password' => 'password'])
            ->assertSessionHas('error', 'We couldn’t cancel your membership in Stripe, so your account wasn’t deleted. Try again in a few minutes.');

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_the_only_owner_hands_the_studio_over_first(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $admin = $this->userIn($studio, 'admin');
        $this->actingAs($owner);

        $this->get("/{$studio->slug}/account/profile")->assertSee('only owner of '.$studio->name)->assertDontSee('Delete my account');
        $this->delete("/{$studio->slug}/account", ['delete_password' => 'password'])->assertSessionHas('error');
        $this->assertModelExists($owner);

        $row = $studio->studioUsers()->where('user_id', $admin->id)->sole();
        $this->get("/{$studio->slug}/admin/staff")->assertSee('Make owner');
        $this->patch("/{$studio->slug}/admin/staff/{$row->id}", ['role' => 'owner'])->assertSessionHas('success', "{$admin->name} is now an owner.");

        $this->get("/{$studio->slug}/account/profile")->assertSee('Delete my account');
        $this->delete("/{$studio->slug}/account", ['delete_password' => 'password'])->assertRedirect("/{$studio->slug}");
        $this->assertModelMissing($owner);
        $this->assertModelExists($studio);
        $this->assertSame('owner', $row->fresh()->role);
    }

    public function test_only_owners_make_owners(): void
    {
        $studio = $this->studio();
        $this->userIn($studio, 'owner');
        $admin = $this->userIn($studio, 'admin');
        $instructor = $this->userIn($studio, 'instructor');
        $row = $studio->studioUsers()->where('user_id', $instructor->id)->sole();

        $this->actingAs($admin)->get("/{$studio->slug}/admin/staff")->assertOk()->assertDontSee('Make owner');
        $this->patch("/{$studio->slug}/admin/staff/{$row->id}", ['role' => 'owner'])->assertForbidden();
        $this->assertSame('instructor', $row->fresh()->role);
    }
}
