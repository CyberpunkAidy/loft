<?php

namespace Tests\Feature;

use App\Jobs\SendAutomatedMessage;
use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Automation;
use App\Models\DomainEvent;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Automations;
use App\Services\Bookings;
use App\Services\Consent;
use App\Services\DomainEvents;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Automations (roadmap step 4): first class, birthday, win-back. */
class AutomationsTest extends TestCase
{
    private function automation(Studio $studio, string $trigger, array $attributes = []): Automation
    {
        return $studio->automations()->create(['trigger' => $trigger, 'active' => true] + $attributes + Automation::defaults($trigger));
    }

    private function optedIn(Studio $studio, array $attributes = []): User
    {
        $user = $this->userIn($studio, 'member', $attributes);
        app(Consent::class)->setMarketingEmail(StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->sole(), true, 'join');

        return $user;
    }

    /** @return Collection<int, StudioMessage> */
    private function sent(string $kind)
    {
        return Mail::queued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === $kind);
    }

    public function test_a_first_class_sends_the_follow_up_through_the_outbox(): void
    {
        Mail::fake();
        $studio = $this->studio(['name' => 'Skyline']);
        $automation = $this->automation($studio, 'first_class');
        $parent = $this->optedIn($studio, ['first_name' => 'Priya', 'email' => 'priya@example.com']);
        $this->membership($studio, $parent);
        $maya = $this->attendee($studio, $parent, ['first_name' => 'Maya']);
        $program = $this->program($studio, ['title' => 'Kids Silks']);
        $bookings = app(Bookings::class);

        $bookings->mark($bookings->book($this->classSession($program), $maya, $parent->id), 'attended');

        $this->assertSame(1, $this->sent('first_class')->count());
        $mail = $this->sent('first_class')->first();
        $this->assertTrue($mail->hasTo('priya@example.com'));
        $this->assertSame('Thanks for coming to Kids Silks!', $mail->headline);
        $this->assertSame(['Hi Priya,', 'Thanks for joining us for Kids Silks. We hope Maya had a great time.', "Whenever you're ready for the next one, it's all on the schedule."], $mail->lines);
        $this->assertStringStartsWith(url("/{$studio->slug}/unsubscribe/{$parent->id}"), $mail->unsubscribeUrl);
        $delivery = MessageDelivery::where('kind', 'first_class')->sole();
        $this->assertSame(['marketing', $automation->id, DomainEvent::where('type', 'booking.attended')->sole()->id],
            [$delivery->category, $delivery->automation_id, $delivery->domain_event_id]);

        // Only the first class.
        $bookings->mark($bookings->book($this->classSession($program, ['starts_at' => now()->addDays(4)]), $maya, $parent->id), 'attended');
        $this->assertSame(1, $this->sent('first_class')->count());

        // No consent: logged as not sent. Turned off: nothing at all.
        $other = $this->userIn($studio);
        $this->membership($studio, $other);
        $bookings->mark($bookings->book($this->classSession($program, ['starts_at' => now()->addDays(5)]), $this->attendee($studio, $other), $other->id), 'attended');
        $this->assertSame('suppressed', MessageDelivery::where('kind', 'first_class')->where('user_id', $other->id)->sole()->status);
        $automation->update(['active' => false]);
        $third = $this->optedIn($studio);
        $this->membership($studio, $third);
        $bookings->mark($bookings->book($this->classSession($program, ['starts_at' => now()->addDays(6)]), $this->attendee($studio, $third, ['is_self' => true]), $third->id), 'attended');
        $this->assertSame(0, MessageDelivery::where('user_id', $third->id)->where('kind', 'first_class')->count());

        // Turned back on: someone whose first class was while it was off isn't new any more.
        $automation->update(['active' => true]);
        $bookings->mark($bookings->book($this->classSession($program, ['starts_at' => now()->addDays(7)]), Attendee::where('user_id', $third->id)->sole(), $third->id), 'attended');
        $this->assertSame(0, MessageDelivery::where('user_id', $third->id)->where('kind', 'first_class')->count());
        $this->assertSame(1, $this->sent('first_class')->count());
    }

    public function test_the_follow_up_waits_and_is_dropped_if_turned_off_meanwhile(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $automation = $this->automation($studio, 'first_class', ['delay_minutes' => 180]);
        $user = $this->optedIn($studio);
        $me = $this->attendee($studio, $user, ['is_self' => true]);
        Queue::fake();
        $event = app(DomainEvents::class)->record('booking.attended', $studio, ['first_visit' => true, 'program_title' => 'Lyra'], $user->id, $me->id);
        app(Automations::class)->onEvent($event);
        Queue::assertPushed(SendAutomatedMessage::class, fn (SendAutomatedMessage $job) => $job->delay->between(now()->addMinutes(179), now()->addMinutes(181))
            && $job->dedupeKey === "automation:{$automation->id}:first_class:attendee:{$me->id}");

        $job = Queue::pushed(SendAutomatedMessage::class)->first();
        $automation->update(['active' => false]);
        $job->handle(app(Automations::class));
        Mail::assertNothingQueued();
        $automation->update(['active' => true]);
        $job->handle(app(Automations::class));
        $this->assertSame(['Hi '.$user->first_name.',', 'Thanks for joining us for Lyra. We hope you had a great time.', "Whenever you're ready for the next one, it's all on the schedule."],
            $this->sent('first_class')->sole()->lines, '{who} is "you" for your own class');
    }

    public function test_birthdays_go_out_from_ten_on_the_day_once_a_year(): void
    {
        Mail::fake();
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $this->automation($studio, 'birthday');
        $parent = $this->optedIn($studio, ['first_name' => 'Priya']);
        $this->attendee($studio, $parent, ['first_name' => 'Maya', 'dob' => '2017-11-20']);
        $this->attendee($studio, $parent, ['first_name' => 'Leo', 'dob' => '2019-03-02']);
        $this->attendee($studio, $parent, ['first_name' => 'Ana', 'dob' => '2016-11-20', 'status' => 'archived']);
        $leap = $this->optedIn($studio);
        $this->attendee($studio, $leap, ['first_name' => 'Lea', 'dob' => '2016-02-29', 'is_self' => true]);
        $automations = app(Automations::class);

        $this->travelTo($studio->parseLocal('2026-11-20 09:30'));
        $this->assertSame(0, $automations->runScheduled($studio), 'not before 10 AM');
        $this->travelTo($studio->parseLocal('2026-11-20 10:05'));
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame(0, $automations->runScheduled($studio), 'once');

        $mail = $this->sent('birthday')->sole();
        $this->assertTrue($mail->hasTo($parent->email));
        $this->assertSame('Happy birthday, Maya!', $mail->headline);
        $this->assertSame('We can\'t wait to see Maya in class.', $mail->lines[2]);

        // Leap-day birthdays are celebrated on February 28 in other years.
        $this->travelTo($studio->parseLocal('2027-02-28 11:00'));
        $automations->runScheduled($studio);
        $this->assertSame('Happy birthday, Lea!', $this->sent('birthday')->last()->headline);
        $this->assertSame('We can\'t wait to see you in class.', $this->sent('birthday')->last()->lines[2]);
    }

    public function test_win_back_goes_to_lapsed_members_with_nothing_booked_once_per_absence(): void
    {
        Mail::fake();
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $this->travelTo($studio->parseLocal('2026-12-01 12:00'));
        $this->automation($studio, 'win_back', ['conditions' => ['days' => 30]]);
        $program = $this->program($studio);
        $visit = function (User $user, string $local, string $status = 'attended') use ($studio, $program) {
            $attendee = Attendee::where('user_id', $user->id)->first() ?? $this->attendee($studio, $user, ['is_self' => true]);
            $session = $this->classSession($program, ['starts_at' => $studio->parseLocal($local)]);
            $session->bookings()->create(['studio_id' => $studio->id, 'attendee_id' => $attendee->id, 'user_id' => $user->id, 'status' => $status, 'booked_at' => now()]);
        };
        $lapsed = $this->optedIn($studio, ['first_name' => 'Alex']);
        $visit($lapsed, '2026-10-01 18:00');
        $visit($lapsed, '2026-11-01 18:00'); // 30 days before Dec 1
        $booked = $this->optedIn($studio);
        $visit($booked, '2026-11-01 18:00');
        $visit($booked, '2026-12-05 18:00', 'booked'); // coming back already
        $longGone = $this->optedIn($studio);
        $visit($longGone, '2026-09-01 18:00'); // outside the window
        $recent = $this->optedIn($studio);
        $visit($recent, '2026-11-20 18:00');
        $automations = app(Automations::class);

        $this->assertSame(1, $automations->runScheduled($studio));
        $mail = $this->sent('win_back')->sole();
        $this->assertTrue($mail->hasTo($lapsed->email));
        $this->assertSame("It's been 30 days since we last saw you at {$studio->name}, and your spot is waiting.", $mail->lines[1]);
        $this->assertSame(0, $automations->runScheduled($studio), 'once per absence');

        // They come back, then lapse again: a new absence, a new email.
        $visit($lapsed, '2026-12-02 18:00');
        $this->travelTo($studio->parseLocal('2027-01-03 10:30'));
        $automations->runScheduled($studio);
        $this->assertSame(2, $this->sent('win_back')->filter(fn ($m) => $m->hasTo($lapsed->email))->count());
        $this->assertSame("It's been 32 days since we last saw you at {$studio->name}, and your spot is waiting.", $this->sent('win_back')->last()->lines[1]);
    }

    public function test_admins_set_up_preview_and_test_automations(): void
    {
        Mail::fake();
        $studio = $this->studio(['name' => 'Skyline']);
        $owner = $this->userIn($studio, 'owner', ['first_name' => 'Olivia', 'email' => 'olivia@example.com']);
        $this->optedIn($studio);
        $this->userIn($studio);
        $this->actingAs($owner);
        $base = "/{$studio->slug}/admin/automations";

        $this->get($base)->assertOk()->assertSee('After a first class')->assertSee('Birthday')->assertSee('We miss you')
            ->assertSee('1 of 2')->assertSee('Set up');
        $this->get("{$base}/nope")->assertNotFound();
        $this->get("{$base}/win_back")->assertOk()->assertSee('Days since their last class')->assertSee('{days}')
            ->assertSee('It&#039;s been 30 days since we last saw you at Skyline', false);

        $this->put("{$base}/win_back", ['subject' => 'Hi {name}', 'body' => 'Come back to {class}', 'days' => 30])
            ->assertSessionHasErrors(['subject', 'body']);
        $this->put("{$base}/win_back", ['subject' => 'We miss you, {first_name}', 'body' => "Hi!\n\nIt's been {days} days.", 'days' => 45, 'active' => '1'])
            ->assertRedirect("{$base}/win_back")->assertSessionHas('success', 'Saved. It’s on.');
        $automation = Automation::where('trigger', 'win_back')->sole();
        $this->assertSame([45, true, 'We miss you, {first_name}'], [$automation->days(), $automation->active, $automation->subject]);
        $this->put("{$base}/first_class", ['subject' => 'Thanks!', 'body' => 'See you soon.', 'delay_hours' => 2])->assertSessionHas('success', 'Saved. It’s off until you turn it on.');
        $this->assertSame(120, Automation::where('trigger', 'first_class')->sole()->delay_minutes);
        $this->get($base)->assertOk()->assertSee('45 days after their last class')->assertSee('2 hours after the first class');

        // A test goes to the admin, consent or not, marked as a test.
        $this->post("{$base}/win_back/test")->assertSessionHas('success', 'A test is on its way to olivia@example.com, with sample names filled in.');
        $test = $this->sent('automation_test')->sole();
        $this->assertTrue($test->hasTo('olivia@example.com'));
        $this->assertSame(['Test: We miss you, Olivia', "It's been 45 days."], [$test->headline, $test->lines[1]]);
        $this->assertNull($test->unsubscribeUrl);
    }
}
