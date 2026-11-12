<?php

namespace Tests\Feature;

use App\Models\Attendee;
use App\Models\DomainEvent;
use App\Models\Studio;
use App\Models\User;
use App\Services\Bookings;
use App\Support\BookingException;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Decision 45: the injury and photo questions, asked when someone is added and before booking. */
class PersonQuestionsTest extends TestCase
{
    private function api(string $method, string $uri, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.ltrim($uri, '/'), $data, ['Authorization' => "Bearer {$token}"]);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');

        return $response;
    }

    private function token(User $user): string
    {
        return $user->createToken('Test phone', ['member'])->plainTextToken;
    }

    private function settings(Studio $studio, array $settings): Studio
    {
        $studio->update(['settings' => $settings + ($studio->settings ?? [])]);

        return $studio->fresh();
    }

    public function test_adding_someone_asks_both_questions_and_keeps_the_answers(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $parent = $this->userIn($studio);
        $this->actingAs($parent);

        $this->get("/{$studio->slug}/account/family/new")->assertOk()
            ->assertSee('Injuries and health')->assertSee('Any injuries, health conditions or allergies')
            ->assertSee('Photos and video')->assertSee('Skyline sometimes takes photos')
            ->assertSee('Yes, including on social media')->assertSee('Only inside the studio (not online)')->assertSee('No photos or video');

        $base = ['first_name' => 'Leo', 'last_name' => 'Shah'];
        $this->post("/{$studio->slug}/account/family", $base)->assertSessionHasErrors(['has_health_notes' => 'Choose yes or no.', 'photo_consent' => 'Choose one.']);
        $this->post("/{$studio->slug}/account/family", $base + ['has_health_notes' => '1', 'photo_consent' => 'studio'])
            ->assertSessionHasErrors(['medical_notes' => 'Tell us about the injuries or conditions.']);
        $this->post("/{$studio->slug}/account/family", $base + ['has_health_notes' => '1', 'medical_notes' => 'Sprained left wrist', 'photo_consent' => 'everywhere'])
            ->assertSessionHasErrors('photo_consent');
        $this->assertSame(0, Attendee::count());

        $this->post("/{$studio->slug}/account/family", $base + ['has_health_notes' => '1', 'medical_notes' => ' Sprained left wrist ', 'photo_consent' => 'studio'])
            ->assertRedirect("/{$studio->slug}/account/family");
        $leo = Attendee::sole();
        $this->assertSame(['Sprained left wrist', 'studio', $parent->id, []], [$leo->medical_notes, $leo->photo_consent, $leo->photo_consent_by, $leo->questionsNeeded($studio)]);
        $this->assertTrue($leo->hasHealthNotes());
        $this->assertNotNull($leo->photo_consent_at);

        // Changing the answers any time: "no" clears the details; a new photo answer is kept in the history.
        $this->get("/{$studio->slug}/account/family/{$leo->id}")->assertOk()->assertSee('Sprained left wrist');
        $this->put("/{$studio->slug}/account/family/{$leo->id}", $base + ['has_health_notes' => '0', 'medical_notes' => 'Sprained left wrist', 'photo_consent' => 'none'])->assertRedirect();
        $leo->refresh();
        $this->assertSame([null, false, 'none'], [$leo->medical_notes, $leo->hasHealthNotes(), $leo->photo_consent]);
        $this->assertSame(
            ['Photos and video: only inside the studio (not online)', 'Photos and video: no photos or video (was: only inside the studio (not online))'],
            DomainEvent::where('type', 'consent.photos_answered')->orderBy('id')->get()->map->label()->all(),
        );
        $this->assertSame([$leo->id], DomainEvent::where('type', 'consent.photos_answered')->distinct()->pluck('attendee_id')->all());
    }

    public function test_someone_not_asked_yet_answers_before_booking_then_comes_back_to_book(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $parent = $this->userIn($studio);
        $this->credits($studio, $parent);
        $leo = $this->attendee($studio, $parent, ['first_name' => 'Leo', 'health_answered_at' => null, 'photo_consent' => null, 'photo_consent_at' => null]);
        $session = $this->classSession($this->program($studio));
        $this->waiver($studio, ['title' => 'Release']);
        $this->actingAs($parent);

        $this->get("/{$studio->slug}/book/{$session->id}")->assertOk()->assertSee('We’ll ask two quick questions and for the waiver first');

        $back = "/{$studio->slug}/book/{$session->id}?attendee={$leo->id}";
        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $leo->id])
            ->assertRedirect("/{$studio->slug}/account/family/{$leo->id}/questions?return=".urlencode($back));
        $this->get("/{$studio->slug}/account/family/{$leo->id}/questions?return=".urlencode($back))->assertOk()
            ->assertSee('Two quick questions about Leo')->assertSee('Skyline asks everyone before they book')->assertSee('Save and continue');

        $this->put("/{$studio->slug}/account/family/{$leo->id}/questions", ['return' => $back])->assertSessionHasErrors(['has_health_notes', 'photo_consent']);
        $this->put("/{$studio->slug}/account/family/{$leo->id}/questions", ['has_health_notes' => '0', 'photo_consent' => 'public', 'return' => $back])->assertRedirect($back);
        $this->assertSame([], $leo->fresh()->questionsNeeded($studio));

        // Back on the booking page, Leo is still chosen and only the waiver is left.
        $page = $this->get($back)->assertOk()->assertSee('We’ll ask you to sign the waiver first');
        $this->assertMatchesRegularExpression('/value="'.$leo->id.'"\s+checked/', $page->getContent());
        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $leo->id])->assertRedirectContains("/{$studio->slug}/waiver/{$leo->id}");
    }

    public function test_staff_bookings_and_paid_checkouts_are_not_held_up_by_the_questions(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $sam = $this->attendee($studio, $user, ['first_name' => 'Sam', 'is_self' => true, 'health_answered_at' => null]);
        $bookings = app(Bookings::class);

        try {
            $bookings->book($this->classSession($this->program($studio)), $sam, $user->id);
            $this->fail('A member booking should ask first.');
        } catch (BookingException $e) {
            $this->assertSame(['questions', ['health'], 'Answer a quick question before booking.'], [$e->reason, $e->context['questions'], $e->getMessage()]);
        }
        $this->assertSame('booked', $bookings->book($this->classSession($this->program($studio)), $sam, $user->id, 'admin')->status);
        $this->assertSame('booked', $bookings->book($this->classSession($this->program($studio)), $sam, $user->id, skipQuestions: true)->status);
    }

    public function test_studios_can_turn_either_question_off(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner)->get("/{$studio->slug}/admin/settings")->assertOk()
            ->assertSee('Questions for each person')->assertSee('Ask about injuries and health conditions')->assertSee('Ask whether you may take photos and video');
        $this->assertSame(['health', 'photos'], Attendee::questionsAsked($studio));

        $valid = [
            'name' => $studio->name, 'accent_color' => '#0F766E', 'timezone' => 'America/Denver', 'currency' => 'usd',
            'late_cancel_hours' => 6, 'booking_opens_days_before' => 14, 'booking_closes_minutes_before' => 30, 'credit_validity_days' => 60,
            'waiver_validity_days' => 0, 'reminder_hours_before' => 12, 'minor_age' => 18, 'schedule_weeks' => 2,
        ];
        $this->put("/{$studio->slug}/admin/settings", $valid + ['ask_health' => '0', 'ask_photo_consent' => '1'])->assertSessionHas('success');
        $studio->refresh();
        $this->assertSame(['photos'], Attendee::questionsAsked($studio));

        // Only the photo question now; medical notes go back to a plain, optional field.
        $member = $this->userIn($studio);
        $this->actingAs($member)->get("/{$studio->slug}/account/family/new")->assertOk()
            ->assertDontSee('Injuries and health')->assertSee('Medical notes / allergies')->assertSee('Photos and video');
        $this->post("/{$studio->slug}/account/family", ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'medical_notes' => 'Asthma', 'photo_consent' => 'public'])->assertRedirect();
        $ana = Attendee::where('first_name', 'Ana')->sole();
        $this->assertSame(['Asthma', null, []], [$ana->medical_notes, $ana->health_answered_at, $ana->questionsNeeded($studio)]);

        // Both off: nobody is asked, and booking goes straight through.
        $studio = $this->settings($studio, ['ask_health' => false, 'ask_photo_consent' => false]);
        $this->credits($studio, $member);
        $kid = $this->attendee($studio, $member, ['health_answered_at' => null, 'photo_consent' => null, 'photo_consent_at' => null]);
        $this->assertSame([], $kid->questionsNeeded($studio));
        $this->assertSame('booked', app(Bookings::class)->book($this->classSession($this->program($studio)), $kid, $member->id)->status);
        $this->get("/{$studio->slug}/account/family/{$kid->id}/questions")->assertRedirect("/{$studio->slug}/account/family");
    }

    public function test_the_app_can_answer_when_adding_someone_or_on_the_way_to_a_booking(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $parent = $this->userIn($studio);
        $this->credits($studio, $parent);
        $token = $this->token($parent);
        $base = "studios/{$studio->slug}";

        $this->api('GET', $base, $token)->assertJsonPath('data.policies.questions', ['health', 'photos']);

        // Adding without the answers is allowed (an app that doesn't ask them can still add people)…
        $leo = $this->api('POST', "{$base}/attendees", $token, ['first_name' => 'Leo', 'last_name' => 'Shah'])->assertCreated()
            ->assertJsonPath('data.has_health_notes', null)->assertJsonPath('data.photo_consent', null)
            ->assertJsonPath('data.questions_needed', ['health', 'photos'])->json('data.id');

        // …but booking them answers questions_required, with what to ask.
        $session = $this->classSession($this->program($studio));
        $this->api('GET', "{$base}/sessions/{$session->id}", $token)
            ->assertJsonPath('booking.attendees.0.can_book', false)->assertJsonPath('booking.attendees.0.reason', 'questions_required')
            ->assertJsonPath('booking.attendees.0.questions', ['health', 'photos'])
            ->assertJsonPath('booking.attendees.0.message', 'Answer two quick questions about Leo before booking.');
        $this->api('POST', "{$base}/sessions/{$session->id}/bookings", $token, ['attendee_id' => $leo])->assertStatus(422)
            ->assertJsonPath('error.code', 'questions_required')->assertJsonPath('error.attendee_id', $leo)->assertJsonPath('error.questions', ['health', 'photos']);

        // Answering: a yes needs the details; then booking goes through.
        $this->api('PATCH', "{$base}/attendees/{$leo}", $token, ['has_health_notes' => true])->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')->assertJsonPath('error.fields.medical_notes.0', 'Tell us about the injuries or conditions.');
        $this->api('PATCH', "{$base}/attendees/{$leo}", $token, ['photo_consent' => 'maybe'])->assertStatus(422);
        $this->api('PATCH', "{$base}/attendees/{$leo}", $token, ['has_health_notes' => true, 'medical_notes' => 'Asthma', 'photo_consent' => 'none'])->assertOk()
            ->assertJsonPath('data.has_health_notes', true)->assertJsonPath('data.medical_notes', 'Asthma')->assertJsonPath('data.photo_consent', 'none')
            ->assertJsonPath('data.questions_needed', []);
        $this->api('POST', "{$base}/sessions/{$session->id}/bookings", $token, ['attendee_id' => $leo])->assertCreated();

        // Changing one detail leaves the answers alone.
        $this->api('PATCH', "{$base}/attendees/{$leo}", $token, ['emergency_contact' => 'Dev 303-555-0143'])->assertOk()
            ->assertJsonPath('data.photo_consent', 'none')->assertJsonPath('data.has_health_notes', true);

        // Adding with the answers, as the app does.
        $this->api('POST', "{$base}/attendees", $token, ['first_name' => 'Maya', 'last_name' => 'Shah', 'has_health_notes' => false, 'photo_consent' => 'public'])->assertCreated()
            ->assertJsonPath('data.has_health_notes', false)->assertJsonPath('data.questions_needed', []);
    }

    public function test_staff_see_who_is_out_of_photos_and_what_to_watch_for(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $session = $this->classSession($this->program($studio));
        $people = [
            'Nora' => ['photo_consent' => 'none', 'medical_notes' => 'Healing right shoulder'],
            'Ines' => ['photo_consent' => 'studio'],
            'Ruby' => ['photo_consent' => 'public'],
            'Sam' => ['photo_consent' => null, 'photo_consent_at' => null, 'health_answered_at' => null],
        ];
        foreach ($people as $first => $attrs) {
            $user = $this->userIn($studio, 'member', ['first_name' => $first]);
            $person = $this->attendee($studio, $user, ['first_name' => $first, 'last_name' => 'Lee'] + $attrs);
            app(Bookings::class)->book($session, $person, $user->id, 'admin', skipEntitlement: true);
        }
        $this->actingAs($owner);

        foreach (["admin/sessions/{$session->id}", 'admin/checkin?date='.$studio->local($session->starts_at)->toDateString()] as $page) {
            $this->get("/{$studio->slug}/{$page}")->assertOk()
                ->assertSeeInOrder(['Photos and video:', 'No photos: Nora Lee.', 'In-studio only: Ines Lee.', 'Not asked yet: Sam Lee.'])
                ->assertSee('In-studio photos only')->assertSee('Not asked yet: injuries, photos')->assertSee('Healing right shoulder');
        }

        $nora = Attendee::where('first_name', 'Nora')->sole();
        $this->get("/{$studio->slug}/admin/members/{$nora->user_id}")->assertOk()->assertSee('No photos')->assertSee('Healing right shoulder')->assertSee('Answered');
        $sam = Attendee::where('first_name', 'Sam')->sole();
        $this->get("/{$studio->slug}/admin/members/{$sam->user_id}")->assertOk()->assertSee('Not asked yet');

        // Everyone said yes: the roster says so.
        $other = $this->classSession($this->program($studio));
        app(Bookings::class)->book($other, Attendee::where('first_name', 'Ruby')->sole(), Attendee::where('first_name', 'Ruby')->sole()->user_id, 'admin', skipEntitlement: true);
        $this->get("/{$studio->slug}/admin/sessions/{$other->id}")->assertOk()->assertSee('everyone in this class said yes');
    }
}
