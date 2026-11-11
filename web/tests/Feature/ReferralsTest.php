<?php

namespace Tests\Feature;

use App\Mail\LoftNotice;
use App\Models\ReferralCode;
use App\Models\Studio;
use App\Services\Referrals;
use App\Support\SignupSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** "How did you hear about Loft?", where visits begin, and referral codes and their rewards (decision 43). */
class ReferralsTest extends TestCase
{
    /** @var array<string, array> invoices the fake API serves */
    private array $invoices = [];

    /** @var array<string, array> subscriptions the fake API serves */
    private array $subs = [];

    private bool $creditFails = false;

    private array $form = [
        'first_name' => 'Nia', 'last_name' => 'New', 'email' => 'nia@example.com', 'password' => 'long-enough',
        'studio_name' => 'Hoop Haven', 'slug' => 'hoop-haven', 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->freezeSecond();
    }

    private function fakeBillingApi(): void
    {
        config(['loft.billing.webhook_secret' => 'whsec_billing']);
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
            $id = basename($path);

            return match (true) {
                $request->method() === 'GET' && str_starts_with($path, 'invoices/') => Http::response($this->invoices[$id]),
                $request->method() === 'GET' && str_starts_with($path, 'subscriptions/') => Http::response($this->subs[$id]),
                $request->method() === 'POST' && preg_match('#^customers/[^/]+/balance_transactions$#', $path) === 1 => $this->creditFails
                    ? Http::response(['error' => ['message' => 'Stripe is down']], 500)
                    : Http::response(['id' => 'cbtxn_1', 'amount' => (int) $request['amount']]),
                default => Http::response(['error' => ['message' => "Unexpected {$request->method()} {$path}"]], 500),
            };
        }]);
    }

    private function billingWebhook(string $type, string $objectId): TestResponse
    {
        $payload = json_encode(['id' => 'evt_'.Str::random(10), 'type' => $type, 'data' => ['object' => ['id' => $objectId]]]);
        $timestamp = time();

        return $this->call('POST', '/stripe/billing-webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_billing'),
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    /** @return list<Request> */
    private function balanceCredits(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with((string) parse_url($r->url(), PHP_URL_PATH), '/balance_transactions'))->map(fn ($pair) => $pair[0])->values()->all();
    }

    /** A studio that joined with $code and now pays Loft through customer cus_new. */
    private function referredStudio(ReferralCode $code, string $name = 'Hoop Haven'): Studio
    {
        $studio = $this->studio(['name' => $name, 'comped_at' => null, 'trial_ends_at' => now()->subDay(), 'referral_code_id' => $code->id]);
        $customer = 'cus_'.$studio->id;
        $this->subs['sub_'.$studio->id] = ['id' => 'sub_'.$studio->id, 'customer' => $customer, 'status' => 'active', 'current_period_end' => now()->addMonth()->getTimestamp()];
        $studio->update(['billing_customer_id' => $customer]);

        return $studio;
    }

    private function invoice(Studio $studio, int $amountPaid): string
    {
        $id = 'in_'.count($this->invoices);
        $this->invoices[$id] = ['id' => $id, 'customer' => 'cus_'.$studio->id, 'subscription' => 'sub_'.$studio->id, 'amount_paid' => $amountPaid];

        return $id;
    }

    public function test_signup_asks_how_they_heard_and_keeps_where_the_visit_began(): void
    {
        $this->get('/pricing?utm_source=instagram&utm_medium=bio&utm_campaign=launch', ['Referer' => 'https://l.instagram.com/?u=x'])->assertOk();
        $this->get('/?utm_source=google')->assertOk(); // the first page of the visit is the one kept

        $this->get('/signup')->assertOk()
            ->assertSee('How did you hear about Loft?')
            ->assertSee('<option value="">Choose one</option>', false)
            ->assertSee('<option value="studio" >Another studio owner</option>', false)
            ->assertSee('Have a referral code?')
            ->assertDontSee('<details class="code-entry"  open', false)
            ->assertSee('Free for 30 days, no card needed.');

        $this->post('/signup', $this->form)->assertSessionHasErrors(['heard_from' => 'Choose how you heard about Loft. It helps us find more studios like yours.']);
        $this->post('/signup', $this->form + ['heard_from' => 'carrier-pigeon'])->assertSessionHasErrors('heard_from');

        $this->post('/signup', $this->form + ['heard_from' => 'instagram', 'heard_from_detail' => '  A reel from Skyline  '])->assertRedirect('/hoop-haven/admin');
        $studio = Studio::where('slug', 'hoop-haven')->sole();
        $this->assertSame(['instagram', 'A reel from Skyline'], [$studio->heard_from, $studio->heard_from_detail]);
        $this->assertSame(['utm_source' => 'instagram', 'utm_medium' => 'bio', 'utm_campaign' => 'launch', 'referrer' => 'l.instagram.com', 'landing' => '/pricing'], $studio->signup_source);
        $this->assertNull($studio->referral_code_id);
        $this->assertTrue($studio->trial_ends_at->eq(now()->addDays(30)));
    }

    public function test_a_visit_from_another_site_or_straight_in_is_recorded_too(): void
    {
        $this->get('/', ['Referer' => 'https://www.google.com/'])->assertOk();
        $this->post('/signup', $this->form + ['heard_from' => 'search'])->assertRedirect();
        $this->assertSame(['referrer' => 'www.google.com', 'landing' => '/'], Studio::where('slug', 'hoop-haven')->sole()->signup_source);
    }

    public function test_a_referral_link_gives_the_new_studio_sixty_days_and_says_who_sent_it(): void
    {
        $skyline = $this->studio(['name' => 'Skyline Aerial Arts', 'slug' => 'skyline-aerial']);
        $code = app(Referrals::class)->codeFor($skyline);
        $this->assertSame('SKYLINE', $code->code);
        $this->assertSame(url('/r/SKYLINE'), $code->url());

        $this->get('/r/skyline')->assertRedirect('/')->assertCookie(Referrals::COOKIE, 'SKYLINE');
        $this->get('/r/NOPE')->assertRedirect('/')->assertCookieMissing(Referrals::COOKIE);

        $this->withCookie(Referrals::COOKIE, 'SKYLINE');
        $this->get('/')->assertOk()->assertSeeInOrder(['<strong>Skyline Aerial Arts</strong> invited you to Loft:', 'your first 60 days are free.', 'Start your studio'], false);
        $this->get('/pricing')->assertOk()->assertSee('invited you to Loft');
        $this->get('/signup')->assertOk()
            ->assertSee('Free for 60 days, no card needed.')
            ->assertSee('<option value="studio" selected>Another studio owner</option>', false)
            ->assertSee('name="referral_code" type="text"  value="SKYLINE"', false)
            ->assertSee('A code from another studio or a Loft partner adds 30 days to your free trial.');

        $this->post('/signup', $this->form + ['heard_from' => 'studio', 'referral_code' => 'https://loftstudio.app/r/skyline/'])
            ->assertRedirect('/hoop-haven/admin')
            ->assertCookieExpired(Referrals::COOKIE);
        $studio = Studio::where('slug', 'hoop-haven')->sole();
        $this->assertSame($code->id, $studio->referral_code_id);
        $this->assertTrue($studio->trial_ends_at->eq(now()->addDays(60)));
        $this->assertSame(60, $studio->billing()->trialDaysLeft());

        // Signed in, the banner is gone.
        $this->get('/')->assertOk()->assertDontSee('invited you to Loft');
    }

    public function test_unknown_disabled_and_own_codes_are_refused(): void
    {
        $skyline = $this->studio(['name' => 'Skyline Aerial Arts', 'slug' => 'skyline']);
        app(Referrals::class)->codeFor($skyline);
        ReferralCode::create(['code' => 'MAYA', 'name' => 'Maya (instructor)', 'disabled_at' => now()]);

        $this->post('/signup', $this->form + ['heard_from' => 'studio', 'referral_code' => 'NOPE'])
            ->assertSessionHasErrors(['referral_code' => 'We don’t recognise that code. Check it, or leave it blank.']);
        $this->post('/signup', $this->form + ['heard_from' => 'instructor', 'referral_code' => 'maya'])->assertSessionHasErrors('referral_code');
        $this->get('/r/MAYA')->assertCookieMissing(Referrals::COOKIE);

        // An owner can't earn a month by starting another studio with their own code.
        $owner = $this->userIn($skyline, 'owner');
        $this->actingAs($owner)->withCookie(Referrals::COOKIE, 'SKYLINE');
        $this->get('/signup')->assertOk()->assertSee('name="referral_code" type="text"  value=""', false)->assertSee('Free for 30 days');
        $this->post('/signup', ['studio_name' => 'Skyline West', 'slug' => 'skyline-west', 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1', 'heard_from' => 'other', 'referral_code' => 'SKYLINE'])
            ->assertSessionHasErrors(['referral_code' => 'That’s your own studio’s code. Codes are for studios you introduce to Loft.']);
        $this->assertFalse(Studio::where('slug', 'skyline-west')->exists());

        // A member of Skyline opening a studio of their own is a real referral.
        $this->actingAs($this->userIn($skyline, 'member'));
        $this->post('/signup', ['studio_name' => 'Silks by Sam', 'slug' => 'silks', 'timezone' => 'America/Denver', 'currency' => 'usd', 'terms' => '1', 'heard_from' => 'studio', 'referral_code' => 'SKYLINE'])
            ->assertRedirect('/silks/admin');
        $this->assertSame($skyline->ownReferralCode->id, Studio::where('slug', 'silks')->sole()->referral_code_id);
    }

    public function test_codes_come_from_the_studio_address_and_never_repeat(): void
    {
        $this->assertSame(['SKYLINE', 'THELOFT', 'ABCSTUDIO', 'POLEPARLOUR', '123'], array_map(Referrals::baseCode(...), ['skyline-aerial', 'the-loft', 'ab-c-studio', 'pole-parlour-west', '123']));
        $this->assertSame(['SKYLINE', 'SKY', ''], array_map(Referrals::normalize(...), [' sky-line ', 'loftstudio.app/r/sky', null]));

        $referrals = app(Referrals::class);
        $a = $referrals->codeFor($this->studio(['slug' => 'skyline']));
        $b = $referrals->codeFor($this->studio(['slug' => 'skyline-west']));
        $this->assertSame(['SKYLINE', 'SKYLINE2'], [$a->code, $b->code]);
        $this->assertTrue($referrals->codeFor($a->studio->fresh())->is($a), 'one code per studio');
    }

    public function test_the_referrer_gets_a_month_off_once_the_new_studio_first_pays(): void
    {
        $this->fakeBillingApi();
        $skyline = $this->studio(['name' => 'Skyline Aerial Arts', 'slug' => 'skyline', 'comped_at' => null, 'billing_customer_id' => 'cus_sky', 'billing_status' => 'active', 'billing_interval' => 'month', 'billing_amount_cents' => 4900]);
        $owner = $this->userIn($skyline, 'owner', ['email' => 'sky@example.com']);
        $haven = $this->referredStudio(app(Referrals::class)->codeFor($skyline));

        // The $0 invoice that starts a trial isn't a payment.
        $this->billingWebhook('invoice.paid', $this->invoice($haven, 0))->assertOk();
        $this->assertNull($haven->refresh()->referral_rewarded_at);
        $this->assertSame([], $this->balanceCredits());

        // Stripe fails: nothing is recorded, and Stripe's retry tries again.
        $this->creditFails = true;
        $paid = $this->invoice($haven, 4900);
        $this->billingWebhook('invoice.paid', $paid)->assertStatus(500);
        $this->assertNull($haven->refresh()->referral_rewarded_at);
        Mail::assertNothingQueued();

        $this->creditFails = false;
        $this->billingWebhook('invoice.paid', $paid)->assertOk();
        $haven->refresh();
        $this->assertSame(['credit', 4900], [$haven->referral_reward, $haven->referral_reward_cents]);
        $this->assertNotNull($haven->referral_rewarded_at);
        $credit = $this->balanceCredits()[1];
        $this->assertStringEndsWith('/v1/customers/cus_sky/balance_transactions', $credit->url());
        $this->assertSame(['-4900', 'usd', 'A free month: Hoop Haven joined Loft with your referral code'], [(string) $credit['amount'], $credit['currency'], $credit['description']]);
        $this->assertSame('loft-referral-'.$haven->id, $credit->header('Idempotency-Key')[0]);

        $notice = Mail::queued(LoftNotice::class, fn (LoftNotice $m) => $m->kind === 'referral_reward')->sole();
        $this->assertTrue($notice->hasTo('sky@example.com'));
        $this->assertSame('You’ve earned a free month of Loft', $notice->headline);
        $this->assertSame('We’ve taken $49 off Skyline Aerial Arts’s next Loft bill. It comes off automatically; there’s nothing to do.', $notice->lines[1]);
        $this->assertStringEndsWith('/skyline/admin/subscription#refer', $notice->actionUrl);

        // Later payments earn nothing more.
        $this->billingWebhook('invoice.paid', $this->invoice($haven, 4900))->assertOk();
        $this->assertCount(2, $this->balanceCredits());
        $this->assertCount(1, Mail::queued(LoftNotice::class));

        // The owner sees it.
        $this->actingAs($owner)->get('/skyline/admin/subscription')->assertOk()
            ->assertSeeInOrder(['Studios you’ve brought to Loft', 'Hoop Haven', 'You earned $49 off']);
    }

    public function test_a_yearly_referrer_gets_a_twelfth_of_its_price(): void
    {
        $this->fakeBillingApi();
        $skyline = $this->studio(['slug' => 'skyline', 'comped_at' => null, 'billing_customer_id' => 'cus_sky', 'billing_status' => 'active', 'billing_interval' => 'year', 'billing_amount_cents' => 49000]);
        $haven = $this->referredStudio(app(Referrals::class)->codeFor($skyline));

        $this->billingWebhook('invoice.paid', $this->invoice($haven, 49000))->assertOk();
        $this->assertSame('-4083', (string) $this->balanceCredits()[0]['amount']);
        $this->assertSame(4083, $haven->refresh()->referral_reward_cents);
    }

    public function test_a_referrer_still_on_its_trial_gets_more_days_and_comped_studios_and_partners_get_nothing_automatic(): void
    {
        $this->fakeBillingApi();
        $referrals = app(Referrals::class);

        $trial = $this->studio(['name' => 'Trial Studio', 'slug' => 'trial', 'comped_at' => null, 'trial_ends_at' => now()->addDays(10)]);
        $this->userIn($trial, 'owner', ['email' => 'trial@example.com']);
        $one = $this->referredStudio($referrals->codeFor($trial), 'First');
        $referrals->reward($one);
        $this->assertTrue($trial->refresh()->trial_ends_at->eq(now()->addDays(40)));
        $this->assertSame('trial', $one->refresh()->referral_reward);
        $notice = Mail::queued(LoftNotice::class)->sole();
        $this->assertSame('You’ve earned 30 more free days of Loft', $notice->headline);
        $this->assertStringContainsString('It now ends on '.$trial->local(now()->addDays(40))->format('F j').'.', $notice->lines[1]);

        // A trial that had already run out starts again from today.
        $trial->update(['trial_ends_at' => now()->subDays(3)]);
        $referrals->reward($this->referredStudio($trial->ownReferralCode, 'Second'));
        $this->assertTrue($trial->refresh()->trial_ends_at->eq(now()->addDays(30)));

        $comped = $this->studio(['slug' => 'comped']); // comped by default in tests
        $three = $this->referredStudio($referrals->codeFor($comped), 'Third');
        $referrals->reward($three);
        $this->assertSame('none', $three->refresh()->referral_reward);

        $partner = ReferralCode::create(['code' => 'MAYA', 'name' => 'Maya (instructor)']);
        $four = $this->referredStudio($partner, 'Fourth');
        $referrals->reward($four);
        $this->assertSame('partner', $four->refresh()->referral_reward);

        $this->assertSame([], $this->balanceCredits());
        $this->assertCount(2, Mail::queued(LoftNotice::class));
    }

    public function test_owners_and_admins_see_their_link_on_the_subscription_page(): void
    {
        $skyline = $this->studio(['name' => 'Skyline Aerial Arts', 'slug' => 'skyline', 'comped_at' => null, 'trial_ends_at' => now()->addDays(20)]);
        $code = app(Referrals::class)->codeFor($skyline);
        $this->studio(['name' => 'Hoop Haven', 'slug' => 'hoop-haven', 'referral_code_id' => $code->id, 'created_at' => now()->subDays(2)]);

        $this->actingAs($this->userIn($skyline, 'admin'))->get('/skyline/admin/subscription')->assertOk()
            ->assertSee('Give a month, get a month')
            ->assertSee('They get 60 days free instead of 30, and once they’ve made their first payment, you get a month free.', false)
            ->assertSee('value="'.url('/r/SKYLINE').'" readonly', false)
            ->assertSee('data-copy="#f-referral-link"', false)
            ->assertSee('<strong class="code">SKYLINE</strong>', false)
            ->assertSeeInOrder(['Hoop Haven', 'joined', 'On their free trial']);

        $this->actingAs($this->userIn($skyline, 'instructor'))->get('/skyline/admin/subscription')->assertOk()->assertDontSee('Give a month, get a month');

        $skyline->update(['comped_at' => now()]);
        $this->actingAs($skyline->studioUsers()->where('role', 'admin')->first()->user)->get('/skyline/admin/subscription')->assertOk()
            ->assertSee('Loft is already free for Skyline Aerial Arts, so there’s nothing for you to earn, but they still get the extra month.', false);
    }

    public function test_without_a_bonus_there_is_no_invite_banner_and_the_trial_is_the_usual_one(): void
    {
        config(['loft.referrals.bonus_days' => 0]);
        $skyline = $this->studio(['name' => 'Skyline Aerial Arts', 'slug' => 'skyline']);
        app(Referrals::class)->codeFor($skyline);

        $this->withCookie(Referrals::COOKIE, 'SKYLINE')->get('/')->assertOk()->assertDontSee('invited you');
        $this->get('/signup')->assertSee('Free for 30 days')->assertDontSee('adds 0 days');
        $this->post('/signup', $this->form + ['heard_from' => 'studio', 'referral_code' => 'SKYLINE'])->assertRedirect();
        $haven = Studio::where('slug', 'hoop-haven')->sole();
        $this->assertTrue($haven->trial_ends_at->eq(now()->addDays(30)));
        $this->assertSame('SKYLINE', $haven->referralCode->code, 'still recorded, for the report');

        // No reward either, and no card to share.
        $skyline->update(['comped_at' => null, 'trial_ends_at' => now()->addDays(5)]);
        app(Referrals::class)->reward($haven);
        $this->assertSame('none', $haven->refresh()->referral_reward);
        $this->assertTrue($skyline->refresh()->trial_ends_at->eq(now()->addDays(5)));
        Mail::assertNothingQueued();
        $this->actingAs($this->userIn($skyline, 'owner'))->get('/skyline/admin/subscription')->assertOk()->assertDontSee('Give a month, get a month');
    }

    public function test_partner_codes_are_made_and_turned_off_with_a_command(): void
    {
        $this->artisan('loft:partner-code')->expectsOutputToContain('No partner codes yet.')->assertSuccessful();
        $this->artisan('loft:partner-code maya --name="Maya (aerial instructor)"')
            ->expectsOutput('Made MAYA for Maya (aerial instructor). Share it as '.url('/r/MAYA').' or have them enter the code when they sign up.')
            ->assertSuccessful();
        $this->artisan('loft:partner-code MAYA')->expectsOutput('MAYA is already a partner code (Maya (aerial instructor)).')->assertFailed();
        $this->artisan('loft:partner-code MA-YA')->expectsOutput('Use 3 to 20 letters and numbers, like MAYA.')->assertFailed();
        $this->artisan('loft:partner-code AB')->assertFailed();

        $this->post('/signup', $this->form + ['heard_from' => 'instructor', 'referral_code' => 'maya'])->assertRedirect();
        $studio = Studio::where('slug', 'hoop-haven')->sole();
        $this->assertSame('MAYA', $studio->referralCode->code);
        $this->assertTrue($studio->trial_ends_at->eq(now()->addDays(60)));
        $this->artisan('loft:partner-code')->expectsTable(['Code', 'For', 'Studios', 'Working'], [['MAYA', 'Maya (aerial instructor)', 1, 'yes']])->assertSuccessful();

        $this->artisan('loft:partner-code MAYA --disable')->expectsOutput('MAYA (Maya (aerial instructor)) is turned off.')->assertSuccessful();
        $this->assertNull(app(Referrals::class)->find('MAYA'));
        $this->artisan('loft:partner-code MAYA --enable')->expectsOutput('MAYA (Maya (aerial instructor)) is working again.')->assertSuccessful();
        $this->artisan('loft:partner-code NOPE --disable')->expectsOutput('There’s no code NOPE.')->assertFailed();

        app(Referrals::class)->codeFor($this->studio(['name' => 'Skyline', 'slug' => 'skyline']));
        $this->artisan('loft:partner-code skyline')->expectsOutput('SKYLINE is already Skyline’s code.')->assertFailed();
    }

    public function test_the_signups_report_groups_studios_by_how_they_found_loft(): void
    {
        $skyline = $this->studio(['name' => 'Skyline', 'slug' => 'skyline', 'created_at' => now()->subDays(60)]);
        $code = app(Referrals::class)->codeFor($skyline);
        $this->studio(['name' => 'Hoop Haven', 'slug' => 'hoop-haven', 'comped_at' => null, 'trial_ends_at' => now()->addDays(50),
            'heard_from' => 'studio', 'heard_from_detail' => 'Skyline', 'referral_code_id' => $code->id,
            'signup_source' => ['landing' => '/r/SKYLINE']]);
        $this->studio(['name' => 'Pole Parlour', 'slug' => 'pole-parlour', 'comped_at' => null, 'billing_status' => 'active', 'billing_period_ends_at' => now()->addMonth(),
            'heard_from' => 'instagram', 'signup_source' => ['utm_source' => 'instagram', 'utm_medium' => 'bio', 'utm_campaign' => 'launch', 'referrer' => 'l.instagram.com', 'landing' => '/pricing']]);
        $this->studio(['name' => 'Aerial Arts', 'slug' => 'aerial-arts', 'comped_at' => null, 'trial_ends_at' => now()->addDays(20),
            'heard_from' => 'instagram', 'signup_source' => ['referrer' => 'www.google.com', 'landing' => '/']]);

        $this->artisan('loft:signups')
            ->expectsOutput('Studios started in the last 30 days: 3')
            ->expectsTable(['Answer', 'Studios', 'Subscribed'], [['Instagram', 2, 1], ['Another studio owner', 1, 0]])
            ->expectsTable(['Code', 'Whose', 'Studios', 'Subscribed', 'Rewarded'], [['SKYLINE', 'Skyline', 1, 0, 0]])
            ->expectsOutputToContain('instagram / bio (launch) → /pricing')
            ->assertSuccessful();
        $this->artisan('loft:signups --days=90')->expectsOutput('Studios started in the last 90 days: 4')->assertSuccessful();
        $this->artisan('loft:signups --days=soon')->assertFailed();
        $this->assertSame('www.google.com', SignupSource::describe(['referrer' => 'www.google.com', 'landing' => '/']));
        $this->assertSame('/r/SKYLINE', SignupSource::describe(['landing' => '/r/SKYLINE']));
    }
}
