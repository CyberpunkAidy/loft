<?php

namespace Tests\Feature;

use App\Events\BookingCreated;
use App\Events\SessionBookingCancelled;
use App\Events\WaitlistPromoted;
use App\Services\Bookings;
use App\Support\BookingException;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/** The plugin's booking rules (includes/services/class-booking.php), ported. */
class BookingRulesTest extends TestCase
{
    private function bookings(): Bookings
    {
        return app(Bookings::class);
    }

    private function expectReason(string $reason, callable $fn): BookingException
    {
        try {
            $fn();
        } catch (BookingException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }
        $this->fail("Expected BookingException [{$reason}]");
    }

    public function test_booking_uses_a_credit_and_fires_an_event(): void
    {
        Event::fake([BookingCreated::class]);
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 5);
        $session = $this->classSession($this->program($studio));

        $booking = $this->bookings()->book($session, $this->attendee($studio, $user), $user->id);

        $this->assertSame('booked', $booking->status);
        $this->assertTrue($booking->credit_consumed);
        $this->assertSame($credits->id, $booking->entitlement_id);
        $this->assertSame(4, $credits->fresh()->credits_remaining);
        Event::assertDispatched(BookingCreated::class, fn ($e) => ! $e->waitlisted && $e->booking->is($booking));
    }

    public function test_full_class_waitlists_without_using_a_credit(): void
    {
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        $first = $this->userIn($studio);
        $this->credits($studio, $first);
        $this->bookings()->book($session, $this->attendee($studio, $first), $first->id);

        $second = $this->userIn($studio);
        $credits = $this->credits($studio, $second);
        $a = $this->bookings()->book($session, $this->attendee($studio, $second), $second->id);
        $third = $this->userIn($studio);
        $this->credits($studio, $third);
        $b = $this->bookings()->book($session, $this->attendee($studio, $third), $third->id);

        $this->assertSame(['waitlisted', 1], [$a->status, $a->position]);
        $this->assertSame(['waitlisted', 2], [$b->status, $b->position]);
        $this->assertFalse($a->credit_consumed);
        $this->assertSame(5, $credits->fresh()->credits_remaining);
    }

    public function test_cannot_book_the_same_attendee_twice(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $session = $this->classSession($this->program($studio));
        $attendee = $this->attendee($studio, $user);
        $this->bookings()->book($session, $attendee, $user->id);

        $this->expectReason('duplicate', fn () => $this->bookings()->book($session, $attendee, $user->id));
    }

    public function test_cannot_book_someone_elses_attendee(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $other = $this->userIn($studio);
        $this->credits($studio, $user);

        $this->expectReason('forbidden', fn () => $this->bookings()->book($this->classSession($this->program($studio)), $this->attendee($studio, $other), $user->id));
    }

    public function test_members_need_a_signed_waiver_and_staff_need_a_reason(): void
    {
        $studio = $this->studio();
        $this->waiver($studio);
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $session = $this->classSession($this->program($studio));
        $attendee = $this->attendee($studio, $user);

        $e = $this->expectReason('waiver', fn () => $this->bookings()->book($session, $attendee, $user->id));
        $this->assertTrue($e->context['attendee']->is($attendee));

        // Staff need a reason to book someone who hasn't signed, and the reason is kept.
        $this->expectReason('waiver', fn () => $this->bookings()->book($session, $attendee, $user->id, 'admin'));
        $staff = $this->userIn($studio, 'admin');
        $booking = $this->bookings()->book($session, $attendee, $user->id, 'admin', null, false, ['staff' => $staff, 'reason' => 'Signed on paper']);
        $this->assertSame('booked', $booking->status);
        $this->assertDatabaseHas('waiver_overrides', ['booking_id' => $booking->id, 'staff_user_id' => $staff->id, 'reason' => 'Signed on paper']);
    }

    public function test_needs_plan_lists_only_plans_that_cover_the_program(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $program = $this->program($studio, [], ['competitive']);
        $all = $this->plan($studio, ['name' => 'Everything', 'program_scope' => null]);
        $competitive = $this->plan($studio, ['name' => 'Competitive', 'program_scope' => ['competitive']]);
        $this->plan($studio, ['name' => 'Recreational', 'program_scope' => ['recreational']]);
        $this->plan($studio, ['name' => 'Hidden', 'program_scope' => null, 'active' => false]);

        $e = $this->expectReason('needs_plan', fn () => $this->bookings()->book($this->classSession($program), $this->attendee($studio, $user), $user->id));

        $this->assertEqualsCanonicalizing([$all->id, $competitive->id], $e->context['plans']->pluck('id')->all());
    }

    public function test_entitlement_scope_is_respected(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->membership($studio, $user, null, ['program_scope' => ['recreational']]);

        $this->expectReason('needs_plan', fn () => $this->bookings()->book(
            $this->classSession($this->program($studio, [], ['competitive'])), $this->attendee($studio, $user), $user->id,
        ));
    }

    public function test_members_only_programs_refuse_credits_and_only_offer_memberships(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $this->plan($studio, ['kind' => 'pack']);
        $membershipPlan = $this->plan($studio, ['kind' => 'subscription', 'name' => 'Member']);
        $program = $this->program($studio, ['members_only' => true]);

        $e = $this->expectReason('needs_plan', fn () => $this->bookings()->book($this->classSession($program), $this->attendee($studio, $user), $user->id));
        $this->assertSame([$membershipPlan->id], $e->context['plans']->pluck('id')->all());
    }

    public function test_age_limits_use_age_on_the_class_date(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $program = $this->program($studio, ['min_age' => 8, 'max_age' => 11]);
        $session = $this->classSession($program, ['starts_at' => now()->addDays(10)->setTime(17, 0)]);

        $this->expectReason('age', fn () => $this->bookings()->book($session, $this->attendee($studio, $user, ['dob' => now()->subYears(6)]), $user->id));
        $this->expectReason('age', fn () => $this->bookings()->book($session, $this->attendee($studio, $user, ['dob' => now()->subYears(13)]), $user->id));
        // Turns 8 between today and the class.
        $turnsEight = $this->attendee($studio, $user, ['dob' => now()->subYears(8)->addDays(5)]);
        $this->assertSame('booked', $this->bookings()->book($session, $turnsEight, $user->id)->status);
    }

    public function test_booking_window_and_closed_sessions(): void
    {
        $studio = $this->studio(['settings' => ['booking_opens_days_before' => 7, 'booking_closes_minutes_before' => 60]]);
        $user = $this->userIn($studio);
        $this->credits($studio, $user);
        $program = $this->program($studio);
        $attendee = $this->attendee($studio, $user);

        $this->expectReason('closed', fn () => $this->bookings()->book($this->classSession($program, ['starts_at' => now()->addDays(8)]), $attendee, $user->id));
        $this->expectReason('closed', fn () => $this->bookings()->book($this->classSession($program, ['starts_at' => now()->addMinutes(30)]), $attendee, $user->id));
        $this->expectReason('closed', fn () => $this->bookings()->book($this->classSession($program, ['starts_at' => now()->subMinute()]), $attendee, $user->id));
        $this->expectReason('cancelled', fn () => $this->bookings()->book($this->classSession($program, ['status' => 'cancelled']), $attendee, $user->id));
        // Staff can book beyond the window.
        $this->assertSame('booked', $this->bookings()->book($this->classSession($program, ['starts_at' => now()->addDays(8)]), $attendee, $user->id, 'admin')->status);
    }

    public function test_membership_is_preferred_and_weekly_limit_is_enforced(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $membership = $this->membership($studio, $user, 1);
        $credits = $this->credits($studio, $user, 3);
        $program = $this->program($studio);
        // Two sessions in the same Monday–Sunday week (studio time), one the next week.
        $monday = $studio->localNow()->next('Monday')->setTime(17, 0);
        $first = $this->classSession($program, ['starts_at' => $monday->copy()->utc()]);
        $second = $this->classSession($program, ['starts_at' => $monday->copy()->addDays(2)->utc()]);
        $nextWeek = $this->classSession($program, ['starts_at' => $monday->copy()->addWeek()->utc()]);
        $attendee = $this->attendee($studio, $user);

        $this->assertSame($membership->id, $this->bookings()->book($first, $attendee, $user->id)->entitlement_id);
        $this->assertSame($credits->id, $this->bookings()->book($second, $attendee, $user->id)->entitlement_id, 'over the weekly limit, credits are used');
        $this->assertSame($membership->id, $this->bookings()->book($nextWeek, $attendee, $user->id)->entitlement_id);
    }

    public function test_credits_expiring_soonest_are_used_first_and_never_expiring_last(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $never = $this->credits($studio, $user, 5, ['expires_at' => null]);
        $later = $this->credits($studio, $user, 5, ['expires_at' => now()->addDays(60)]);
        $soon = $this->credits($studio, $user, 5, ['expires_at' => now()->addDays(10)]);
        $this->credits($studio, $user, 5, ['expires_at' => now()->subDay()]); // expired
        $this->credits($studio, $user, 5, ['status' => 'past_due']);

        $booking = $this->bookings()->book($this->classSession($this->program($studio)), $this->attendee($studio, $user), $user->id);

        $this->assertSame($soon->id, $booking->entitlement_id);
        $this->assertNotNull($never->id);
        $this->assertNotNull($later->id);
    }

    public function test_past_due_membership_cannot_book(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $this->membership($studio, $user, null, ['status' => 'past_due']);

        $this->expectReason('needs_plan', fn () => $this->bookings()->book($this->classSession($this->program($studio)), $this->attendee($studio, $user), $user->id));
    }

    public function test_on_time_cancel_refunds_the_credit(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 5);
        $booking = $this->bookings()->book($this->classSession($this->program($studio), ['starts_at' => now()->addDays(2)]), $this->attendee($studio, $user), $user->id);

        $result = $this->bookings()->cancel($booking, $user->id);

        $this->assertSame(['status' => 'cancelled', 'credit_refunded' => true, 'makeup' => false], $result);
        $this->assertSame(5, $credits->fresh()->credits_remaining);
        $this->assertFalse($booking->fresh()->credit_consumed);
    }

    public function test_late_cancel_keeps_the_credit_unless_the_studio_returns_it(): void
    {
        $studio = $this->studio(); // late_cancel_hours = 12
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 5);
        $program = $this->program($studio);
        $booking = $this->bookings()->book($this->classSession($program, ['starts_at' => now()->addHours(3)]), $this->attendee($studio, $user), $user->id);

        $this->assertSame(['status' => 'late_cancelled', 'credit_refunded' => false, 'makeup' => false], $this->bookings()->cancel($booking, $user->id));
        $this->assertSame(4, $credits->fresh()->credits_remaining);

        $studio->update(['settings' => ['late_cancel_keeps_credit' => true]]);
        $again = $this->bookings()->book($this->classSession($program, ['starts_at' => now()->addHours(3)]), $this->attendee($studio, $user), $user->id);
        $this->assertSame(['status' => 'late_cancelled', 'credit_refunded' => true, 'makeup' => false], $this->bookings()->cancel($again->fresh(), $user->id));
    }

    public function test_staff_cancels_are_never_late_and_members_cannot_cancel_others(): void
    {
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 5);
        $booking = $this->bookings()->book($this->classSession($this->program($studio), ['starts_at' => now()->addHour()]), $this->attendee($studio, $user), $user->id);

        $this->expectReason('forbidden', fn () => $this->bookings()->cancel($booking, $this->userIn($studio)->id));
        $this->assertSame(['status' => 'cancelled', 'credit_refunded' => true, 'makeup' => false], $this->bookings()->cancel($booking, null, byAdmin: true));
        $this->assertSame(5, $credits->fresh()->credits_remaining);
    }

    public function test_cancelling_promotes_the_waitlist_in_order_and_takes_their_credit(): void
    {
        Event::fake([WaitlistPromoted::class]);
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        $users = collect(range(1, 3))->map(fn () => $this->userIn($studio));
        $credits = $users->map(fn ($u) => $this->credits($studio, $u, 2));
        $bookings = $users->map(fn ($u) => $this->bookings()->book($session, $this->attendee($studio, $u), $u->id));

        $this->bookings()->cancel($bookings[0], $users[0]->id);

        $this->assertSame('booked', $bookings[1]->fresh()->status);
        $this->assertSame('waitlist_promote', $bookings[1]->fresh()->source);
        $this->assertTrue($bookings[1]->fresh()->credit_consumed);
        $this->assertSame(1, $credits[1]->fresh()->credits_remaining);
        $this->assertSame('waitlisted', $bookings[2]->fresh()->status);
        Event::assertDispatched(WaitlistPromoted::class, fn ($e) => $e->booking->is($bookings[1]));
    }

    public function test_promotion_drops_someone_whose_credits_ran_out_and_tries_the_next(): void
    {
        $studio = $this->studio();
        $program = $this->program($studio);
        $session = $this->classSession($program, ['capacity' => 1]);
        $holder = $this->userIn($studio);
        $this->credits($studio, $holder, 1);
        $seat = $this->bookings()->book($session, $this->attendee($studio, $holder), $holder->id);

        $broke = $this->userIn($studio);
        $brokeCredits = $this->credits($studio, $broke, 1);
        $waiting = $this->bookings()->book($session, $this->attendee($studio, $broke), $broke->id);
        // Their only credit gets used on another class while they wait.
        $this->bookings()->book($this->classSession($program), $this->attendee($studio, $broke), $broke->id);
        $this->assertSame(0, $brokeCredits->fresh()->credits_remaining);

        $next = $this->userIn($studio);
        $this->membership($studio, $next);
        $nextBooking = $this->bookings()->book($session, $this->attendee($studio, $next), $next->id);

        $this->bookings()->cancel($seat, $holder->id);

        $this->assertSame('cancelled', $waiting->fresh()->status);
        $this->assertSame('booked', $nextBooking->fresh()->status);
    }

    public function test_promotion_rechecks_a_lapsed_membership(): void
    {
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        $holder = $this->userIn($studio);
        $this->membership($studio, $holder);
        $seat = $this->bookings()->book($session, $this->attendee($studio, $holder), $holder->id);

        $waiter = $this->userIn($studio);
        $membership = $this->membership($studio, $waiter);
        $waiting = $this->bookings()->book($session, $this->attendee($studio, $waiter), $waiter->id);
        $membership->update(['status' => 'cancelled']);
        $credits = $this->credits($studio, $waiter, 2);

        $this->bookings()->cancel($seat, $holder->id);

        $this->assertSame('booked', $waiting->fresh()->status);
        $this->assertSame($credits->id, $waiting->fresh()->entitlement_id, 'falls back to their credits');
        $this->assertSame(1, $credits->fresh()->credits_remaining);
    }

    public function test_lenient_no_show_policy_returns_and_retakes_the_credit(): void
    {
        $studio = $this->studio(['settings' => ['no_show_consumes_credit' => false]]);
        $user = $this->userIn($studio);
        $credits = $this->credits($studio, $user, 5);
        $booking = $this->bookings()->book($this->classSession($this->program($studio)), $this->attendee($studio, $user), $user->id);

        $this->assertTrue($this->bookings()->mark($booking, 'no_show'));
        $this->assertSame(5, $credits->fresh()->credits_remaining);
        $this->assertTrue($this->bookings()->mark($booking->fresh(), 'attended'));
        $this->assertSame(4, $credits->fresh()->credits_remaining);
        $this->assertNotNull($booking->fresh()->attended_at);
        $this->assertFalse($this->bookings()->mark($booking->fresh(), 'waitlisted'));
    }

    public function test_cancelling_a_session_returns_credits_and_notifies_everyone(): void
    {
        Event::fake([SessionBookingCancelled::class]);
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1]);
        $a = $this->userIn($studio);
        $aCredits = $this->credits($studio, $a, 3);
        $this->bookings()->book($session, $this->attendee($studio, $a), $a->id);
        $b = $this->userIn($studio);
        $this->credits($studio, $b, 3);
        $this->bookings()->book($session, $this->attendee($studio, $b), $b->id); // waitlisted

        $this->assertSame(2, $this->bookings()->cancelSession($session));

        $this->assertSame('cancelled', $session->fresh()->status);
        $this->assertSame(3, $aCredits->fresh()->credits_remaining);
        $this->assertSame(0, $session->bookings()->whereIn('status', ['booked', 'waitlisted'])->count());
        Event::assertDispatchedTimes(SessionBookingCancelled::class, 2);
    }

    public function test_an_entitlement_from_another_studio_never_pays(): void
    {
        $studio = $this->studio();
        $other = $this->studio();
        $user = $this->userIn($studio);
        $other->studioUsers()->create(['user_id' => $user->id, 'role' => 'member']);
        $foreign = $this->credits($other, $user, 5);

        $this->expectReason('needs_plan', fn () => $this->bookings()->book(
            $this->classSession($this->program($studio)), $this->attendee($studio, $user), $user->id, 'web', $foreign,
        ));
    }
}
