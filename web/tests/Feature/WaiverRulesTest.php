<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Studio;
use App\Models\User;
use App\Models\Waiver;
use App\Models\WaiverOverride;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Bookings;
use App\Services\Waivers;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Roadmap step 1: program waivers, versions, enforcement everywhere, overrides, downloads. */
class WaiverRulesTest extends TestCase
{
    private function waivers(): Waivers
    {
        return app(Waivers::class);
    }

    private function sign(Studio $studio, $attendee, Waiver $waiver, ?User $signer = null): WaiverSignature
    {
        return $this->waivers()->sign($studio, $attendee, $waiver->fresh(), $signer ?? $attendee->user, 'Signer Name', null, '127.0.0.1', 'test');
    }

    private function programWaiver(Studio $studio, $program, array $attributes = []): Waiver
    {
        $waiver = $this->waiver($studio, ['title' => 'Aerial liability', 'scope' => 'program'] + $attributes);
        $waiver->programs()->attach($program->id);
        $studio->forgetActiveWaivers();

        return $waiver;
    }

    public function test_program_waivers_apply_only_to_their_classes(): void
    {
        $studio = $this->studio();
        $general = $this->waiver($studio, ['title' => 'General release']);
        $aerial = $this->program($studio, ['title' => 'Silks']);
        $stretch = $this->program($studio, ['title' => 'Stretch']);
        $aerialWaiver = $this->programWaiver($studio, $aerial);
        $user = $this->userIn($studio);
        $this->credits($studio, $user, 10);
        $attendee = $this->attendee($studio, $user);
        $this->sign($studio, $attendee, $general);

        // Stretch needs only the general waiver; Silks needs the aerial one too.
        $this->assertTrue($this->waivers()->isSigned($studio, $attendee, $stretch));
        $this->assertFalse($this->waivers()->isSigned($studio, $attendee, $aerial));
        $this->assertSame('booked', app(Bookings::class)->book($this->classSession($stretch), $attendee, $user->id)->status);

        $silks = $this->classSession($aerial);
        $this->actingAs($user)->post("/{$studio->slug}/book/{$silks->id}", ['attendee_id' => $attendee->id])
            ->assertRedirect("/{$studio->slug}/waiver/{$attendee->id}?waiver={$aerialWaiver->id}&return=%2F{$studio->slug}%2Fbook%2F{$silks->id}%3Fattendee%3D{$attendee->id}");
        $this->get("/{$studio->slug}/waiver/{$attendee->id}?waiver={$aerialWaiver->id}")->assertOk()->assertSee('Aerial liability')->assertSee('needed for Silks');

        $this->post("/{$studio->slug}/waiver/{$attendee->id}", ['waiver_id' => $aerialWaiver->id, 'signed_name' => 'Pat Doe', 'agree' => '1', 'return' => "/{$studio->slug}/book/{$silks->id}"])
            ->assertRedirect("/{$studio->slug}/book/{$silks->id}");
        $this->post("/{$studio->slug}/book/{$silks->id}", ['attendee_id' => $attendee->id])->assertRedirect("/{$studio->slug}/account");

        // The account's Waivers tab lists both, with what each is for.
        $this->get("/{$studio->slug}/account/waivers")->assertOk()->assertSee('General release')->assertSee('Only for Silks');
    }

    public function test_signing_walks_through_every_waiver_still_needed(): void
    {
        $studio = $this->studio();
        $first = $this->waiver($studio, ['title' => 'First']);
        $second = $this->waiver($studio, ['title' => 'Second']);
        $user = $this->userIn($studio);
        $attendee = $this->attendee($studio, $user);
        $this->actingAs($user);

        $this->get("/{$studio->slug}/waiver/{$attendee->id}")->assertSee('First');
        $this->post("/{$studio->slug}/waiver/{$attendee->id}", ['waiver_id' => $first->id, 'signed_name' => 'Pat Doe', 'agree' => '1'])
            ->assertRedirect("/{$studio->slug}/waiver/{$attendee->id}?waiver={$second->id}")
            ->assertSessionHas('success', "Signed “First” for {$attendee->first_name}. One more to go.");
        $this->post("/{$studio->slug}/waiver/{$attendee->id}", ['waiver_id' => $second->id, 'signed_name' => 'Pat Doe', 'agree' => '1'])
            ->assertRedirect("/{$studio->slug}/account/waivers");
        $this->assertTrue($this->waivers()->isSigned($studio, $attendee));
    }

