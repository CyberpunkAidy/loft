<?php

namespace Tests\Feature;

use App\Models\Studio;
use App\Models\User;
use Tests\TestCase;

/** The Terms of Service and Privacy Policy (decision 38): the pages, where they're linked, and who agreed to which version when. */
class LegalTest extends TestCase
{
    public function test_the_terms_and_privacy_policy_are_published(): void
    {
        $this->get('/terms')->assertOk()
            ->assertSee('Terms of Service')
            ->assertSee('Last updated November 13, 2026')
            ->assertSee('A studio that starts with another studio’s or a Loft partner’s referral code gets 30 more days of free trial.', false)
            ->assertSee('the owners and admins of a studio can’t use its code for a studio of their own', false)
            ->assertSee('Goldseam Tech LLC')
            ->assertSee('laws of the State of Wyoming')
            ->assertSee('privacy@loftstudio.app')
            ->assertSee('currently $49 a month, or $490 a year, per studio')
            ->assertSee('You choose monthly or yearly billing.')
            ->assertSee('end of the month or year you’ve paid for', false)
            ->assertSee('Your price is locked.')
            ->assertSee('stays the same for as long as your subscription continues on the same plan (monthly or yearly)')
            ->assertSee('A new price applies only to subscriptions started, or switched to the other plan, after it takes effect.')
            ->assertDontSee('before a new price applies to your subscription')
            ->assertSee('30-day free trial')
            ->assertSee('for 7 more days')
            ->assertSee('15. Data processing terms')
            ->assertSee('Decision (EU) 2021/914');
        $this->get('/privacy')->assertOk()
            ->assertSee('Privacy Policy')
            ->assertSee('Card numbers go straight to Stripe and never reach Loft')
            ->assertSee('We don’t sell your information', false)
            ->assertSee('how you heard about Loft and any referral code you used')
            ->assertSee('It’s kept for your visit only, and saved only if you start a studio.', false)
            ->assertSee('remembers its code for 30 days')
            ->assertSee('privacy@loftstudio.app');

        config(['loft.legal.entity' => 'Goldseam Tech Inc.', 'loft.legal.state' => 'Delaware', 'loft.billing.monthly_cents' => 5900, 'loft.billing.yearly_cents' => 0]);
        $this->get('/terms')->assertSee('Goldseam Tech Inc.')->assertSee('laws of the State of Delaware')
            ->assertSee('currently $59 a month per studio')
            ->assertSee('billed monthly in advance')
            ->assertSee('stays the same for as long as your subscription continues.')
            ->assertDontSee('monthly or yearly');
    }

    public function test_every_kind_of_page_links_to_them(): void
    {
        $studio = $this->studio();
        foreach (['/', '/pricing', "/{$studio->slug}"] as $page) {
            $this->get($page)->assertOk()->assertSee('href="'.route('terms').'"', false)->assertSee('href="'.route('privacy').'"', false);
        }
        $this->actingAs($this->userIn($studio, 'admin'))->get("/{$studio->slug}/admin")->assertOk()->assertSee('href="'.route('privacy').'"', false);
    }

    public function test_starting_a_studio_means_agreeing_to_the_terms(): void
    {
        $this->freezeSecond();
        $form = [
            'first_name' => 'Ola', 'last_name' => 'Owner', 'email' => 'ola@example.com', 'password' => 'secret-pass',
            'studio_name' => 'Pilates Loft', 'slug' => 'pilates', 'timezone' => 'America/Denver', 'currency' => 'usd', 'heard_from' => 'instagram',
        ];
        $this->get('/signup')->assertOk()->assertSee('I agree to Loft’s', false);
        $this->post('/signup', $form)->assertSessionHasErrors(['terms' => 'Agree to the Terms of Service and Privacy Policy to start your studio.']);
        $this->post('/signup', $form + ['terms' => '0'])->assertSessionHasErrors('terms');
        $this->assertSame(0, Studio::count());

        $this->post('/signup', $form + ['terms' => '1'])->assertRedirect('/pilates/admin');
        $studio = Studio::sole();
        $this->assertSame(['2026-11-13', now()->toDateTimeString()], [$studio->terms_version, $studio->terms_accepted_at->toDateTimeString()]);
        $owner = User::where('email', 'ola@example.com')->sole();
        $this->assertSame(['2026-11-13', now()->toDateTimeString()], [$owner->terms_version, $owner->terms_accepted_at->toDateTimeString()]);
    }

    public function test_members_agree_when_they_create_an_account(): void
    {
        $studio = $this->studio();
        $this->get("/{$studio->slug}/join")->assertOk()->assertSee('By creating an account, you agree to Loft’s', false);
        $this->post("/{$studio->slug}/join", ['first_name' => 'Maya', 'last_name' => 'Lee', 'email' => 'maya@example.com', 'password' => 'secret-pass'])->assertRedirect();
        $this->assertSame('2026-11-13', User::where('email', 'maya@example.com')->sole()->terms_version);

        $this->postJson("/api/v1/studios/{$studio->slug}/register", ['first_name' => 'Sam', 'last_name' => 'Ortiz', 'email' => 'sam@example.com', 'password' => 'secret-pass', 'device_name' => 'Phone'])
            ->assertCreated();
        $this->assertNotNull(User::where('email', 'sam@example.com')->sole()->terms_accepted_at);
        $this->assertNull(User::factory()->create()->terms_accepted_at, 'nobody is recorded as agreeing without doing so');
    }
}
