<?php

namespace Tests\Feature;

use App\Jobs\SendText;
use App\Mail\StudioMessage;
use App\Mail\TextReply;
use App\Models\Automation;
use App\Models\DomainEvent;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Automations;
use App\Services\Bookings;
use App\Services\Texts;
use App\Services\Twilio\TwilioSignature;
use App\Support\Phone;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Decision 48: texts from each studio's own Twilio number, to members who opted in. */
class TextMessagesTest extends TestCase
{
    private const SID = 'AC'.'0123456789abcdef0123456789abcdef'; // made up, in two parts so secret scanners don't take it for a real one

    private const TOKEN = '0123456789abcdef0123456789abcdef';

    private int $sent = 0;

    private function textingStudio(array $attributes = []): Studio
    {
        return $this->studio($attributes + [
            'name' => 'Skyline Aerial',
            'twilio_account_sid' => self::SID,
            'twilio_auth_token' => self::TOKEN,
            'sms_from' => '+13035550100',
            'sms_number_sid' => 'PN0123456789abcdef0123456789abcdef',
            'sms_connected_at' => now(),
        ]);
    }

    /** Twilio takes every text (or answers with $error) and connects with one or two numbers. */
    private function fakeTwilio(?array $error = null, int $numbers = 2): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api.twilio.com/2010-04-01/Accounts/*/Messages.json' => fn () => $error
                ? Http::response($error, $error['status'] ?? 400)
                : Http::response(['sid' => 'SM'.str_pad((string) ++$this->sent, 32, '0', STR_PAD_LEFT), 'status' => 'queued'], 201),
            'api.twilio.com/2010-04-01/Accounts/*/IncomingPhoneNumbers/*' => Http::response(['sid' => 'PN1', 'sms_url' => 'https://loft.test/twilio/1/incoming']),
            'api.twilio.com/2010-04-01/Accounts/*/IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => array_slice([
                ['sid' => 'PN1', 'phone_number' => '+13035550100', 'friendly_name' => 'Front desk', 'capabilities' => ['sms' => true], 'sms_url' => 'https://demo.twilio.com/welcome/sms/reply'],
                ['sid' => 'PN2', 'phone_number' => '+17205550101', 'friendly_name' => '(720) 555-0101', 'capabilities' => ['sms' => true], 'sms_url' => null],
                ['sid' => 'PN3', 'phone_number' => '+18005550199', 'friendly_name' => 'Voice only', 'capabilities' => ['sms' => false], 'sms_url' => null],
            ], 0, $numbers === 1 ? 1 : 3)]),
            'api.twilio.com/2010-04-01/Accounts/*.json' => Http::response(['sid' => self::SID, 'friendly_name' => 'Skyline', 'status' => 'active', 'type' => 'Full']),
        ]);
    }

    /** @return Collection<int, array<string, string>> the texts sent to Twilio, as their form fields */
    private function texts(): Collection
    {
        return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/Messages.json'))->map(fn ($pair) => $pair[0]->data())->values();
    }

    private function texter(Studio $studio, array $attributes = [], bool $marketing = false, bool $updates = true): User
    {
        $user = $this->userIn($studio, 'member', $attributes + ['phone' => '303-555-0142']);
        StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->update(array_filter([
            'sms_updates_at' => $updates ? now() : null, 'sms_updates_source' => $updates ? 'profile' : null,
            'sms_marketing_at' => $marketing ? now() : null, 'sms_marketing_source' => $marketing ? 'profile' : null,
        ]));

        return $user;
    }

    private function twilioPost(string $url, array $params, ?string $token = self::TOKEN): TestResponse
    {
        return $this->post($url, $params, ['X-Twilio-Signature' => TwilioSignature::sign((string) $token, $url, $params)]);
    }

    private function api(string $method, string $uri, User $user, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, ['Authorization' => 'Bearer '.$user->createToken('Test phone', ['member'])->plainTextToken]);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $response;
    }

    public function test_phone_numbers_are_read_as_people_type_them(): void
    {
        foreach (['303-555-0123', '(303) 555-0123', '+1 303 555 0123', '1-303-555-0123', '303.555.0123 ext 12'] as $typed) {
            $this->assertSame('+13035550123', Phone::e164($typed), $typed);
        }
        $this->assertSame('+442079460958', Phone::e164('+44 20 7946 0958'));
        $this->assertSame('+442079460958', Phone::e164('011 44 20 7946 0958'));
        foreach (['', '555-0123', '123-456-7890', '+1 103 555 0123', 'call me'] as $typed) {
            $this->assertNull(Phone::e164($typed), $typed);
        }
        $this->assertSame('(303) 555-0123', Phone::display('+13035550123'));

        $user = User::factory()->create(['phone' => '(720) 555-0199']);
        $this->assertSame('+17205550199', $user->phone_e164);
        $user->update(['phone' => 'ask at the desk']);
        $this->assertNull($user->fresh()->phone_e164);
    }

    public function test_a_studio_connects_its_twilio_account_and_chooses_a_number(): void
    {
        $this->fakeTwilio();
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner);
        $page = "/{$studio->slug}/admin/texts";

        $this->get($page)->assertOk()->assertSee('Connect your Twilio number')->assertSee('What to put in your A2P 10DLC registration')
            ->assertSee('Reply HELP for help, STOP to opt out.')->assertSee(route('studio.account.profile', $studio));
        $this->post("{$page}/connect", ['account_sid' => 'nope', 'auth_token' => 'short'])->assertSessionHasErrors(['account_sid', 'auth_token']);

        $this->post("{$page}/connect", ['account_sid' => self::SID, 'auth_token' => strtoupper(self::TOKEN)])->assertRedirect($page);
        $studio->refresh();
        $this->assertSame([self::SID, self::TOKEN, null], [$studio->twilio_account_sid, $studio->twilio_auth_token, $studio->sms_from]);
        $this->assertNotSame(self::TOKEN, DB::table('studios')->where('id', $studio->id)->value('twilio_auth_token')); // encrypted
        $this->assertArrayNotHasKey('twilio_auth_token', $studio->toArray());
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.twilio.com/2010-04-01/Accounts/'.self::SID.'.json'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::SID.':'.self::TOKEN)));

        // Two numbers that text (the voice-only one isn't offered): they choose.
        $this->get($page)->assertOk()->assertSee('Choose the number to text from')->assertSee('(303) 555-0100')->assertSee('(720) 555-0101')
            ->assertDontSee('(800) 555-0199')->assertSee('Texts to it go to demo.twilio.com now.');
        $this->post("{$page}/number", ['number_sid' => 'PN9'])->assertSessionHasErrors('number_sid');
        $this->post("{$page}/number", ['number_sid' => 'PN2'])->assertRedirect($page)->assertSessionHas('success', 'Texts are on. They come from (720) 555-0101. Send yourself a test below.');
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/IncomingPhoneNumbers/PN2.json')
            && $r['SmsUrl'] === route('twilio.incoming', ['studioId' => $studio->id]) && $r['SmsMethod'] === 'POST');
        $this->assertTrue($studio->fresh()->textsReady());
        $this->get($page)->assertOk()->assertSee('Texts are on')->assertSee('(720) 555-0101')->assertSee('What goes by text')
            ->assertSee('Skyline Aerial: Reminder: Maya has Kids Aerial Silks tomorrow at 4:30 PM.');

        // A test text.
        $this->post("{$page}/test", ['test_phone' => '555'])->assertSessionHasErrors('test_phone');
        $this->post("{$page}/test", ['test_phone' => '(303) 555-0177'])->assertSessionHas('success');
        $this->assertSame(['From' => '+17205550101', 'To' => '+13035550177', 'Body' => 'Skyline Aerial: This is a test text from Loft. Texts to your members will come from this number.',
            'StatusCallback' => route('twilio.status', ['studioId' => $studio->id, 'delivery' => MessageDelivery::where('kind', 'text_test')->sole()->id])], $this->texts()->sole());

        // Disconnecting forgets the keys.
        $this->delete($page)->assertRedirect($page);
        $this->assertNull($studio->fresh()->twilio_auth_token);
        $this->assertFalse($studio->fresh()->textsReady());
    }

    public function test_one_number_is_taken_straight_away_and_wrong_keys_are_explained(): void
    {
        $this->fakeTwilio(numbers: 1);
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'admin'));
        $this->post("/{$studio->slug}/admin/texts/connect", ['account_sid' => self::SID, 'auth_token' => self::TOKEN])->assertSessionHas('success', 'Texts are on. They come from (303) 555-0100. Send yourself a test below.');
        $this->assertSame('+13035550100', $studio->fresh()->sms_from);

        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['api.twilio.com/*' => Http::response(['code' => 20003, 'message' => 'Authenticate', 'status' => 401], 401)]);
        $other = $this->studio();
        $this->actingAs($this->userIn($other, 'owner'));
        $this->post("/{$other->slug}/admin/texts/connect", ['account_sid' => self::SID, 'auth_token' => self::TOKEN])
            ->assertSessionHasErrors(['auth_token' => 'Twilio didn’t accept that Account SID and Auth Token. Copy both again from your Twilio console.']);
        $this->assertNull($other->fresh()->twilio_account_sid);

        // Instructors don't see it; a demo studio can't connect.
        $this->actingAs($this->userIn($studio, 'instructor'))->get("/{$studio->slug}/admin/texts")->assertForbidden();
        $demo = $this->studio(['demo_expires_at' => now()->addDay()]);
        $this->actingAs($this->userIn($demo, 'owner'));
        $this->get("/{$demo->slug}/admin/texts")->assertOk()->assertSee('Texts are off in the demo');
        $this->post("/{$demo->slug}/admin/texts/connect", ['account_sid' => self::SID, 'auth_token' => self::TOKEN])->assertSessionHas('warning');
        $this->assertNull($demo->fresh()->twilio_account_sid);
        $this->assertFalse($this->textingStudio(['demo_expires_at' => now()->addDay()])->textsReady());
    }

    public function test_members_opt_in_on_their_profile_and_get_a_confirmation_text(): void
    {
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $member = $this->userIn($studio, 'member', ['first_name' => 'Priya', 'last_name' => 'Shah', 'email' => 'priya@example.com', 'phone' => null]);
        $this->actingAs($member);
        $profile = "/{$studio->slug}/account/profile";
        $form = ['first_name' => 'Priya', 'last_name' => 'Shah', 'email' => 'priya@example.com'];

        $this->get($profile)->assertOk()->assertSee('Texts from Skyline Aerial')
            ->assertSee('Text me class reminders and booking updates from Skyline Aerial')->assertSee('Text me news and offers from Skyline Aerial')
            ->assertSee('Message frequency varies. Msg &amp; data rates may apply. Reply HELP for help, STOP to opt out.', false)->assertSee('(303) 555-0100');
        $this->put($profile, $form + ['text_updates' => 1])->assertSessionHasErrors(['phone' => 'Enter a mobile number we can text, like (303) 555-0123, to get texts.']);
        $this->assertSame(0, $this->texts()->count());

        $this->put($profile, $form + ['phone' => '(303) 555-0142', 'text_updates' => 1, 'text_marketing' => 0])->assertSessionHas('success');
        $membership = StudioUser::where('user_id', $member->id)->sole();
        $this->assertSame(['profile', null], [$membership->sms_updates_source, $membership->sms_marketing_at]);
        $this->assertTrue($membership->wantsTextUpdates());
        $this->assertSame(1, DomainEvent::where('type', 'consent.sms_updates_granted')->count());
        $text = $this->texts()->sole();
        $this->assertSame(['+13035550142', "Skyline Aerial: You'll now get texts about your classes (reminders, waitlist spots, cancellations). Msg frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out."],
            [$text['To'], $text['Body']]);
        $delivery = MessageDelivery::where('kind', 'texts_confirmed')->sole();
        $this->assertSame(['sms', 'sent', 'SM00000000000000000000000000000001'], [$delivery->channel, $delivery->status, $delivery->provider_message_id]);

        // Saving again changes nothing and sends nothing; adding news and offers confirms that.
        $this->put($profile, $form + ['phone' => '(303) 555-0142', 'text_updates' => 1]);
        $this->assertSame(1, $this->texts()->count());
        $this->put($profile, $form + ['phone' => '(303) 555-0142', 'text_updates' => 1, 'text_marketing' => 1]);
        $this->assertStringContainsString("You'll now get news and offers by text.", $this->texts()->last()['Body']);

        // Unticking stops them; no text says so.
        $this->put($profile, $form + ['phone' => '(303) 555-0142']);
        $this->assertFalse(StudioUser::where('user_id', $member->id)->sole()->wantsTextUpdates());
        $this->assertSame(2, $this->texts()->count());
        $this->assertSame(1, DomainEvent::where('type', 'consent.sms_marketing_withdrawn')->count());

        // A studio without texts doesn't offer them.
        $plain = $this->studio();
        $this->userIn($plain, 'member', ['email' => 'x@example.com']);
        StudioUser::create(['studio_id' => $plain->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->get("/{$plain->slug}/account/profile")->assertOk()->assertDontSee('Texts from');
    }

    public function test_new_members_can_opt_in_when_they_join(): void
    {
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $this->get("/{$studio->slug}/join")->assertOk()->assertSee('Text me class reminders and booking updates from Skyline Aerial')->assertDontSee('Text me news and offers');
        $join = ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'password' => 'secret-pass', 'text_updates' => 1];

        $this->post("/{$studio->slug}/join", $join)->assertSessionHasErrors('phone');
        $this->assertGuest();
        $this->post("/{$studio->slug}/join", $join + ['phone' => '720 555 0188'])->assertRedirect();
        $ana = User::where('email', 'ana@example.com')->sole();
        $this->assertSame('join', StudioUser::where('user_id', $ana->id)->sole()->sms_updates_source);
        $this->assertSame('+17205550188', $this->texts()->sole()['To']);
    }

    public function test_booking_texts_go_to_members_who_opted_in_as_well_as_the_email(): void
    {
        Mail::fake();
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $parent = $this->texter($studio, ['first_name' => 'Priya']);
        $this->membership($studio, $parent);
        $maya = $this->attendee($studio, $parent, ['first_name' => 'Maya']);
        $other = $this->userIn($studio, 'member', ['phone' => '303-555-0199']); // has a number, didn't opt in
        $this->membership($studio, $other);
        $program = $this->program($studio, ['title' => 'Kids Silks']);
        $bookings = app(Bookings::class);

        // A reminder, once, a day ahead.
        $start = now()->addHours(24)->subMinutes(20);
        $bookings->book($this->classSession($program, ['starts_at' => $start]), $maya, $parent->id);
        $bookings->book($this->classSession($program, ['starts_at' => $start]), $this->attendee($studio, $other), $other->id);
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $local = $studio->local($start);
        $this->assertSame(['+13035550142' => 'Skyline Aerial: Reminder: Maya has Kids Silks '.($local->isSameDay($studio->localNow()->addDay()) ? 'tomorrow' : 'on '.$local->format('D, M j')).' at '.$local->format('g:i A').'. Your classes: '.route('studio.account', $studio)],
            $this->texts()->pluck('Body', 'To')->all());
        $this->assertSame(2, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'reminder')->count()); // the emails go either way

        // A spot opens on the waitlist.
        $full = $this->classSession($program, ['capacity' => 1, 'starts_at' => now()->addDays(4)->setTime(17, 0)]);
        $first = $bookings->book($full, $this->attendee($studio, $other), $other->id);
        $bookings->book($full, $maya, $parent->id);
        $bookings->cancel($first, $other->id);
        $this->assertStringStartsWith('Skyline Aerial: A spot opened, so Maya is in Kids Silks on ', $this->texts()->last()['Body']);
        $this->assertStringContainsString('Can\'t make it? Please cancel so someone else can have the spot', $this->texts()->last()['Body']);

        // The studio cancels a class: only the opted-in member is texted.
        $bookings->cancelSession($full);
        $this->assertStringContainsString('Kids Silks on '.$studio->local($full->starts_at)->format('D, M j \a\t g:i A').' is cancelled (Maya was booked). Any credit used is back in your account.', $this->texts()->last()['Body']);
        $this->assertSame(3, $this->texts()->count());
        $this->assertSame(0, MessageDelivery::where('channel', 'sms')->where('user_id', $other->id)->count());

        // Each can be turned off, without the Settings page turning them back on.
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->put("/{$studio->slug}/admin/texts/settings", ['text_reminders' => 1, 'text_waitlist' => 0, 'text_cancellations' => 0])->assertSessionHas('success');
        $this->put("/{$studio->slug}/admin/settings", ['name' => 'Skyline Aerial', 'accent_color' => '#c2410c', 'timezone' => 'America/Denver', 'currency' => 'usd',
            'late_cancel_hours' => 12, 'booking_opens_days_before' => 30, 'booking_closes_minutes_before' => 0, 'credit_validity_days' => 90,
            'waiver_validity_days' => 365, 'reminder_hours_before' => 24, 'minor_age' => 18, 'schedule_weeks' => 2])->assertSessionHas('success');
        $studio->refresh();
        $this->assertSame([true, false, false], [$studio->setting('text_reminders'), $studio->setting('text_waitlist'), $studio->setting('text_cancellations')]);
        $again = $this->classSession($program, ['starts_at' => now()->addDays(5)]);
        $bookings->book($again, $maya, $parent->id);
        $bookings->cancelSession($again);
        $this->assertSame(3, $this->texts()->count());

        // Replying STOP stops booking texts too; disconnecting stops everything.
        StudioUser::where('user_id', $parent->id)->update(['sms_stopped_at' => now()]);
        $bookings->book($this->classSession($program, ['starts_at' => now()->addHours(24)->subMinutes(10)]), $maya, $parent->id);
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame(3, $this->texts()->count());
    }

    public function test_automations_go_by_text_only_with_marketing_consent_and_in_the_daytime(): void
    {
        Mail::fake();
        $this->fakeTwilio();
        $studio = $this->textingStudio(['timezone' => 'America/Denver']);
        $automation = $studio->automations()->create(['trigger' => 'win_back', 'active' => true, 'channel' => 'sms'] + Automation::defaults('win_back'));
        $fan = $this->texter($studio, ['first_name' => 'Priya'], marketing: true);
        $updatesOnly = $this->texter($studio, ['first_name' => 'Jo']);
        $automations = app(Automations::class);
        $this->travelTo($studio->parseLocal(now()->toDateString().' 14:00:00'));

        $automations->send($automation->setRelation('studio', $studio), $fan, null, ['days' => 30], 'win:1');
        $automations->send($automation, $updatesOnly, null, ['days' => 30], 'win:2');
        $this->assertSame("Skyline Aerial: Hi Priya, it's been 30 days and we miss you! See what's on this week: ".route('studio.schedule', $studio).' Reply STOP to opt out.', $this->texts()->sole()['Body']);
        $this->assertSame(['sent', 'suppressed'], MessageDelivery::where('channel', 'sms')->orderBy('id')->pluck('status')->all());
        $this->assertSame([$automation->id, 'marketing', 'win:1:sms'], [MessageDelivery::first()->automation_id, MessageDelivery::first()->category, MessageDelivery::first()->dedupe_key]);
        Mail::assertNothingQueued(); // text only

        // Late in the evening, it waits for 9 AM studio time.
        Queue::fake();
        $this->travelTo($studio->parseLocal(now()->toDateString().' 21:30:00'));
        $automations->send($automation, $fan, null, ['days' => 31], 'win:3');
        Queue::assertPushed(SendText::class, fn (SendText $job) => $job->delay->equalTo($studio->parseLocal($studio->localNow()->addDay()->toDateString().' 09:00:00')) && $job->consent === 'marketing');

        // Email and text: each to those who agreed to it.
        $automation->update(['channel' => 'both']);
        $automations->send($automation, $fan, null, ['days' => 40], 'win:4');
        $this->assertSame(['email', 'sms'], MessageDelivery::where('dedupe_key', 'like', 'win:4%')->orderBy('id')->pluck('channel')->all());
    }

    public function test_staff_choose_how_each_automation_goes_and_test_the_text(): void
    {
        Mail::fake();
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $admin = $this->userIn($studio, 'admin', ['phone' => '303-555-0111', 'email' => 'cam@example.com']);
        $this->actingAs($admin);
        $page = "/{$studio->slug}/admin/automations/birthday";

        $this->get($page)->assertOk()->assertSee('Send by')->assertSee('Email and text')
            ->assertSee('Happy birthday, {attendee_first_name}! Everyone at {studio} hopes it’s a great one.', false);
        $this->put($page, ['channel' => 'sms', 'sms_body' => 'Happy birthday {nickname}!'])->assertSessionHasErrors('sms_body');
        $this->put($page, ['channel' => 'sms', 'sms_body' => ''])->assertSessionHasErrors('sms_body');
        $this->put($page, ['channel' => 'sms', 'sms_body' => 'Happy birthday, {attendee_first_name}! Book a class: {link}', 'active' => 1])->assertRedirect();
        $automation = Automation::where('trigger', 'birthday')->sole();
        $this->assertSame(['sms', 'Happy birthday, {attendee_first_name}! Book a class: {link}'], [$automation->channel, $automation->sms_body]);
        $this->get($page)->assertSee('Skyline Aerial: Happy birthday, Maya! Book a class: '.route('studio.schedule', $studio).' Reply STOP to opt out.');
        $this->get("/{$studio->slug}/admin/automations")->assertSee('Text message');

        $this->post("{$page}/test")->assertSessionHas('success', 'A test is on its way to (303) 555-0111, with sample names filled in.');
        $this->assertSame('+13035550111', $this->texts()->sole()['To']);
        Mail::assertNothingQueued();

        // Without texts connected, it can only stay as it is or go back to email.
        $studio->update(['sms_from' => null]);
        $this->get($page)->assertSee('Texts aren’t connected', false)->assertSee('connect your Twilio number');
        $this->put($page, ['channel' => 'both', 'sms_body' => 'Hi'])->assertSessionHasErrors('channel');
        $this->put($page, ['channel' => 'email', 'subject' => 'Happy birthday!', 'body' => 'Hi {first_name}'])->assertRedirect();
        $this->assertSame('email', $automation->fresh()->channel);
    }

    public function test_twilio_reports_delivery_and_failures_on_signed_requests_only(): void
    {
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $member = $this->texter($studio);
        $delivery = app(Texts::class)->send($member, $studio, 'reminder', 'Hello', 'r:1');
        $url = route('twilio.status', ['studioId' => $studio->id, 'delivery' => $delivery->id]);

        $this->post($url, ['MessageStatus' => 'delivered'])->assertForbidden();
        $this->twilioPost($url, ['MessageSid' => 'SMx', 'MessageStatus' => 'delivered'], 'ffffffffffffffffffffffffffffffff')->assertForbidden();
        $this->twilioPost($url, ['MessageSid' => 'SMx', 'MessageStatus' => 'sent'])->assertNoContent();
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->twilioPost($url, ['MessageSid' => 'SMx', 'MessageStatus' => 'delivered'])->assertNoContent();
        $this->assertSame('delivered', $delivery->fresh()->status);

        $second = app(Texts::class)->send($member, $studio, 'reminder', 'Hello again', 'r:2');
        $this->twilioPost(route('twilio.status', ['studioId' => $studio->id, 'delivery' => $second->id]), ['MessageSid' => 'SMy', 'MessageStatus' => 'undelivered', 'ErrorCode' => '30034'])->assertNoContent();
        $this->assertSame(['failed', '30034: Your number isn’t registered for US texting yet (A2P 10DLC)'], [$second->fresh()->status, $second->fresh()->error]);

        // Staff see it on the Text messages page and the member's page.
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/texts")->assertSee('Your number isn’t registered for US texting yet (A2P 10DLC)', false)->assertSee('Delivered');
        $this->get("/{$studio->slug}/admin/members/{$member->id}")->assertOk()->assertSee('Text:')->assertSee('Delivered')->assertSee('opted in')->assertSee('booking texts');

        // Another studio's signature doesn't fit.
        $other = $this->textingStudio(['twilio_auth_token' => 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee']);
        $this->twilioPost(route('twilio.status', ['studioId' => $other->id, 'delivery' => $delivery->id]), ['MessageStatus' => 'failed'])->assertForbidden();
        $this->assertSame('delivered', $delivery->fresh()->status);
    }

    public function test_replies_stop_and_start_texts_and_anything_else_reaches_the_studio(): void
    {
        Mail::fake();
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $owner = $this->userIn($studio, 'owner', ['email' => 'owner@example.com']);
        $this->userIn($studio, 'admin', ['email' => 'desk@example.com']);
        $this->userIn($studio, 'instructor', ['email' => 'coach@example.com']);
        $member = $this->texter($studio, ['first_name' => 'Priya', 'last_name' => 'Shah', 'email' => 'priya@example.com'], marketing: true);
        $url = route('twilio.incoming', ['studioId' => $studio->id]);
        $reply = fn (string $body, array $extra = []) => $this->twilioPost($url, ['MessageSid' => 'SM'.md5($body), 'From' => '+13035550142', 'To' => '+13035550100', 'Body' => $body] + $extra);

        $reply(' stop ')->assertOk()->assertHeader('Content-Type', 'text/xml; charset=utf-8')->assertSee('<Response></Response>', false);
        $membership = StudioUser::where('user_id', $member->id)->sole();
        $this->assertNotNull($membership->sms_stopped_at);
        $this->assertFalse($membership->wantsTextUpdates() || $membership->wantsTextMarketing());
        $this->assertSame('reply_stop', DomainEvent::where('type', 'consent.sms_stopped')->sole()->payload['source']);

        // On their profile: how to start again, not the boxes.
        $this->actingAs($member)->get("/{$studio->slug}/account/profile")->assertSee('text <strong>START</strong> to (303) 555-0100', false)->assertDontSee('Text me news');

        $reply('START')->assertOk();
        $membership->refresh();
        $this->assertSame([true, false, 'reply_start'], [$membership->wantsTextUpdates(), $membership->wantsTextMarketing(), $membership->sms_updates_source]);

        $reply('HELP')->assertOk();
        Mail::assertNothingQueued();

        $reply('Running 5 minutes late, sorry!')->assertOk();
        $reply('Running 5 minutes late, sorry!')->assertOk(); // Twilio retried: one email each
        $forwarded = Mail::queued(TextReply::class);
        $this->assertSame(['desk@example.com', 'owner@example.com'], $forwarded->map(fn ($m) => $m->to[0]['address'])->sort()->values()->all());
        $email = $forwarded->first();
        $this->assertSame(['Text from Priya Shah', 'Priya Shah ((303) 555-0142) texted Skyline Aerial’s number:', '“Running 5 minutes late, sorry!”', 'priya@example.com'],
            [$email->headline, $email->lines[0], $email->lines[1], $email->memberEmail]);
        $this->assertTrue($email->envelope()->hasReplyTo('priya@example.com'));
        $this->assertSame(route('studio.admin.members.show', ['studio' => $studio, 'member' => $member]), $email->actionUrl);

        // A stranger's text is forwarded with just the number; a bad signature is refused.
        $this->twilioPost($url, ['MessageSid' => 'SMz', 'From' => '+17205550000', 'Body' => 'Do you have adult classes?'])->assertOk();
        $this->assertSame('Text from (720) 555-0000', Mail::queued(TextReply::class)->last()->headline);
        $this->post($url, ['From' => '+13035550142', 'Body' => 'STOP'])->assertForbidden();
        $this->post('/twilio/999999/incoming', ['Body' => 'STOP'])->assertNotFound();
    }

    public function test_texts_are_not_sent_to_someone_who_stopped_and_a_new_number_needs_consent_again(): void
    {
        $this->fakeTwilio(['code' => 21610, 'message' => 'Attempt to send to unsubscribed recipient', 'status' => 400]);
        $studio = $this->textingStudio();
        $member = $this->texter($studio, marketing: true);

        $delivery = app(Texts::class)->send($member, $studio, 'reminder', 'Hello', 'r:1');
        $this->assertSame(['failed', '21610: They replied STOP to your number'], [$delivery->fresh()->status, $delivery->fresh()->error]);
        $this->assertNotNull(StudioUser::where('user_id', $member->id)->sole()->sms_stopped_at);
        $this->assertSame('carrier', DomainEvent::where('type', 'consent.sms_stopped')->sole()->payload['source']);

        // A new number: texts are off everywhere until they agree again.
        $other = $this->texter($studio, ['phone' => '720-555-0123']);
        $other->update(['phone' => '720-555-0999']);
        $membership = StudioUser::where('user_id', $other->id)->sole();
        $this->assertNull($membership->sms_updates_at);
        $this->assertSame('phone_changed', DomainEvent::where('type', 'consent.sms_updates_withdrawn')->sole()->payload['source']);
        $other->update(['first_name' => 'Renamed']); // nothing else changes it
        $this->assertSame(1, DomainEvent::where('type', 'consent.sms_updates_withdrawn')->count());
    }

    public function test_the_app_reads_and_changes_text_choices(): void
    {
        $this->fakeTwilio();
        $studio = $this->textingStudio();
        $member = $this->userIn($studio, 'member', ['phone' => null]);
        $base = "studios/{$studio->slug}";

        $this->api('GET', $base, $member)->assertJsonPath('data.texts.from', '(303) 555-0100');
        $this->api('GET', "{$base}/texts", $member)->assertOk()->assertJsonPath('data.available', true)->assertJsonPath('data.updates', false)
            ->assertJsonPath('data.labels.updates', 'Text me class reminders and booking updates from Skyline Aerial')
            ->assertJsonPath('data.disclosure', 'Message frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out.');
        $this->api('PATCH', "{$base}/texts", $member, ['updates' => true, 'marketing' => false])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->api('PATCH', "{$base}/texts", $member, ['updates' => true, 'marketing' => true, 'phone' => '303 555 0142'])->assertOk()
            ->assertJsonPath('data.updates', true)->assertJsonPath('data.marketing', true)->assertJsonPath('data.phone', '303 555 0142')->assertJsonPath('data.phone_textable', true);
        $this->assertSame('app', StudioUser::where('user_id', $member->id)->sole()->sms_updates_source);
        $this->assertStringContainsString('texts about your classes, and news and offers', $this->texts()->sole()['Body']);

        StudioUser::where('user_id', $member->id)->update(['sms_stopped_at' => now(), 'sms_updates_at' => null, 'sms_marketing_at' => null]);
        $this->api('PATCH', "{$base}/texts", $member, ['updates' => true, 'marketing' => false])->assertStatus(409)
            ->assertJsonPath('error.code', 'texts_stopped')->assertJsonPath('error.from', '(303) 555-0100');

        // Signing up in the app.
        $this->postJson("/api/v1/{$base}/register", ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@example.com', 'password' => 'secret-pass',
            'device_name' => 'iPhone', 'phone' => '7205550188', 'text_updates' => true])->assertSuccessful();
        $this->assertNotNull(StudioUser::where('user_id', User::where('email', 'ana@example.com')->sole()->id)->sole()->sms_updates_at);

        // A studio without texts.
        $plain = $this->studio();
        StudioUser::create(['studio_id' => $plain->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->api('GET', "studios/{$plain->slug}", $member)->assertJsonPath('data.texts', null);
        $this->api('PATCH', "studios/{$plain->slug}/texts", $member, ['updates' => true, 'marketing' => false])->assertStatus(422)->assertJsonPath('error.code', 'texts_unavailable');
    }

    public function test_the_privacy_policy_explains_texts(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('Last updated November 13, 2026')->assertSee('id="texts"', false)
            ->assertSee('Reply <strong>STOP</strong> to any text to stop all texts from that studio', false)
            ->assertSee('We don’t sell or share mobile numbers, or your agreement to texts, with anyone for their own marketing.', false)
            ->assertSee('Twilio gets your mobile number and each text', false);
        $this->get('/terms')->assertOk()->assertSee('If you text members, you do it through your own Twilio account and number', false)
            ->assertSee('its Twilio account for texts, aren’t our subprocessors', false);
    }
}
