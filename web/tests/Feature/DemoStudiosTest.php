<?php

namespace Tests\Feature;

use App\Jobs\RefillDemoPool;
use App\Models\Booking;
use App\Models\DemoRun;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\User;
use App\Services\Demo\DemoStudios;
use App\Services\Memberships;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** "Try the demo" (decision 44): a private copy of the sample studio per visitor, for a day. */
class DemoStudiosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        $this->fakeStripe(); // any call to Stripe from a demo fails the test
        $this->freezeSecond();
    }

    /** Starts a demo and keeps its cookie for the next requests, as a browser would. */
    private function startDemo(): TestResponse
    {
        $response = $this->post('/demo');
        if ($cookie = $response->getCookie(DemoStudios::COOKIE)) {
            $this->withCookie(DemoStudios::COOKIE, $cookie->getValue());
        }

        return $response;
    }

    private function demoStudio(): Studio
    {
        return Studio::whereNotNull('demo_expires_at')->latest('id')->firstOrFail();
    }

    public function test_trying_the_demo_gives_the_visitor_their_own_studio_signed_in_as_its_owner(): void
    {
        $this->get('/demo')->assertOk()->assertSee('Try Loft with a sample studio')->assertSee('Open the demo studio')->assertSee('deleted after 24 hours');
        $this->get('/')->assertOk()->assertSee('href="'.route('demo').'"', false)->assertSee('Try the demo');

        $response = $this->startDemo();
        $studio = $this->demoStudio();
        $response->assertRedirect("/{$studio->slug}/admin")->assertCookie(DemoStudios::COOKIE);
        $this->assertMatchesRegularExpression('/^demo-[a-z0-9]{6}$/', $studio->slug);
        $this->assertTrue($studio->isDemo());
        $this->assertTrue($studio->demo_expires_at->eq(now()->addHours(24)));
        $this->assertNotNull($studio->comped_at);
        $owner = auth()->user();
        $this->assertSame(['Olivia Owens', 'owner@'.substr($studio->slug, 5).'.example', $studio->id], [$owner->name, $owner->email, $owner->demo_studio_id]);
        $this->assertTrue($owner->hasRoleIn($studio, 'owner'));

        // A lived-in month: members, a busy week ahead, this month's sales, people at risk.
        $this->assertGreaterThanOrEqual(60, $studio->studioUsers()->where('role', 'member')->count());
        $this->assertGreaterThan(50, Booking::where('studio_id', $studio->id)->whereIn('status', ['booked', 'waitlisted'])->where('created_at', '>=', now()->subMinute())->count());
        $this->assertGreaterThan(50, $studio->orders()->where('status', 'paid')->count());
        $this->assertSame(1, DemoRun::count());

        $this->get("/{$studio->slug}/admin")->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertSee('Nothing here is real: no emails go out and no cards are charged. It’s deleted 1 day from now.', false)
            ->assertSee('See it as a member')
            ->assertSee('Start your own studio');
        $this->get("/{$studio->slug}/admin/subscription")->assertOk()->assertSee('There’s nothing to pay in the demo.', false)->assertDontSee('Give a month, get a month');

        // Every email it would have sent is logged as suppressed; none was sent.
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        $this->assertGreaterThan(0, MessageDelivery::where('studio_id', $studio->id)->count());
        $this->assertSame(['suppressed'], MessageDelivery::where('studio_id', $studio->id)->distinct()->pluck('status')->all());

        $this->get('/demo')->assertOk()->assertSee('Back to your demo')->assertSee('Start a fresh one');
    }

    public function test_the_visitor_switches_to_the_sample_member_and_back(): void
    {
        $this->startDemo();
        $studio = $this->demoStudio();
        $owner = auth()->user();

        $this->post('/demo/switch')->assertRedirect("/{$studio->slug}")->assertSessionHas('success');
        $member = auth()->user();
        $this->assertSame(['Priya Shah', 'member'], [$member->name, $member->membershipIn($studio)->role]);
        $this->get("/{$studio->slug}")->assertOk()->assertSee('Back to the owner');
        $this->get("/{$studio->slug}/account")->assertOk()->assertSee('Maya')->assertSee('Leo');
        $this->get("/{$studio->slug}/admin")->assertForbidden();

        $this->post('/demo/switch')->assertRedirect("/{$studio->slug}/admin");
        $this->assertTrue(auth()->user()->is($owner));

        // Someone else's browser, or a made-up cookie, switches nobody.
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->post('/demo/switch')->assertRedirect('/demo');
        $this->assertTrue(auth()->user()->is($stranger));
        $this->withCookie(DemoStudios::COOKIE, "{$studio->id}:{$stranger->id}:{$member->id}:1")->post('/demo/switch')->assertRedirect('/demo');
        $this->assertTrue(auth()->user()->is($stranger));
    }

    public function test_payments_stripe_and_video_uploads_are_switched_off(): void
    {
        $this->startDemo();
        $studio = $this->demoStudio();
        $base = "/{$studio->slug}/admin";
        $order = $studio->orders()->where('method', 'stripe')->where('status', 'paid')->firstOrFail();

        $this->get("{$base}/payments")->assertOk()->assertSee('Payments are off in the demo')->assertDontSee('Connect Stripe');
        foreach (["{$base}/payments/connect", "{$base}/payments/readers", "{$base}/orders/{$order->id}/refund"] as $url) {
            $this->from("{$base}/payments")->post($url, ['amount' => '1'])->assertRedirect("{$base}/payments")
                ->assertSessionHas('warning', 'Payments are switched off in the demo, so nothing here charges a card or refunds one. In your own studio, you connect Stripe and members pay you directly.');
        }
        $this->from("{$base}/subscription")->post("{$base}/subscription/checkout", ['interval' => 'month'])->assertSessionHas('warning', 'There’s nothing to pay for in the demo. In your own studio, this is where you subscribe to Loft.');
        $this->from("{$base}/videos")->post("{$base}/videos", ['title' => 'Warm-up'])->assertSessionHas('warning', 'Uploading videos is switched off in the demo. In your own studio, you upload classes to your on-demand library here.');
        $this->assertSame(0, $studio->videos()->count());

        $this->post('/demo/switch');
        $plan = $studio->plans()->where('kind', 'pack')->firstOrFail();
        $this->from("/{$studio->slug}/plans")->post("/{$studio->slug}/checkout/{$plan->id}")->assertSessionHas('warning');
        $this->assertNull($studio->fresh()->stripe_account_id);
    }

    public function test_accounts_made_in_a_demo_go_with_it_and_real_ones_stay(): void
    {
        $this->startDemo();
        $studio = $this->demoStudio();
        $real = User::factory()->create(['email' => 'real.person@example.com']);

        // A new staff member gets a demo account and no email; an existing person is just added.
        $this->post("/{$studio->slug}/admin/staff", ['email' => 'new.coach@example.com', 'first_name' => 'New', 'last_name' => 'Coach', 'role' => 'instructor'])->assertRedirect();
        $this->post("/{$studio->slug}/admin/staff", ['email' => 'real.person@example.com', 'role' => 'instructor'])->assertRedirect();
        $made = User::where('email', 'new.coach@example.com')->sole();
        $this->assertSame($studio->id, $made->demo_studio_id);
        $this->assertNull($real->fresh()->demo_studio_id);
        Notification::assertNothingSent();

        // Someone joining the demo's public page gets a demo account too.
        $joined = app(Memberships::class)->register($studio, ['first_name' => 'Jo', 'last_name' => 'Ina', 'email' => 'jo.ina@example.com', 'password' => 'long-enough'], false);
        $this->assertSame($studio->id, $joined->demo_studio_id);

        // A password reset for a demo account sends nothing.
        auth()->logout();
        $this->post('/forgot-password', ['email' => $made->email])->assertSessionHas('status');
        Notification::assertNothingSent();

        $this->travel(25)->hours();
        $this->artisan('loft:housekeeping')->expectsOutputToContain('Demo studios removed: 1.')->assertSuccessful();
        $this->assertNull(Studio::find($studio->id));
        $this->assertSame(0, User::whereNotNull('demo_studio_id')->count());
        $this->assertTrue($real->fresh()->exists);
        $this->assertSame(0, Booking::where('studio_id', $studio->id)->count());
        $this->assertSame(1, DemoRun::count(), 'the count of demos stays');
        $this->get("/{$studio->slug}")->assertNotFound();
    }

    public function test_starting_a_real_studio_leaves_the_demo_and_counts_as_a_conversion(): void
    {
        $this->startDemo();
        $demo = $this->demoStudio();

        $this->get('/signup')->assertRedirect('/signup');
        $this->assertGuest();
        $this->post('/demo/leave')->assertRedirect('/signup');

        $this->post('/signup', [
            'first_name' => 'Real', 'last_name' => 'Owner', 'email' => 'real.owner@example.com', 'password' => 'long-enough',
            'studio_name' => 'Real Aerial', 'slug' => 'real-aerial', 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1', 'heard_from' => 'search',
        ])->assertRedirect('/real-aerial/admin');
        $real = Studio::where('slug', 'real-aerial')->sole();
        $this->assertTrue($real->signup_source['tried_demo']);
        $this->assertNull(auth()->user()->demo_expires_at);
        $this->assertSame([$real->id, true], [DemoRun::sole()->studio_id, DemoRun::sole()->converted_at !== null]);

        $this->artisan('loft:signups')
            ->expectsOutput('Studios started in the last 30 days: 1')
            ->expectsOutput('Demos started: 1, and 1 of those browsers went on to start a studio.')
            ->expectsOutputToContain('tried the demo')
            ->assertSuccessful();
        $this->assertTrue($demo->fresh()->isDemo(), 'the demo itself lasts its day');
    }

    public function test_spares_are_built_ahead_so_starting_is_instant(): void
    {
        config(['loft.demo.pool' => 1]);
        $demos = app(DemoStudios::class);
        $this->assertSame(1, $demos->refill());
        $spare = Studio::whereNull('demo_claimed_at')->whereNotNull('demo_expires_at')->sole();
        $this->assertTrue($spare->demo_expires_at->eq(now()->addHours(12)));
        $this->assertSame(0, $demos->refill(), 'already full');

        $this->travel(2)->hours();
        $this->startDemo()->assertRedirect("/{$spare->slug}/admin");
        $spare->refresh();
        $this->assertNotNull($spare->demo_claimed_at);
        $this->assertTrue($spare->demo_expires_at->eq(now()->addHours(24)));
        $this->assertSame(0, User::where('demo_studio_id', $spare->id)->where('demo_expires_at', '!=', $spare->demo_expires_at)->count(), 'its accounts last as long');
        $this->assertSame(1, Studio::whereNull('demo_claimed_at')->whereNotNull('demo_expires_at')->count(), 'a new spare was queued');

        // A spare nobody takes is replaced while its week is still current.
        $this->travel(13)->hours();
        $this->assertSame(1, $demos->cleanup());
    }

    public function test_with_no_spare_ready_the_visitor_waits_while_the_worker_builds_one(): void
    {
        config(['loft.demo.pool' => 1]);
        Queue::fake();

        // Nothing is built in the visitor's request: they wait, and the worker is asked for one.
        $this->startDemo()->assertRedirect('/demo/wait');
        $this->assertGuest();
        $this->assertSame(0, Studio::count());
        Queue::assertPushed(RefillDemoPool::class);
        $this->get('/demo/wait')->assertOk()->assertSee('Setting up your studio…')->assertSee('data-demo-wait="'.route('demo.status').'"', false)
            ->assertSee('<button class="btn btn-large btn-block" disabled>Getting it ready…</button>', false);
        $this->getJson('/demo/status')->assertExactJson(['ready' => false]);
        Queue::assertPushed(RefillDemoPool::class, 1); // only one waits in the queue at a time

        // The worker's job builds one spare at a time.
        (new RefillDemoPool)->handle(app(DemoStudios::class));
        $this->getJson('/demo/status')->assertExactJson(['ready' => true]);
        $this->get('/demo/wait')->assertOk()->assertSee('Your demo studio is ready')->assertSee('Open the demo studio');
        $spare = Studio::sole();
        $this->startDemo()->assertRedirect("/{$spare->slug}/admin");
        $this->assertTrue(auth()->user()->hasRoleIn($spare, 'owner'));
    }

    public function test_starting_demos_is_limited(): void
    {
        $limit = RateLimiter::limiter('demo')(Request::create('/demo', 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']));
        $this->assertSame([10, 3600, 'demo:203.0.113.9'], [$limit->maxAttempts, $limit->decaySeconds, $limit->key]);

        config(['loft.demo.max_live' => 0]);
        $this->from('/demo')->startDemo()->assertRedirect('/demo')->assertSessionHas('warning');
        $this->assertSame(0, Studio::count());
        $this->assertGuest();
    }

    public function test_strangers_on_a_demo_page_see_what_it_is_but_cannot_switch(): void
    {
        $this->startDemo();
        $studio = $this->demoStudio();
        auth()->logout();
        $this->withUnencryptedCookie(DemoStudios::COOKIE, '')
            ->get("/{$studio->slug}")->assertOk()->assertSee('Demo studio.')->assertDontSee('See it as a member')->assertDontSee('Back to the owner');
        $this->assertSame(0, Studio::whereNull('demo_expires_at')->count());
    }
}
