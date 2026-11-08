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
            ->assertSessionHas('error', 'We couldn’t cancel a membership in Stripe, so your account wasn’t deleted. Try again in a few minutes.');

        $this->assertModelExists($user);
        $this->assertAuthenticatedAs($user);
        $this->assertSame('active', $membership->fresh()->status);
    }

    public function test_the_only_owner_hands_a_studio_over(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $admin = $this->userIn($studio, 'admin');
        $instructor = $this->userIn($studio, 'instructor');
        $member = $this->userIn($studio);
        $elsewhere = $this->userIn($this->studio(), 'admin');
        $this->actingAs($owner);

        $this->get("/{$studio->slug}/account/profile")->assertOk()
            ->assertSee('only owner of '.$studio->name)
            ->assertSeeInOrder(["Make {$admin->name} the owner (admin)", "Make {$instructor->name} the owner (instructor)", "Close {$studio->name} and delete it"])
            ->assertDontSee("Make {$member->name} the owner");

        // A choice is required, and only this studio's staff can take it over.
        foreach ([null, $member->id, $elsewhere->id, $owner->id] as $choice) {
            $this->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => $choice]])
                ->assertSessionHasErrors("studios.{$studio->id}");
        }
        $this->assertModelExists($owner);

        $this->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => $admin->id]])
            ->assertRedirect("/{$studio->slug}")
            ->assertSessionHas('success', 'Your account has been deleted.');
        $this->assertModelMissing($owner);
        $this->assertModelExists($studio);
        $this->assertSame('owner', $studio->studioUsers()->where('user_id', $admin->id)->value('role'));
        $this->assertSame('instructor', $studio->studioUsers()->where('user_id', $instructor->id)->value('role'));
    }

    public function test_the_only_owner_closes_a_studio(): void
    {
        Storage::fake('local');
        $this->fakeStripe(['api.stripe.com/v1/subscriptions/*' => Http::response(['status' => 'canceled'])]);
        $studio = $this->studio(['stripe_account_id' => 'acct_closing']);
        $open = $this->studio(['stripe_account_id' => 'acct_open']);
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio);
        $open->studioUsers()->create(['user_id' => $member->id, 'role' => 'member']);
        $this->membership($studio, $member, null, ['stripe_subscription_id' => 'sub_closing']);
        $kept = $this->membership($open, $member, null, ['stripe_subscription_id' => 'sub_open']);
        $this->attendee($studio, $member);
        $this->classSession($this->program($studio));
        Storage::disk('local')->put("signatures/{$studio->id}/sig-1.png", 'png');
        Storage::disk('local')->put("signatures/{$open->id}/sig-2.png", 'png');

        $this->actingAs($owner)
            ->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => 'close']])
            ->assertRedirect('/')
            ->assertSessionHas('success', 'Your account has been deleted.');

        $this->assertModelMissing($owner);
        $this->assertModelMissing($studio);
        $this->get("/{$studio->slug}")->assertNotFound();
        $this->assertSame(0, Entitlement::where('studio_id', $studio->id)->count());
        $this->assertSame(0, Attendee::where('studio_id', $studio->id)->count());
        Storage::disk('local')->assertMissing("signatures/{$studio->id}/sig-1.png");
        Storage::disk('local')->assertExists("signatures/{$open->id}/sig-2.png");

        // Members aren't charged by a studio that's gone, and keep their accounts and other studios.
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'https://api.stripe.com/v1/subscriptions/sub_closing'
            && $r->header('Stripe-Account') === ['acct_closing']);
        $this->assertCount(1, Http::recorded());
        $this->assertModelExists($member);
        $this->assertSame('active', $kept->fresh()->status);
        $this->assertTrue($member->fresh()->hasRoleIn($open, 'member'));
    }

    public function test_a_studio_isnt_closed_when_stripe_fails(): void
    {
        $this->fakeStripe(['api.stripe.com/v1/subscriptions/sub_closing' => Http::sequence()
            ->push(['error' => ['message' => 'Something went wrong']], 500)
            ->push(['id' => 'sub_closing', 'status' => 'active'])]);
        $studio = $this->studio(['stripe_account_id' => 'acct_closing']);
        $owner = $this->userIn($studio, 'owner');
        $this->membership($studio, $this->userIn($studio), null, ['stripe_subscription_id' => 'sub_closing']);

        $this->actingAs($owner)
            ->delete("/{$studio->slug}/account", ['delete_password' => 'password', 'studios' => [$studio->id => 'close']])
            ->assertSessionHas('error');

        $this->assertModelExists($owner);
        $this->assertModelExists($studio);
    }

    public function test_an_owner_makes_another_owner(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $admin = $this->userIn($studio, 'admin');
        $row = $studio->studioUsers()->where('user_id', $admin->id)->sole();

        $this->actingAs($owner)->get("/{$studio->slug}/admin/staff")->assertSee('Make owner');
        $this->patch("/{$studio->slug}/admin/staff/{$row->id}", ['role' => 'owner'])->assertSessionHas('success', "{$admin->name} is now an owner.");
        $this->assertSame('owner', $row->fresh()->role);

        // With another owner, the studio needs no choice.
        $this->get("/{$studio->slug}/account/profile")->assertDontSee('only owner of');
        $this->delete("/{$studio->slug}/account", ['delete_password' => 'password'])->assertRedirect("/{$studio->slug}");
        $this->assertModelMissing($owner);
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
