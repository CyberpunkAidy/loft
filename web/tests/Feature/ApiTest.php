<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Studio;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\AccountDeletion;
use App\Services\OfflinePayments;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The mobile app's API, v1 (roadmap step 7). */
class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /**
     * A request with a bearer token, and nothing else (no session). Each real request starts a fresh
     * app; here the app lives on, so the guard auth:sanctum switched to is reset after each call.
     */
    private function api(string $method, string $uri, ?string $token = null, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, $headers + ($token ? ['Authorization' => "Bearer {$token}"] : []));
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $response;
    }

    private function token(User $user): string
    {
        return $user->createToken('Test phone', ['member'])->plainTextToken;
    }

    private function assertError(TestResponse $response, int $status, string $code): TestResponse
    {
        return $response->assertStatus($status)->assertJsonPath('error.code', $code)->assertJsonStructure(['error' => ['code', 'message']]);
    }

    public function test_signing_in_and_out(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $user = $this->userIn($studio, 'member', ['email' => 'pat@example.com', 'first_name' => 'Pat']);

        $this->assertError($this->api('POST', 'auth/tokens', null, ['email' => 'pat@example.com', 'password' => 'wrong', 'device_name' => 'iPhone']), 422, 'invalid_credentials');
        $this->assertError($this->api('POST', 'auth/tokens', null, ['email' => 'pat@example.com']), 422, 'validation_failed')
            ->assertJsonStructure(['error' => ['fields' => ['password', 'device_name']]]);
        $token = $this->api('POST', 'auth/tokens', null, ['email' => 'PAT@example.com', 'password' => 'password', 'device_name' => 'Pat’s iPhone'])
            ->assertCreated()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.first_name', 'Pat')
            ->assertJsonPath('data.user.studios.0.slug', $studio->slug)->assertJsonPath('data.user.studios.0.role', 'member')
            ->json('data.token');
        $this->assertMatchesRegularExpression('/^\d+\|loft_\w{40,}$/', $token, 'prefixed, so secret scanners can spot a leaked token');

        $this->api('GET', 'me', $token)->assertOk()->assertJsonPath('data.email', 'pat@example.com')->assertJsonPath('data.studios.0.name', 'Skyline');
        $this->api('PATCH', 'me', $token, ['phone' => '303-555-0100', 'email' => 'new@example.com'])->assertOk()->assertJsonPath('data.phone', '303-555-0100')
            ->assertJsonPath('data.email', 'pat@example.com');
        $this->assertError($this->api('GET', 'me'), 401, 'unauthenticated');
        $this->assertError($this->api('GET', 'me', 'loft_not-a-real-token'), 401, 'unauthenticated');

        // A website session never signs in to the API.
        $this->actingAs($user, 'web')->getJson('/api/v1/me')->assertUnauthorized();

        // Push tokens: registered, refreshed, moved to whoever signs in on that phone.
        $this->api('POST', 'me/devices', $token, ['platform' => 'ios', 'token' => 'ExponentPushToken[abc]', 'name' => 'iPhone 15', 'app_version' => '1.0.0'])->assertCreated();
        $this->api('POST', 'me/devices', $token, ['platform' => 'ios', 'token' => 'ExponentPushToken[abc]', 'app_version' => '1.0.1'])->assertOk()->assertJsonPath('data.app_version', '1.0.1');
        $this->assertError($this->api('POST', 'me/devices', $token, ['platform' => 'windows', 'token' => 'x']), 422, 'validation_failed');
        $other = $this->userIn($studio);
        $this->api('POST', 'me/devices', $this->token($other), ['platform' => 'ios', 'token' => 'ExponentPushToken[abc]'])->assertOk();
        $this->assertSame([$other->id], UserDevice::pluck('user_id')->all());
        $this->assertError($this->api('DELETE', 'me/devices/'.UserDevice::sole()->id, $token), 404, 'not_found');
        $this->api('POST', 'me/devices', $token, ['platform' => 'android', 'token' => 'fcm-123']);

        // Signing out: this token stops working, and its push token goes.
        $this->api('DELETE', 'auth/tokens/current', $token, ['push_token' => 'fcm-123'])->assertNoContent();
        $this->assertError($this->api('GET', 'me', $token), 401, 'unauthenticated');
        $this->assertSame(0, $user->devices()->count());

        // Ten tries a minute at signing in.
        foreach (range(1, 9) as $_) {
            $this->api('POST', 'auth/tokens', null, ['email' => 'pat@example.com', 'password' => 'wrong', 'device_name' => 'x']);
        }
        $this->assertError($this->api('POST', 'auth/tokens', null, ['email' => 'pat@example.com', 'password' => 'password', 'device_name' => 'x']), 429, 'rate_limited')
            ->assertHeader('Retry-After');
    }

    public function test_tokens_are_revoked_when_the_password_changes_or_the_account_goes(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $first = $this->token($user);
        $this->api('GET', 'me', $first)->assertOk();

        $resetToken = Password::broker()->createToken($user);
        $this->post('/reset-password', ['token' => $resetToken, 'email' => 'pat@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1']);
        $this->assertError($this->api('GET', 'me', $first), 401, 'unauthenticated');

        $second = $this->token($user->fresh());
        $this->actingAs($user->fresh())->put("/{$studio->slug}/account/profile", ['first_name' => $user->first_name, 'last_name' => $user->last_name,
            'email' => 'pat@example.com', 'current_password' => 'new-password-1', 'password' => 'new-password-2', 'password_confirmation' => 'new-password-2'])->assertSessionHasNoErrors();
        $this->assertError($this->api('GET', 'me', $second), 401, 'unauthenticated');

        $third = $this->token($user->fresh());
        $user->devices()->create(['platform' => 'ios', 'token' => 'abc', 'last_seen_at' => now()]);
        app(AccountDeletion::class)->delete($user->fresh());
        $this->assertSame([0, 0], [\DB::table('personal_access_tokens')->count(), UserDevice::count()]);
        $this->assertError($this->api('GET', 'me', $third), 401, 'unauthenticated');
    }

    public function test_registering_and_joining_from_the_app(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $base = "studios/{$studio->slug}";

        $token = $this->api('POST', "{$base}/register", null, ['first_name' => 'Sam', 'last_name' => 'Lee', 'email' => 'Sam@Example.com', 'password' => 'secret-pass',
            'is_student' => true, 'marketing_email' => true, 'device_name' => 'Pixel'])->assertCreated()->assertJsonPath('data.user.studios.0.slug', $studio->slug)->json('data.token');
        $sam = User::where('email', 'sam@example.com')->sole();
        $this->assertSame(['Sam', true], [$studio->attendees()->sole()->first_name, $studio->attendees()->sole()->is_self]);
        $this->assertNotNull($studio->studioUsers()->where('user_id', $sam->id)->sole()->email_marketing_consent_at);
        $this->api('GET', "{$base}/attendees", $token)->assertOk()->assertJsonCount(1, 'data');
        $this->assertError($this->api('POST', "{$base}/register", null, ['first_name' => 'S', 'last_name' => 'L', 'email' => 'sam@example.com', 'password' => 'secret-pass', 'device_name' => 'x']), 422, 'validation_failed')
            ->assertJsonPath('error.fields.email.0', 'An account with that email already exists. Sign in, then join.');

        // Someone from another studio: not a member until they join.
        $other = $this->studio();
        $alex = $this->userIn($other, 'member');
        $alexToken = $this->token($alex);
        $this->assertError($this->api('GET', "{$base}/attendees", $alexToken), 403, 'not_a_member');
        $this->api('POST', "{$base}/join", $alexToken)->assertCreated()->assertJsonPath('data.slug', $studio->slug)->assertJsonPath('data.role', 'member');
        $this->api('POST', "{$base}/join", $alexToken)->assertOk();
        $this->api('GET', "{$base}/attendees", $alexToken)->assertOk()->assertJsonCount(0, 'data');
        $this->assertCount(2, $this->api('GET', 'me', $alexToken)->json('data.studios'));

        $this->assertError($this->api('GET', 'studios/no-such-studio'), 404, 'not_found');
        $this->api('GET', $base)->assertOk()->assertJsonPath('data.name', 'Skyline')->assertJsonPath('data.timezone', $studio->timezone)
            ->assertJsonPath('data.online_payments', false);
    }

    public function test_the_schedule_is_public_and_shows_your_own_bookings_with_a_token(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $kids = $this->program($studio, ['title' => 'Kids Silks', 'dropin_price_cents' => 2500]);
        $kids->categories()->attach($studio->categories()->where('type', 'age_group')->where('slug', 'kids')->sole());
        $adults = $this->program($studio, ['title' => 'Adult Lyra']);
        $hidden = $this->program($studio, ['title' => 'Draft', 'is_published' => false]);
        $start = $studio->localNow()->addDay()->setTime(17, 0);
        $first = $this->classSession($kids, ['starts_at' => $start->copy()->utc(), 'capacity' => 8]);
        $second = $this->classSession($adults, ['starts_at' => $start->copy()->addHours(2)->utc()]);
        $third = $this->classSession($kids, ['starts_at' => $start->copy()->addDays(2)->utc()]);
        $this->classSession($hidden, ['starts_at' => $start->copy()->utc()]);
        $this->classSession($adults, ['starts_at' => $start->copy()->addHour()->utc(), 'status' => 'cancelled']);
        $this->classSession($adults, ['starts_at' => $start->copy()->addDays(20)->utc()]);
        $base = "studios/{$studio->slug}/sessions";

        $page = $this->api('GET', "{$base}?per_page=2")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $first->id)->assertJsonPath('data.0.program.title', 'Kids Silks')->assertJsonPath('data.0.drop_in_price_cents', 2500)
            ->assertJsonPath('data.0.starts_at', $first->starts_at->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.0.starts_at_local', $start->toIso8601String())
            ->assertJsonPath('data.0.spots_left', 8)->assertJsonPath('data.0.waitlist.count', 0)
            ->assertJsonMissingPath('data.0.mine');
        $this->assertSame([$first->id, $second->id], array_column($page->json('data'), 'id'));
        $next = $page->json('meta.next_cursor');
        $this->assertNotNull($next);
        $this->assertSame([$third->id], array_column($this->api('GET', "{$base}?per_page=2&cursor={$next}")->assertOk()->json('data'), 'id'));
        $this->assertSame([$first->id, $third->id], array_column($this->api('GET', "{$base}?age_group=kids")->json('data'), 'id'));
        $this->assertCount(4, $this->api('GET', "{$base}?days=30")->json('data'));
        $this->assertError($this->api('GET', "{$base}?from=next-week"), 422, 'validation_failed');

        // With a token: the member's own bookings in each class.
        $member = $this->userIn($studio);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $booking = Booking::create(['studio_id' => $studio->id, 'class_session_id' => $first->id, 'attendee_id' => $maya->id, 'user_id' => $member->id, 'status' => 'booked', 'booked_at' => now()]);
        $someoneElse = $this->userIn($studio);
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $first->id, 'attendee_id' => $this->attendee($studio, $someoneElse)->id, 'user_id' => $someoneElse->id, 'status' => 'booked', 'booked_at' => now()]);
        $mine = $this->api('GET', $base, $this->token($member))->assertOk();
        $this->assertSame([['booking_id' => $booking->id, 'attendee_id' => $maya->id, 'status' => 'booked', 'position' => null]], $mine->json('data.0.mine'));
        $this->assertSame([], $mine->json('data.1.mine'));
        $this->assertSame(6, $mine->json('data.0.spots_left'));

        $this->assertError($this->api('GET', "{$base}/".$this->classSession($hidden)->id), 404, 'not_found');
        $other = $this->studio();
        $this->assertError($this->api('GET', "studios/{$other->slug}/sessions/{$first->id}"), 404, 'not_found');
    }

    public function test_booking_says_why_not_with_a_stable_code(): void
    {
        $studio = $this->studio();
        $member = $this->userIn($studio);
        $token = $this->token($member);
        $kid = $this->attendee($studio, $member, ['first_name' => 'Leo', 'dob' => now()->subYears(7)->toDateString()]);
        $session = $this->classSession($this->program($studio, ['title' => 'Silks', 'min_age' => 5]), ['capacity' => 1]);
        $waiver = $this->waiver($studio, ['title' => 'Release']);
        $pack = $this->plan($studio, ['name' => '5-class pack', 'kind' => 'pack', 'credits' => 5]);
        $base = "studios/{$studio->slug}";

        // The class page says what Leo can do before trying.
        $this->api('GET', "{$base}/sessions/{$session->id}", $token)->assertOk()
            ->assertJsonPath('booking.closed', false)->assertJsonPath('booking.attendees.0.attendee.first_name', 'Leo')
            ->assertJsonPath('booking.attendees.0.can_book', false)->assertJsonPath('booking.attendees.0.reason', 'waiver_required')
            ->assertJsonPath('booking.attendees.0.waiver_id', $waiver->id);
        $this->api('GET', "{$base}/sessions/{$session->id}")->assertOk()->assertJsonMissingPath('booking');

        $book = fn (array $data = [], array $headers = []) => $this->api('POST', "{$base}/sessions/{$session->id}/bookings", $token, $data + ['attendee_id' => $kid->id], $headers);
        $this->assertError($book(), 422, 'waiver_required')->assertJsonPath('error.waiver_id', $waiver->id)->assertJsonPath('error.attendee_id', $kid->id);

        // Sign it in the app.
        $this->assertError($this->api('POST', "{$base}/attendees/{$kid->id}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Parent']), 422, 'validation_failed');
        $this->api('POST', "{$base}/attendees/{$kid->id}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Parent', 'agree' => true])
            ->assertCreated()->assertJsonPath('data.waiver_id', $waiver->id)->assertJsonPath('next_waiver_id', null);

        $this->assertError($book(), 422, 'needs_plan')->assertJsonPath('error.plans.0.id', $pack->id)->assertJsonPath('error.plans.0.name', '5-class pack');

        $this->credits($studio, $member, 2);
        $key = (string) Str::uuid();
        $booked = $book([], ['Idempotency-Key' => $key])->assertCreated()->assertJsonPath('data.status', 'booked')->assertJsonPath('data.attendee.id', $kid->id)
            ->assertJsonPath('message', 'Leo is booked for Silks!');
        // A retry with the same key (a dropped connection): the same answer, no second booking.
        $book([], ['Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.id', $booked->json('data.id'));
        $this->assertSame(1, Booking::count());
        $this->assertError($book(['apparatus_id' => 5], ['Idempotency-Key' => $key]), 422, 'idempotency_key_reused');
        $this->assertError($book(), 409, 'already_booked');

        // Someone else's attendee; too young; a full class with no waitlist.
        $stranger = $this->attendee($studio, $this->userIn($studio));
        $this->assertError($book(['attendee_id' => $stranger->id]), 422, 'validation_failed')->assertJsonPath('error.fields.attendee_id.0', 'That person isn’t on your account.');
        $sign = fn (Attendee $a) => $this->api('POST', "{$base}/attendees/{$a->id}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Parent', 'agree' => true])->assertCreated();
        $sign($toddler = $this->attendee($studio, $member, ['first_name' => 'Kai', 'dob' => now()->subYears(3)->toDateString()]));
        $this->assertError($book(['attendee_id' => $toddler->id]), 422, 'age');
        $sign($sibling = $this->attendee($studio, $member, ['first_name' => 'Ava', 'dob' => now()->subYears(9)->toDateString()]));
        $session->update(['waitlist_capacity' => 0]);
        $this->assertError($book(['attendee_id' => $sibling->id]), 422, 'full');
        $options = $this->api('GET', "{$base}/sessions/{$session->id}", $token)->assertOk()->assertJsonPath('booking.closed_code', 'full')->json('booking.attendees');
        $this->assertSame(['Ava' => 'full', 'Kai' => 'full', 'Leo' => 'already_booked'], collect($options)->mapWithKeys(fn ($o) => [$o['attendee']['first_name'] => $o['reason']])->sortKeys()->all());

        // Lists (only theirs) and cancelling.
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $stranger->id, 'user_id' => $stranger->user_id, 'status' => 'waitlisted', 'position' => 1, 'booked_at' => now()]);
        $this->api('GET', "{$base}/bookings", $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.session.title', 'Silks')
            ->assertJsonPath('data.0.can_cancel', true);
        $this->api('GET', "{$base}/bookings?when=past", $token)->assertOk()->assertJsonCount(0, 'data');
        $id = $booked->json('data.id');
        $this->assertError($this->api('DELETE', "{$base}/bookings/{$id}", $this->token($this->userIn($studio))), 403, 'forbidden');
        $this->api('DELETE', "{$base}/bookings/{$id}", $token)->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('credit_refunded', true);
        $this->assertSame(2, Entitlement::sole()->credits_remaining);
        $this->assertError($this->api('DELETE', "studios/{$this->studio()->slug}/bookings/{$id}", $token), 404, 'not_found'); // another studio's URL can't reach it
    }

    public function test_booking_lists_page_through_in_class_order(): void
    {
        $studio = $this->studio();
        $member = $this->userIn($studio);
        $kid = $this->attendee($studio, $member);
        $program = $this->program($studio, ['title' => 'Silks']);
        $times = [5, 1, 3, -2, -6]; // days from now, booked in this order
        foreach ($times as $days) {
            Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($program, ['starts_at' => now()->addDays($days)->setTime(17, 0)])->id,
                'attendee_id' => $kid->id, 'user_id' => $member->id, 'status' => $days > 0 ? 'booked' : 'attended', 'booked_at' => now()]);
        }
        $token = $this->token($member);
        $base = "studios/{$studio->slug}/bookings";
        $day = fn (array $rows) => array_map(fn ($r) => (int) round(now()->diffInDays(Carbon::parse($r['session']['starts_at']), false)), $rows);

        $first = $this->api('GET', "{$base}?per_page=2", $token)->assertOk();
        $second = $this->api('GET', "{$base}?per_page=2&cursor=".$first->json('meta.next_cursor'), $token)->assertOk();
        $this->assertSame([1, 3, 5], [...$day($first->json('data')), ...$day($second->json('data'))]);
        $this->assertNull($second->json('meta.next_cursor'));
        $this->assertSame([-2, -6], $day($this->api('GET', "{$base}?when=past", $token)->json('data')));
    }

    public function test_family_waivers_and_purchases(): void
    {
        $studio = $this->studio();
        $member = $this->userIn($studio);
        $token = $this->token($member);
        $base = "studios/{$studio->slug}";

        $leo = $this->api('POST', "{$base}/attendees", $token, ['first_name' => 'Leo', 'last_name' => 'Shah', 'dob' => now()->subYears(7)->toDateString(), 'medical_notes' => 'Asthma'])
            ->assertCreated()->assertJsonPath('data.is_minor', true)->assertJsonPath('data.is_self', false)->json('data.id');
        $this->assertError($this->api('POST', "{$base}/attendees", $token, ['first_name' => 'X', 'dob' => 'tomorrow']), 422, 'validation_failed');
        $this->api('PATCH', "{$base}/attendees/{$leo}", $token, ['emergency_contact' => 'Dev 303-555-0143'])->assertOk()
            ->assertJsonPath('data.emergency_contact', 'Dev 303-555-0143')->assertJsonPath('data.first_name', 'Leo');
        $theirs = $this->attendee($studio, $this->userIn($studio));
        $this->assertError($this->api('PATCH', "{$base}/attendees/{$theirs->id}", $token, ['first_name' => 'Hacked']), 404, 'not_found');
        $this->assertError($this->api('DELETE', "{$base}/attendees/{$theirs->id}", $token), 404, 'not_found');

        $waiver = $this->waiver($studio, ['title' => 'Release']);
        $this->api('GET', "{$base}/waivers", $token)->assertOk()->assertJsonPath('data.0.title', 'Release')->assertJsonPath('data.0.applies_to_every_class', true)
            ->assertJsonPath('data.0.attendees.0.attendee_id', $leo)->assertJsonPath('data.0.attendees.0.signed', false)->assertJsonPath('data.0.attendees.0.needed', true);
        $this->assertError($this->api('POST', "{$base}/attendees/{$theirs->id}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Shah', 'agree' => '1']), 404, 'not_found');
        $this->api('POST', "{$base}/attendees/{$leo}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Shah', 'agree' => '1'])->assertCreated();
        $this->api('GET', "{$base}/waivers", $token)->assertJsonPath('data.0.attendees.0.signed', true)->assertJsonPath('data.0.attendees.0.needed', false);
        $waiver->update(['is_active' => false]);
        $studio->forgetActiveWaivers();
        $this->assertError($this->api('POST', "{$base}/attendees/{$leo}/waiver-signatures", $token, ['waiver_id' => $waiver->id, 'signed_name' => 'Pat Shah', 'agree' => '1']), 422, 'waiver_not_in_force');

        $this->api('DELETE', "{$base}/attendees/{$leo}", $token)->assertNoContent();
        $this->assertSame('archived', Attendee::find($leo)->status);
        $this->api('GET', "{$base}/attendees", $token)->assertJsonCount(0, 'data');

        // What they hold and what they've bought.
        $owner = $this->userIn($studio, 'owner');
        $pack = $this->plan($studio, ['name' => '10-Class Pack', 'kind' => 'pack', 'credits' => 10, 'price_cents' => 15000]);
        app(OfflinePayments::class)->record($pack, $member, $owner, ['method' => 'cash', 'paid_on' => $studio->localNow()->toDateString()]);
        Order::create(['studio_id' => $studio->id, 'user_id' => $member->id, 'channel' => 'online', 'method' => 'stripe', 'status' => 'pending',
            'currency' => 'usd', 'subtotal_cents' => 100, 'total_cents' => 100]);
        $this->api('GET', "{$base}/entitlements", $token)->assertOk()->assertJsonPath('data.0.plan.name', '10-Class Pack')->assertJsonPath('data.0.credits_remaining', 10);
        $this->api('GET', "{$base}/orders", $token)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 1001)
            ->assertJsonPath('data.0.items.0.description', '10-Class Pack')->assertJsonPath('data.0.method_label', 'Cash');
        $this->api('GET', "studios/{$studio->slug}/plans")->assertOk()->assertJsonPath('data.0.name', '10-Class Pack')->assertJsonPath('data.0.can_buy_online', false);
    }

    public function test_buying_a_plan_from_the_app(): void
    {
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');

            return match (true) {
                $path === 'customers' => Http::response(['id' => 'cus_1']),
                $path === 'checkout/sessions' => Http::response(['id' => 'cs_app', 'url' => 'https://checkout.stripe.com/c/pay/cs_app', 'expires_at' => 1794200000]),
                $path === 'checkout/sessions/cs_app' => Http::response(['id' => 'cs_app', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1',
                    'amount_total' => 9000, 'currency' => 'usd', 'payment_intent' => 'pi_1', 'metadata' => $this->checkoutMetadata]),
                default => Http::response(['error' => ['message' => 'No such object']], 404),
            };
        }]);
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $member = $this->userIn($studio);
        $token = $this->token($member);
        $kid = $this->attendee($studio, $member, ['first_name' => 'Leo']);
        $this->waiver($studio)->signatures()->create(['studio_id' => $studio->id, 'attendee_id' => $kid->id, 'user_id' => $member->id, 'waiver_version' => 1,
            'signed_name' => 'Pat', 'signed_at' => now()]);
        $session = $this->classSession($this->program($studio, ['title' => 'Silks']));
        $pack = $this->plan($studio, ['name' => '5-class pack', 'kind' => 'pack', 'credits' => 5, 'price_cents' => 9000, 'stripe_price_id' => 'price_1', 'stripe_product_id' => 'prod_1']);
        $base = "studios/{$studio->slug}";

        $this->assertError($this->api('POST', "{$base}/checkouts", $token, ['plan_id' => 999]), 422, 'validation_failed');
        $start = $this->api('POST', "{$base}/checkouts", $token, ['plan_id' => $pack->id, 'session_id' => $session->id, 'attendee_id' => $kid->id], ['Idempotency-Key' => 'buy-1'])
            ->assertCreated()->assertJsonPath('data.id', 'cs_app')->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_app')
            ->assertJsonPath('data.expires_at', '2026-11-09T04:53:20Z');
        $return = url("/{$studio->slug}/checkout/return");
        $this->assertSame($return, $start->json('data.return_url'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['success_url'] === $return.'?session_id={CHECKOUT_SESSION_ID}'
            && $r['cancel_url'] === $return.'?cancelled=1' && (int) $r['metadata']['session_id'] === $session->id && (int) $r['metadata']['attendee_id'] === $kid->id);
        $this->checkoutMetadata = ['loft_studio_id' => $studio->id, 'loft_plan_id' => $pack->id, 'loft_user_id' => $member->id, 'session_id' => $session->id, 'attendee_id' => $kid->id];

        // Back in the app: paid, the plan recorded and the class booked, once.
        $this->get("/{$studio->slug}/checkout/return?session_id=cs_app")->assertOk()->assertSee('Thank you!');
        $this->get("/{$studio->slug}/checkout/return?cancelled=1")->assertOk()->assertSee('Nothing was charged');
        $this->api('GET', "{$base}/checkouts/cs_app", $token)->assertOk()->assertJsonPath('data.fulfilled', true)->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.entitlement.plan.name', '5-class pack')->assertJsonPath('data.booking.status', 'booked')->assertJsonPath('data.booking.attendee.first_name', 'Leo');
        $this->api('GET', "{$base}/checkouts/cs_app", $token)->assertOk();
        $this->assertSame([1, 1, 1], [Entitlement::count(), Booking::count(), Order::count()]);
        $this->assertError($this->api('GET', "{$base}/checkouts/cs_app", $this->token($this->userIn($studio))), 404, 'not_found');
        $this->assertError($this->api('GET', "{$base}/checkouts/not-a-session", $token), 404, 'not_found');

        // No online payments: a stable code, not a Stripe call.
        $studio->update(['stripe_charges_enabled' => false]);
        $this->assertError($this->api('POST', "{$base}/checkouts", $token, ['plan_id' => $pack->id]), 422, 'checkout_unavailable');
    }

    /** The metadata the faked Checkout Session carries once it's been created. */
    private array $checkoutMetadata = [];
}
