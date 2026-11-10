<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_platform_pages_render(): void
    {
        $this->get('/')->assertOk()->assertSee('Start your studio');
        $this->get('/login')->assertOk();
        $this->get('/signup')->assertOk()->assertSee('America/Denver');
        $this->get('/forgot-password')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_studio_signup_creates_owner_studio_and_defaults(): void
    {
        $this->post('/signup', [
            'first_name' => 'Olivia', 'last_name' => 'Owens', 'email' => 'olivia@example.com', 'password' => 'long-enough',
            'studio_name' => 'Skyline Aerial', 'slug' => 'skyline', 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1',
        ])->assertRedirect('/skyline/admin');

        $studio = Studio::where('slug', 'skyline')->sole();
        $owner = User::where('email', 'olivia@example.com')->sole();
        $this->assertAuthenticatedAs($owner);
        $this->assertTrue($owner->hasRoleIn($studio, 'owner'));
        $this->assertSame(10, Category::where('studio_id', $studio->id)->count());
        $this->get('/skyline/admin')->assertOk()->assertSee('Connect Stripe so members can pay');
    }

    public function test_signup_refuses_reserved_and_taken_slugs(): void
    {
        $this->studio(['slug' => 'taken']);
        foreach (['login', 'taken', 'Bad Slug', 'ab'] as $slug) {
            $this->post('/signup', [
                'first_name' => 'A', 'last_name' => 'B', 'email' => "x{$slug}@example.com", 'password' => 'long-enough',
                'studio_name' => 'S', 'slug' => $slug, 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1',
            ])->assertSessionHasErrors('slug');
        }
    }

    public function test_signed_in_users_can_start_another_studio(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/signup', ['studio_name' => 'Second', 'slug' => 'second', 'timezone' => 'Europe/London', 'currency' => 'gbp', 'terms' => '1'])
            ->assertRedirect('/second/admin');
        $this->assertTrue($user->fresh()->hasRoleIn(Studio::where('slug', 'second')->sole(), 'owner'));
        $this->get('/studios')->assertOk()->assertSee('Second');
    }

    public function test_login_from_a_studio_lands_in_that_studio(): void
    {
        $studio = $this->studio();
        $member = $this->userIn($studio, 'member', ['password' => 'password123']);
        $owner = $this->userIn($studio, 'owner', ['password' => 'password123']);

        $this->post('/login', ['email' => $member->email, 'password' => 'password123', 'studio' => $studio->slug])->assertRedirect("/{$studio->slug}/account");
        $this->post('/logout', ['return' => "/{$studio->slug}"])->assertRedirect("/{$studio->slug}");
        $this->post('/login', ['email' => $owner->email, 'password' => 'password123', 'studio' => $studio->slug])->assertRedirect("/{$studio->slug}/admin");
        $this->post('/logout');
        $this->post('/login', ['email' => $owner->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'pat@example.com']);

        $this->post('/forgot-password', ['email' => 'pat@example.com'])->assertSessionHas('status');
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.$user->email)->assertOk();
            $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'brand-new-pass', 'password_confirmation' => 'brand-new-pass'])
                ->assertRedirect('/login');

            return true;
        });
        $this->post('/login', ['email' => 'pat@example.com', 'password' => 'brand-new-pass'])->assertRedirect('/studios');
    }
}
