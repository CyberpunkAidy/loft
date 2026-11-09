<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\MemberSnapshot;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Services\Bookings;
use App\Services\DomainEvents;
use App\Services\MemberSnapshots;
use App\Services\Messages;
use App\Services\OfflinePayments;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\RecordingEventHandler;
use Tests\TestCase;

/** The event backbone (roadmap step 3): the outbox, message deliveries, consent and snapshots. */
class EventBackboneTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RecordingEventHandler::$seen = [];
        RecordingEventHandler::$failOn = [];
    }

    private function bookings(): Bookings
    {
        return app(Bookings::class);
    }

    private function member(Studio $studio, int $credits = 10): Attendee
    {
        $user = $this->userIn($studio);
        $this->credits($studio, $user, $credits);

        return $this->attendee($studio, $user);
    }

    /** The payload has these keys and values. Key order isn't compared: Postgres's jsonb reorders keys. */
    private function assertPayloadHas(array $expected, DomainEvent $event): void
    {
        $actual = array_intersect_key($event->payload, $expected);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /** @return list<string> */
    private function types(Studio $studio): array
    {
        return DomainEvent::where('studio_id', $studio->id)->orderBy('id')->pluck('type')->all();
    }

    public function test_booking_changes_are_recorded_as_events(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio, ['title' => 'Silks']), ['capacity' => 1]);
        $first = $this->member($studio);
        $second = $this->member($studio);

        $booked = $this->bookings()->book($session, $first, $first->user_id);
        $waiting = $this->bookings()->book($session->fresh(), $second, $second->user_id);
        $this->bookings()->cancel($booked, $first->user_id); // moves $second in
        $this->assertSame(['booking.booked', 'booking.waitlisted', 'booking.cancelled', 'booking.promoted'], $this->types($studio));

        $event = DomainEvent::where('type', 'booking.booked')->sole();
        $this->assertSame([$first->user_id, $first->id], [$event->user_id, $event->attendee_id]);
        $this->assertPayloadHas(['booking_id' => $booked->id, 'session_id' => $session->id, 'program_title' => 'Silks', 'source' => 'web', 'credit_consumed' => true], $event);
        $this->assertPayloadHas(['by' => 'member', 'late' => false, 'from' => 'booked', 'credit_refunded' => true], DomainEvent::where('type', 'booking.cancelled')->sole());
        $this->assertSame(1, DomainEvent::where('type', 'booking.waitlisted')->sole()->payload['position']);

        // Check-in: the first class at the studio is marked, then not again.
        $this->assertTrue($this->bookings()->mark($waiting->fresh(), 'attended'));
        $this->assertTrue(DomainEvent::where('type', 'booking.attended')->sole()->payload['first_visit']);
        $this->bookings()->mark($waiting->fresh(), 'attended'); // no change, no event
        $this->bookings()->mark($waiting->fresh(), 'booked');
        $this->bookings()->mark($waiting->fresh(), 'no_show');
        // That check-in was undone, so the next class is still the first; the one after isn't.
        foreach ([4, 5] as $days) {
            $next = $this->classSession($session->program, ['starts_at' => now()->addDays($days)->setTime(17, 0)]);
            $this->bookings()->mark($this->bookings()->book($next, $second, $second->user_id), 'attended');
        }
        $this->assertSame([true, true, false], DomainEvent::where('type', 'booking.attended')->orderBy('id')->get()->map(fn ($e) => $e->payload['first_visit'])->all());
        $this->assertSame(1, DomainEvent::where('type', 'booking.check_in_undone')->count());
        $this->assertSame(1, DomainEvent::where('type', 'booking.no_show')->count());

        // The studio cancels a class: one event for the class, one per booking.
        $third = $this->classSession($session->program, ['starts_at' => now()->addDays(6)->setTime(17, 0)]);
        $this->bookings()->book($third, $first, $first->user_id);
        $this->bookings()->cancelSession($third);
        $this->assertSame(1, DomainEvent::where('type', 'session.cancelled')->sole()->payload['bookings']);
        $this->assertSame('studio', DomainEvent::where('type', 'booking.cancelled')->latest('id')->first()->payload['by']);
    }

    public function test_an_event_is_written_in_the_same_transaction_as_its_change(): void
    {
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio));
        $attendee = $this->member($studio);
        $this->mock(DomainEvents::class)->shouldReceive('forBooking')->andThrow(new RuntimeException('database went away'));

        try {
            app(Bookings::class)->book($session, $attendee, $attendee->user_id);
            $this->fail('Expected the booking to fail');
        } catch (RuntimeException) {
        }
        $this->assertSame(0, $session->bookings()->count(), 'no event, no booking');
        $this->assertSame(10, Entitlement::where('user_id', $attendee->user_id)->sole()->credits_remaining, 'and the credit is still there');
    }

    public function test_plans_payments_waivers_and_expiry_are_recorded(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $member = $this->userIn($studio);
        $plan = $this->plan($studio, ['name' => '5-Class Pack', 'kind' => 'pack', 'credits' => 5, 'price_cents' => 9000]);

        $payment = app(OfflinePayments::class)->record($plan, $member, $owner, ['method' => 'cash', 'paid_on' => now()->toDateString()]);
        $granted = DomainEvent::where('type', 'entitlement.granted')->sole();
        $this->assertSame(['cash', 9000, '5-Class Pack'], [$granted->payload['source'], $granted->payload['amount_cents'], $granted->payload['plan_name']]);
        app(OfflinePayments::class)->void($payment, $owner);
        $this->assertSame('voided', DomainEvent::where('type', 'entitlement.ended')->sole()->payload['reason']);

        $expiring = $this->credits($studio, $member, 3, ['expires_at' => now()->subHour(), 'plan_id' => $plan->id]);
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame('expired', $expiring->fresh()->status);
        $this->assertSame(['voided', 'expired'], DomainEvent::where('type', 'entitlement.ended')->orderBy('id')->get()->map(fn ($e) => $e->payload['reason'])->all());

        $waiver = $this->waiver($studio, ['title' => 'Aerial Release']);
        $kid = $this->attendee($studio, $member);
        $this->actingAs($member)->post("/{$studio->slug}/waiver/{$kid->id}", ['waiver_id' => $waiver->id, 'signed_name' => $member->name, 'agree' => '1']);
        $signed = DomainEvent::where('type', 'waiver.signed')->sole();
        $this->assertSame(['Aerial Release', 1, $kid->id], [$signed->payload['waiver_title'], $signed->payload['version'], $signed->attendee_id]);
    }

    public function test_the_relay_hands_events_to_handlers_and_retries_failures(): void
    {
        Mail::fake();
        $this->expectExceptionObject(new InvalidArgumentException('Unknown domain event type [booking.teleported].'));
        try {
            app(DomainEvents::class)->record('booking.teleported', null);
        } finally {
            // With no handlers, events are kept as history and simply marked relayed.
            config(['loft.event_handlers' => []]);
            $studio = $this->studio();
            $attendee = $this->member($studio);
            $this->bookings()->book($this->classSession($this->program($studio)), $attendee, $attendee->user_id);
            $this->assertSame(1, app(DomainEvents::class)->relay());
            $this->assertNotNull(DomainEvent::sole()->relayed_at);

            // With handlers: relayed right after the transaction commits (the queue is sync in tests).
            config(['loft.event_handlers' => [RecordingEventHandler::class]]);
            RecordingEventHandler::$failOn = ['booking.cancelled'];
            $booking = $this->bookings()->book($this->classSession($this->program($studio), ['starts_at' => now()->addDays(6)]), $attendee, $attendee->user_id);
            $this->assertSame(['booking.booked'], RecordingEventHandler::$seen);
            $this->bookings()->cancel($booking, $attendee->user_id);
            $failed = DomainEvent::where('type', 'booking.cancelled')->sole();
            $this->assertSame([null, 1, 'Handler failed on booking.cancelled'], [$failed->relayed_at, $failed->relay_attempts, $failed->relay_error]);

            // Retried by the next relay until it works, or until MAX_ATTEMPTS.
            app(DomainEvents::class)->relay();
            $this->assertSame(2, $failed->fresh()->relay_attempts);
            RecordingEventHandler::$failOn = [];
            $this->assertSame(1, app(DomainEvents::class)->relay());
            $this->assertNotNull($failed->fresh()->relayed_at);
            $this->assertSame(['booking.booked', 'booking.cancelled'], RecordingEventHandler::$seen);

            $stuck = app(DomainEvents::class)->record('member.joined', $studio);
            $stuck->update(['relayed_at' => null, 'relay_attempts' => DomainEvents::MAX_ATTEMPTS]);
            $this->assertSame(0, app(DomainEvents::class)->relay(), 'given up on after MAX_ATTEMPTS');
        }
    }

    public function test_studio_emails_are_logged_sent_once_and_marked(): void
    {
        $studio = $this->studio();
        $attendee = $this->member($studio);
        $booking = $this->bookings()->book($this->classSession($this->program($studio)), $attendee, $attendee->user_id);

        // The mailer is synchronous in tests, so the confirmation is already sent.
        $delivery = MessageDelivery::sole();
        $this->assertSame(['booked', 'transactional', "booked:booking:{$booking->id}", 'sent', $attendee->user_id],
            [$delivery->kind, $delivery->category, $delivery->dedupe_key, $delivery->status, $delivery->user_id]);
        $this->assertNotNull($delivery->sent_at);
        $this->assertNotNull($delivery->provider_message_id);

        Mail::fake();
        $message = new StudioMessage($studio, 'Again?', [], [], [], null, null, 'booked');
        $this->assertNull(app(Messages::class)->email($attendee->user, $message, "booked:booking:{$booking->id}"));
        Mail::assertNothingQueued();

        // A queued email that finally fails is marked failed.
        $retry = app(Messages::class)->email($attendee->user, new StudioMessage($studio, 'Hi', [], [], [], null, null, 'welcome'));
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->deliveryId === $retry->id);
        $failing = new StudioMessage($studio, 'Hi', kind: 'welcome');
        $failing->deliveryId = $retry->id;
        $failing->failed(new RuntimeException('Resend said no'));
        $this->assertSame(['failed', 'Resend said no'], [$retry->fresh()->status, $retry->fresh()->error]);
    }

    public function test_marketing_consent_and_unsubscribing(): void
    {
        Mail::fake();
        $studio = $this->studio(['name' => 'Skyline']);
        $this->post("/{$studio->slug}/join", ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'password' => 'secret-pass', 'marketing_email' => '1'])
            ->assertRedirect();
        $pat = StudioUser::where('studio_id', $studio->id)->sole();
        $this->assertTrue($pat->allowsMarketingEmail());
        $this->assertSame('join', $pat->email_marketing_consent_source);
        $this->assertSame(['member.joined', 'consent.email_marketing_granted'], $this->types($studio));

        $other = $this->studio();
        $this->post("/{$other->slug}/join")->assertRedirect(); // signed in: one click, no box ticked
        $this->assertFalse(StudioUser::where('studio_id', $other->id)->sole()->allowsMarketingEmail());

        // Transactional email always goes; marketing only with consent, with the unsubscribe link.
        $user = $pat->user;
        $news = fn () => new StudioMessage($studio, 'Summer camp is open', ['Sign up now.'], kind: 'newsletter');
        $sent = app(Messages::class)->email($user, $news(), null, 'marketing');
        $this->assertSame('queued', $sent->status);
        Mail::assertQueued(StudioMessage::class, function (StudioMessage $m) use ($studio, $user) {
            $headers = $m->headers()->text;

            return $m->kind === 'newsletter'
                && str_starts_with($headers['List-Unsubscribe'], '<'.url("/{$studio->slug}/unsubscribe/{$user->id}?signature="))
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click'
                && str_contains($m->render(), 'Unsubscribe');
        });

        // The link works without signing in; mail apps POST to it (one click).
        auth()->logout();
        $link = app(Messages::class)->unsubscribeUrl($studio, $user);
        $this->get($link)->assertOk()->assertSee('Stop getting news and offers from Skyline');
        $this->get("/{$studio->slug}/unsubscribe/{$user->id}")->assertForbidden();
        $this->post($link, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('Unsubscribed.');
        $this->assertFalse($pat->fresh()->allowsMarketingEmail());
        $this->assertSame('unsubscribe_link', DomainEvent::where('type', 'consent.email_marketing_withdrawn')->sole()->payload['source']);
        $this->get($link)->assertOk()->assertSee('doesn’t get news and offers');

        $suppressed = app(Messages::class)->email($user, $news(), null, 'marketing');
        $this->assertSame('suppressed', $suppressed->status);
        $this->assertSame(1, Mail::queued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'newsletter')->count());

        // Back on from the profile; staff see the state, the activity and the emails.
        $this->actingAs($user)->put("/{$studio->slug}/account/profile", ['first_name' => 'Pat', 'last_name' => 'Lee', 'email' => 'pat@example.com', 'marketing_email' => '1'])
            ->assertSessionHas('success');
        $this->assertSame('profile', $pat->fresh()->email_marketing_consent_source);
        $this->get("/{$studio->slug}/account/profile")->assertOk()->assertSee('News and offers from Skyline');

        $owner = $this->userIn($studio, 'owner');
        $this->actingAs($owner)->get("/{$studio->slug}/admin/members/{$user->id}")->assertOk()
            ->assertSee('opted in')->assertSee('Opted out of marketing emails')->assertSee('Joined')->assertSee('Not sent (no consent)');
    }

    public function test_nightly_member_snapshots(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $this->travelTo($studio->parseLocal('2026-11-19 12:00:00'));
        $program = $this->program($studio);
        $member = $this->userIn($studio);
        $kid = $this->attendee($studio, $member);
        $this->userIn($studio, 'instructor'); // staff aren't snapshotted
        $class = function (string $local, string $status) use ($studio, $program, $kid) {
            $session = $this->classSession($program, ['starts_at' => $studio->parseLocal($local)]);
            $session->bookings()->create(['studio_id' => $studio->id, 'attendee_id' => $kid->id, 'user_id' => $kid->user_id, 'status' => $status, 'booked_at' => now()]);
        };
        $class('2026-11-17 17:00', 'attended'); // 3 days before the 20th
        $class('2026-11-10 17:00', 'attended');
        $class('2026-10-10 17:00', 'attended'); // 29–56 days before
        $class('2026-11-12 17:00', 'no_show');
        $class('2026-11-14 17:00', 'late_cancelled');
        $class('2026-11-22 17:00', 'booked'); // upcoming
        $this->membership($studio, $member);
        $this->credits($studio, $member, 4);
        app(DomainEvents::class)->record('payment.failed', $studio, [], $member->id);
        $this->travelTo($studio->parseLocal('2026-11-20 01:30:00'));
        app(DomainEvents::class)->record('payment.failed', $studio, [], $member->id); // after midnight: tomorrow's

        $snapshots = app(MemberSnapshots::class);
        $this->assertSame(0, $snapshots->takeIfDue($studio), 'not before 2 AM studio time');
        $this->travelTo($studio->parseLocal('2026-11-20 02:10:00'));
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame(0, $snapshots->takeIfDue($studio), 'once a day');

        $snap = MemberSnapshot::sole();
        $this->assertSame('2026-11-20', $snap->as_of->toDateString());
        $this->assertSame(
            [$member->id, 3, 2, 1, 2, 1, true, 4, 1, 1],
            [$snap->user_id, $snap->days_since_visit, $snap->visits_28d, $snap->visits_prev_28d, $snap->misses_28d, $snap->upcoming_bookings,
                $snap->has_membership, $snap->credits_remaining, $snap->failed_payments_90d, $snap->attendees],
        );

        // Deleting the account deletes its snapshots, and unlinks (keeps) its events.
        $this->actingAs($member)->delete("/{$studio->slug}/account", ['delete_password' => 'password'])->assertRedirect();
        $this->assertSame(0, MemberSnapshot::count());
        $this->assertSame([null, null], DomainEvent::where('type', 'payment.failed')->pluck('user_id')->all());
    }

    public function test_the_old_email_log_is_gone_and_the_new_one_stays(): void
    {
        $this->assertFalse(Schema::hasTable('sent_notifications'), 'replaced by message_deliveries');
        $this->assertTrue(Schema::hasTable('message_deliveries'));
    }
}
