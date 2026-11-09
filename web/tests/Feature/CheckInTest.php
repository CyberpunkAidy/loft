<?php

namespace Tests\Feature;

use App\Models\AccessCredential;
use App\Models\AccessDevice;
use App\Models\AccessEvent;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\Studio;
use App\Services\CheckInCodes;
use App\Services\CheckIns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Contactless check-in (roadmap step 8): devices, tags, rotating QR codes, the kiosk and the device API. */
class CheckInTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** A member's child, booked into a class starting in half an hour, waiver signed. */
    private function booked(Studio $studio, array $sessionAttributes = []): array
    {
        $member = $this->userIn($studio, 'member', ['first_name' => 'Pat']);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $session = $this->classSession($this->program($studio, ['title' => 'Silks']), $sessionAttributes + ['starts_at' => now()->addMinutes(30)->startOfMinute()]);
        $this->sign($studio, $maya);
        $booking = Booking::create(['studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $maya->id, 'user_id' => $member->id, 'status' => 'booked', 'booked_at' => now()]);

        return [$member, $maya, $session, $booking];
    }

    private function sign(Studio $studio, Attendee $attendee): void
    {
        $waiver = $studio->activeWaivers()->first() ?? $this->waiver($studio);
        $waiver->signatures()->create(['studio_id' => $studio->id, 'attendee_id' => $attendee->id, 'user_id' => $attendee->user_id, 'waiver_version' => $waiver->version, 'signed_name' => 'Pat', 'signed_at' => now()]);
    }

    private function device(Studio $studio, string $name = 'Front door'): array
    {
        return AccessDevice::issue($studio, $name);
    }

    private function kiosk(Studio $studio, string $token, string $credential): TestResponse
    {
        $this->withCookie("loft_kiosk_{$studio->id}", $token)->post("/{$studio->slug}/kiosk", ['credential' => $credential])->assertRedirect(url("/{$studio->slug}/kiosk"));

        return $this->get("/{$studio->slug}/kiosk")->assertOk();
    }

    private function api(string $method, string $uri, ?string $token, array $data = [], array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.$uri, $data, $headers + ($token ? ['Authorization' => "Bearer {$token}"] : []));
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $response;
    }

    public function test_staff_add_devices_and_give_out_tags(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        [$member, $maya] = $this->booked($studio);
        $leo = $this->attendee($studio, $member, ['first_name' => 'Leo']);
        $this->actingAs($owner);

        // A device's code is shown once and stored only as a hash.
        $this->post("/{$studio->slug}/admin/access/devices", ['name' => 'Front door'])->assertRedirect();
        $token = session('new_device')['token'];
        $this->assertStringStartsWith('loftdev_', $token);
        $this->get("/{$studio->slug}/admin/access")->assertOk()->assertSee('Front door is ready')->assertSee($token)->assertSee('Use this browser as the kiosk');
        $this->get("/{$studio->slug}/admin/access")->assertOk()->assertDontSee($token)->assertSee('Code …'.substr($token, -4));
        $device = AccessDevice::sole();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertFalse(DB::table('access_devices')->where('token_hash', $token)->exists());

        // Pairing a browser: the code is checked against this studio.
        $this->post("/{$studio->slug}/kiosk/pair", ['code' => 'loftdev_wrong'])->assertSessionHasErrors('code');
        $this->post('/'.$this->studio()->slug.'/kiosk/pair', ['code' => $token])->assertSessionHasErrors('code');
        $this->post("/{$studio->slug}/kiosk/pair", ['code' => $token])->assertRedirect(url("/{$studio->slug}/kiosk"))->assertCookie("loft_kiosk_{$studio->id}", $token);
        $this->withCookie("loft_kiosk_{$studio->id}", $token)->get("/{$studio->slug}/kiosk")->assertOk()->assertSee('Tap your card or fob')->assertSee('Front door');

        // Tags: however the reader writes the UID, it's the same tag; one person at a time.
        $base = "/{$studio->slug}/admin/members/{$member->id}";
        $this->get($base)->assertOk()->assertSee('Check-in tags')->assertSee('No tags yet');
        $this->post("{$base}/tags", ['attendee_id' => $maya->id, 'uid' => '04:a2:3b:4c:5d', 'label' => 'Blue fob'])->assertSessionHas('success', 'Tag …4C5D is now Maya’s.');
        $tag = AccessCredential::sole();
        $this->assertSame([AccessCredential::hash('04A23B4C5D'), '4C5D'], [$tag->value_hash, $tag->value_last4]);
        $this->assertNotSame('04A23B4C5D', $tag->value_hash);
        $this->post("{$base}/tags", ['attendee_id' => $leo->id, 'uid' => '04 A2 3B 4C 5D'])->assertSessionHas('error', 'That tag is already Maya '.$maya->last_name.'’s. Take it back from them first.');
        $this->post("{$base}/tags", ['attendee_id' => $leo->id, 'uid' => '-:-'])->assertSessionHas('error', 'That doesn’t look like a tag number. Scan the tag again.');
        $stranger = $this->attendee($studio, $this->userIn($studio));
        $this->post("{$base}/tags", ['attendee_id' => $stranger->id, 'uid' => '99887766'])->assertNotFound();
        $this->get($base)->assertOk()->assertSee('…4C5D')->assertSee('Blue fob');
        $this->post("/{$studio->slug}/admin/tags/{$tag->id}/revoke")->assertSessionHas('success');
        $this->post("{$base}/tags", ['attendee_id' => $leo->id, 'uid' => '04A23B4C5D'])->assertSessionHas('success', 'Tag …4C5D is now Leo’s.');
        $this->assertSame([1, $leo->id, null], [AccessCredential::count(), $tag->fresh()->attendee_id, $tag->fresh()->revoked_at]);

        // Removing the device: the kiosk and the API stop working at once.
        $this->post("/{$studio->slug}/admin/access/devices/{$device->id}/revoke")->assertSessionHas('success');
        $this->withCookie("loft_kiosk_{$studio->id}", $token)->get("/{$studio->slug}/kiosk")->assertOk()->assertSee('Set up this check-in kiosk');
        $this->api('GET', 'devices/me', $token)->assertUnauthorized()->assertJsonPath('error.code', 'unauthenticated');

        $this->actingAs($this->userIn($studio, 'instructor'))->get("/{$studio->slug}/admin/access")->assertForbidden();
    }

    public function test_checking_in_at_the_kiosk_with_a_tag(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        [$member, $maya, $session, $booking] = $this->booked($studio);
        [$device, $token] = $this->device($studio);
        $tag = app(CheckIns::class)->addTag($maya, '04A23B4C');
        $time = $studio->local($session->starts_at)->format('g:i A');

        $this->kiosk($studio, $token, '04:a2:3b:4c')->assertSee('Hi Maya!')->assertSee("Welcome, Maya! You’re checked in for Silks at {$time}.")
            ->assertSee('http-equiv="refresh"', false);
        $this->assertSame('attended', $booking->fresh()->status);
        $this->assertTrue(DomainEvent::where('type', 'booking.attended')->sole()->payload['first_visit']);
        $this->assertNotNull($tag->fresh()->last_used_at);
        $this->kiosk($studio, $token, '04A23B4C')->assertSee('Maya is already checked in for Silks.');
        $this->assertSame(1, DomainEvent::where('type', 'booking.attended')->count(), 'checked in once');

        $this->kiosk($studio, $token, 'FFEEDDCC')->assertSee('Not checked in')->assertSee('We don’t recognise that card.');
        $tag->update(['revoked_at' => now()]);
        $this->kiosk($studio, $token, '04A23B4C')->assertSee('We don’t recognise that card.');

        // Not now: one class ended half an hour ago, the next is two hours off (check-in opens an hour before).
        $leo = $this->attendee($studio, $member, ['first_name' => 'Leo']);
        $this->sign($studio, $leo);
        foreach ([now()->subMinutes(90), now()->addHours(2)] as $start) {
            Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($session->program, ['starts_at' => $start])->id,
                'attendee_id' => $leo->id, 'user_id' => $member->id, 'status' => 'booked', 'booked_at' => now()]);
        }
        app(CheckIns::class)->addTag($leo, 'AA11BB22');
        $this->kiosk($studio, $token, 'AA11BB22')->assertSee('Leo isn’t booked into a class right now.');

        // Waitlisted; a waiver to sign (the kiosk can't override it).
        $ava = $this->attendee($studio, $member, ['first_name' => 'Ava']);
        $this->sign($studio, $ava);
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $ava->id, 'user_id' => $member->id, 'status' => 'waitlisted', 'position' => 1, 'booked_at' => now()]);
        app(CheckIns::class)->addTag($ava, 'CC33DD44');
        $this->kiosk($studio, $token, 'CC33DD44')->assertSee('Ava is on the waitlist for Silks, not booked yet.');
        $kai = $this->attendee($studio, $member, ['first_name' => 'Kai']);
        $kaiBooking = Booking::create(['studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $kai->id, 'user_id' => $member->id, 'status' => 'booked', 'booked_at' => now()]);
        app(CheckIns::class)->addTag($kai, 'EE55FF66');
        $this->kiosk($studio, $token, 'EE55FF66')->assertSee('Kai needs to sign the waiver for Silks first.');
        $this->assertSame('booked', $kaiBooking->fresh()->status);

        // Every scan is logged.
        $this->assertSame(['checked_in', 'already_checked_in', 'unknown_credential', 'unknown_credential', 'no_booking', 'waitlisted', 'waiver_required'],
            AccessEvent::orderBy('id')->pluck('reason')->all());
        $this->assertSame([$device->id, $tag->id, $maya->id, $booking->id, 'tag', 'granted'], [AccessEvent::first()->access_device_id, AccessEvent::first()->access_credential_id,
            AccessEvent::first()->attendee_id, AccessEvent::first()->booking_id, AccessEvent::first()->method, AccessEvent::first()->result]);
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/access")->assertOk()->assertSee('Checked in')->assertSee('Card or code not recognised')->assertSee('Waiver not signed')->assertSee('Front door');
        $this->get("/{$studio->slug}/admin/access?result=denied")->assertOk()->assertDontSee('Already checked in')->assertSee('On the waitlist');
        $this->get("/{$studio->slug}/admin/checkin?session={$session->id}")->assertOk()->assertSee('Maya');
    }

    public function test_rotating_check_in_codes_and_the_device_api(): void
    {
        $studio = $this->studio();
        [$member, $maya, $session, $booking] = $this->booked($studio);
        [$device, $deviceToken] = $this->device($studio);
        $memberToken = $member->createToken('Phone', ['member'])->plainTextToken;
        $codes = app(CheckInCodes::class);

        // Members see a QR code per person, on the website and in the app.
        $this->actingAs($member)->get("/{$studio->slug}/account/check-in")->assertOk()->assertSee('Check-in code for Maya')->assertSee('<svg', false)
            ->assertSee('http-equiv="refresh"', false);
        $this->app['auth']->forgetGuards();
        $code = $this->api('GET', "studios/{$studio->slug}/attendees/{$maya->id}/check-in-code", $memberToken)->assertOk()->assertJsonPath('data.attendee_id', $maya->id)->json('data');
        $this->assertSame($codes->current($maya)['code'], $code['code']);
        $this->assertLessThanOrEqual(CheckInCodes::STEP, $code['refresh_in']);
        $theirs = $this->attendee($studio, $this->userIn($studio));
        $this->api('GET', "studios/{$studio->slug}/attendees/{$theirs->id}/check-in-code", $memberToken)->assertNotFound();

        // Tokens don't cross over: a member's isn't a device's, and the other way round.
        $this->api('GET', 'devices/me', $memberToken)->assertUnauthorized();
        $this->api('GET', 'me', $deviceToken)->assertUnauthorized();
        $this->api('GET', 'devices/me', $deviceToken)->assertOk()->assertJsonPath('data.name', 'Front door')->assertJsonPath('data.studio.slug', $studio->slug);

        // A scan over the API, safe to retry.
        $scan = fn (string $value, array $extra = [], array $headers = []) => $this->api('POST', 'devices/check-ins', $deviceToken, ['credential' => ['kind' => 'qr', 'value' => $value]] + $extra, $headers);
        $scan($code['code'], [], ['Idempotency-Key' => 'scan-1'])->assertOk()->assertJsonPath('data.result', 'granted')->assertJsonPath('data.reason', 'checked_in')
            ->assertJsonPath('data.attendee.first_name', 'Maya')->assertJsonPath('data.booking.title', 'Silks')->assertJsonPath('data.booking.status', 'attended');
        $scan($code['code'], [], ['Idempotency-Key' => 'scan-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertJsonPath('data.reason', 'checked_in');
        $this->assertSame(1, AccessEvent::count());
        // Keys are per device: another kiosk using the same key is a scan of its own.
        [, $otherToken] = $this->device($studio, 'Back door');
        $this->api('POST', 'devices/check-ins', $otherToken, ['credential' => ['kind' => 'qr', 'value' => $code['code']]], ['Idempotency-Key' => 'scan-1'])
            ->assertOk()->assertHeaderMissing('Idempotent-Replayed')->assertJsonPath('data.reason', 'already_checked_in');
        $this->assertSame(2, AccessEvent::count());

        // A screenshot stops working; forged codes and other studios' codes don't work at all.
        $this->travel(3)->minutes();
        $scan($code['code'])->assertOk()->assertJsonPath('data.result', 'denied')->assertJsonPath('data.reason', 'expired_code');
        $forged = substr($code['code'], 0, -1).($code['code'][-1] === 'a' ? 'b' : 'a');
        $scan($forged)->assertJsonPath('data.reason', 'unknown_credential');
        $other = $this->studio();
        $visitor = $this->attendee($other, $this->userIn($other));
        $scan($codes->current($visitor)['code'])->assertJsonPath('data.reason', 'unknown_credential');
        $this->assertSame(2, AccessEvent::where('result', 'granted')->count());

        // A device that was offline sends the time it scanned: a class that has ended since still counts.
        $this->travelBack();
        [, $leo, $ended, $leoBooking] = $this->booked($studio, ['starts_at' => now()->subMinutes(70)]);
        $scanned = now()->subMinutes(30);
        $scan($codes->current($leo, $scanned->getTimestamp())['code'], ['occurred_at' => $scanned->toIso8601String()])->assertJsonPath('data.result', 'granted');
        $this->assertSame('attended', $leoBooking->fresh()->status);
        $this->assertEquals($scanned->startOfSecond(), AccessEvent::latest('id')->first()->occurred_at);
        $scan($code['code'], ['occurred_at' => now()->subDays(2)->toIso8601String()])->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        $this->api('POST', 'devices/check-ins', $deviceToken, ['credential' => ['kind' => 'bluetooth', 'value' => 'x']])->assertStatus(422);
    }
}