    public function test_check_in_needs_a_waiver_or_a_reason(): void
    {
        $studio = $this->studio();
        $this->waiver($studio);
        $staff = $this->userIn($studio, 'instructor');
        $member = $this->userIn($studio);
        $session = $this->classSession($this->program($studio), ['starts_at' => now()->addHour()]);
        $booking = Booking::create([
            'studio_id' => $studio->id, 'class_session_id' => $session->id, 'attendee_id' => $this->attendee($studio, $member, ['first_name' => 'Maya'])->id,
            'user_id' => $member->id, 'status' => 'booked', 'booked_at' => now(),
        ]);
        $this->actingAs($staff);

        $this->get("/{$studio->slug}/admin/checkin?date=".$studio->local($session->starts_at)->toDateString()."&session={$session->id}")
            ->assertOk()->assertSee('No waiver')->assertSee('Check in anyway');
        $this->post("/{$studio->slug}/admin/checkin/{$booking->id}", ['status' => 'attended'])
            ->assertSessionHas('error', 'Maya hasn’t signed the waiver this class needs. Have them sign it, or check them in with a reason.');
        $this->assertSame('booked', $booking->fresh()->status);

        $this->post("/{$studio->slug}/admin/checkin/{$booking->id}", ['status' => 'attended', 'override_reason' => 'Signed a paper copy']);
        $this->assertSame('attended', $booking->fresh()->status);
        $override = WaiverOverride::sole();
        $this->assertSame([$booking->id, $staff->id, 'Signed a paper copy'], [$override->booking_id, $override->staff_user_id, $override->reason]);
        // No-shows don't need a waiver.
        $this->post("/{$studio->slug}/admin/checkin/{$booking->id}", ['status' => 'no_show']);
        $this->assertSame('no_show', $booking->fresh()->status);
    }

    public function test_staff_add_someone_unsigned_only_with_a_reason(): void
    {
        $studio = $this->studio();
        $this->waiver($studio, ['title' => 'Release']);
        $member = $this->userIn($studio);
        $kid = $this->attendee($studio, $member, ['first_name' => 'Leo', 'last_name' => 'Shah']);
        $session = $this->classSession($this->program($studio));
        $this->actingAs($this->userIn($studio, 'admin'));

        $this->post("/{$studio->slug}/admin/sessions/{$session->id}/bookings", ['attendee_id' => $kid->id, 'comp' => '1'])
            ->assertSessionHas('error', 'Leo Shah hasn’t signed “Release”. To add them anyway, give a reason under “No signed waiver?”.');
        $this->post("/{$studio->slug}/admin/sessions/{$session->id}/bookings", ['attendee_id' => $kid->id, 'comp' => '1', 'waiver_override' => 'Parent signing at the desk'])
            ->assertSessionHas('success');
        $this->assertSame('Parent signing at the desk', WaiverOverride::sole()->reason);
    }

    public function test_the_waitlist_skips_people_whose_waiver_lapsed_until_they_sign(): void
    {
        $studio = $this->studio();
        $waiver = $this->waiver($studio, ['body' => 'Version one']);
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        [$a, $b] = [$this->userIn($studio), $this->userIn($studio)];
        $this->credits($studio, $a);
        $this->credits($studio, $b);
        [$alice, $bea] = [$this->attendee($studio, $a), $this->attendee($studio, $b)];
        $this->sign($studio, $alice, $waiver);
        $this->sign($studio, $bea, $waiver);
        $bookings = app(Bookings::class);
        $first = $bookings->book($session, $alice, $a->id);
        $waiting = $bookings->book($session, $bea, $b->id);
        $this->assertSame('waitlisted', $waiting->status);

        // A new version: Bea's signature no longer counts, so the freed seat waits for her.
        $this->waivers()->saveText($waiver, $waiver->title, 'Version two');
        $studio->forgetActiveWaivers();
        $bookings->cancel($first, $a->id);
        $this->assertSame('waitlisted', $waiting->fresh()->status);

        // Signing the new version moves her in.
        $this->actingAs($b)->post("/{$studio->slug}/waiver/{$bea->id}", ['waiver_id' => $waiver->id, 'signed_name' => 'Bea Signer', 'agree' => '1']);
        $this->assertSame('booked', $waiting->fresh()->status);
    }

    public function test_signed_text_is_never_edited_in_place(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $attendee = $this->attendee($studio, $user);
        $this->actingAs($this->userIn($studio, 'owner'));

        $this->post("/{$studio->slug}/admin/waivers", ['title' => 'Release', 'body' => 'Draft', 'scope' => 'studio', 'is_active' => '1']);
        $waiver = Waiver::sole();
        // Nobody has signed: editing changes version 1 in place.
        $this->put("/{$studio->slug}/admin/waivers/{$waiver->id}", ['title' => 'Release', 'body' => 'Final terms', 'scope' => 'studio', 'is_active' => '1'])
            ->assertSessionHas('success', 'Waiver saved.');
        $this->assertSame([1, 'Final terms'], [$waiver->fresh()->version, WaiverVersion::sole()->body]);

        $signature = $this->sign($studio, $attendee, $waiver, $user);
        $this->assertSame(hash('sha256', 'Final terms'), $signature->body_sha256);

        // Someone signed: changing the text publishes version 2, and version 1's text stays as signed.
        $this->put("/{$studio->slug}/admin/waivers/{$waiver->id}", ['title' => 'Release', 'body' => 'Changed terms', 'scope' => 'studio', 'is_active' => '1'])
            ->assertSessionHas('success', 'Published version 2. Everyone signs again before their next class.');
        $this->assertSame(2, $waiver->fresh()->version);
        $this->assertSame(['Final terms', 'Changed terms'], WaiverVersion::orderBy('version')->pluck('body')->all());
        $this->assertSame('Final terms', $signature->fresh()->version->body);
        $this->assertFalse($this->waivers()->isSigned($studio, $attendee));
    }

    public function test_a_waiver_can_set_its_own_validity_and_be_turned_off(): void
    {
        $studio = $this->studio(); // studio setting: 365 days
        $waiver = $this->waiver($studio, ['validity_days' => 30]);
        $attendee = $this->attendee($studio, $this->userIn($studio));
        $this->sign($studio, $attendee, $waiver)->update(['signed_at' => now()->subDays(31)]);
        $this->assertFalse($this->waivers()->isSigned($studio, $attendee));

        $this->actingAs($this->userIn($studio, 'admin'))->post("/{$studio->slug}/admin/waivers/{$waiver->id}/activate")
            ->assertSessionHas('success', "“{$waiver->title}” is turned off.");
        $studio->forgetActiveWaivers();
        $this->assertTrue($this->waivers()->isSigned($studio, $attendee));
    }

    public function test_admins_download_every_signed_waiver(): void
    {
        Storage::fake('local');
        $studio = $this->studio(['name' => 'Skyline Aerial']);
        $waiver = $this->waiver($studio, ['title' => 'Release', 'body' => 'The terms']);
        $user = $this->userIn($studio, 'member', ['email' => 'pat@example.com']);
        $attendee = $this->attendee($studio, $user, ['first_name' => '=SUM(A1)', 'last_name' => 'Kid']);
        $png = 'data:image/png;base64,'.base64_encode("\x89PNG\r\n\x1a\n".str_repeat("\0", 20));
        $signature = $this->waivers()->sign($studio, $attendee, $waiver, $user, 'Pat Parent', $png, '203.0.113.9', 'Firefox');

        $this->actingAs($this->userIn($studio, 'instructor'))->get("/{$studio->slug}/admin/waivers/download")->assertForbidden();

        $response = $this->actingAs($this->userIn($studio, 'admin'))->get("/{$studio->slug}/admin/waivers/download");
        $response->assertOk()->assertDownload('skyline-aerial-signed-waivers-'.$studio->localNow()->format('Y-m-d').'.zip');

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $csv = array_map('str_getcsv', explode("\n", trim($zip->getFromName('signatures.csv'))));
        $this->assertSame('Signed at', $csv[0][0]);
        $row = array_combine($csv[0], $csv[1]);
        $this->assertSame(["'=SUM(A1) Kid", 'Pat Parent', 'guardian', 'pat@example.com', '203.0.113.9', hash('sha256', 'The terms')], [
            $row['Attendee'], $row['Signed by (typed name)'], $row['Relationship'], $row['Account email'], $row['IP address'], $row['Text SHA-256'],
        ]);
        $this->assertSame("Release\n\nThe terms\n", $zip->getFromName($row['Text file']));
        $this->assertSame("drawings/signature-{$signature->id}.png", $row['Drawn signature']);
        $this->assertNotFalse($zip->getFromName($row['Drawn signature']));
        $this->assertStringContainsString('Signed waivers for Skyline Aerial', $zip->getFromName('README.txt'));
        $zip->close();
    }
}
