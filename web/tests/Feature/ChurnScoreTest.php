<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Automation;
use App\Models\Booking;
use App\Models\MemberRiskScore;
use App\Models\MemberSnapshot;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\User;
use App\Services\AccountDeletion;
use App\Services\Automations;
use App\Services\ChurnScores;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Churn scoring v1 (roadmap step 9): rules over the nightly snapshots, the churn-risk email, and what staff see. */
class ChurnScoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** A snapshot of someone who's been a member a while, with any features changed. */
    private function snapshot(array $features = []): MemberSnapshot
    {
        return new MemberSnapshot($features + [
            'days_since_visit' => 3, 'visits_28d' => 4, 'visits_prev_28d' => 4, 'misses_28d' => 0, 'upcoming_bookings' => 1,
            'has_membership' => true, 'credits_remaining' => 0, 'failed_payments_90d' => 0, 'attendees' => 1, 'tenure_days' => 200,
        ]);
    }

    private function scored(array $features): array
    {
        $result = app(ChurnScores::class)->score($this->snapshot($features));

        return [$result['score'], $result['level'], array_column($result['reasons'], 'points', 'code')];
    }

    private function risk(Studio $studio, User $user, string $asOf, string $level, int $score, array $reasons = []): MemberRiskScore
    {
        return MemberRiskScore::create(['studio_id' => $studio->id, 'user_id' => $user->id, 'as_of' => $asOf, 'model_version' => ChurnScores::MODEL,
            'score' => $score, 'level' => $level, 'reasons' => $reasons, 'created_at' => now()]);
    }

    public function test_the_rules_score_what_a_studio_would_notice(): void
    {
        // A regular: booked ahead, comes every week.
        $this->assertSame([0, 'low', ['booked_ahead' => -20, 'regular' => -20]], $this->scored(['visits_28d' => 6, 'visits_prev_28d' => 5, 'upcoming_bookings' => 2]));

        // Slipping: two weeks away, half as many classes, nothing booked.
        $this->assertSame([55, 'medium', ['no_visit_14' => 25, 'visits_halved' => 15, 'nothing_booked' => 15]], $this->scored([
            'days_since_visit' => 16, 'visits_28d' => 1, 'visits_prev_28d' => 4, 'upcoming_bookings' => 0, 'has_membership' => false, 'credits_remaining' => 3,
        ]));

        // Stopped coming, and a renewal failed.
        [$score, $level, $reasons] = $this->scored(['days_since_visit' => 25, 'visits_28d' => 0, 'visits_prev_28d' => 6, 'upcoming_bookings' => 0, 'failed_payments_90d' => 1]);
        $this->assertSame([95, 'high', ['no_visit_21', 'stopped_coming', 'payment_failed', 'nothing_booked']], [$score, $level, array_keys($reasons)]);

        // Joined, never came: worrying after two weeks, not before.
        $this->assertSame([70, 'high', ['never_visited' => 40, 'no_plan' => 15, 'nothing_booked' => 15]], $this->scored([
            'days_since_visit' => null, 'visits_28d' => 0, 'visits_prev_28d' => 0, 'upcoming_bookings' => 0, 'has_membership' => false, 'tenure_days' => 20,
        ]));
        $this->assertSame([15, 'low', ['no_plan' => 15]], $this->scored([
            'days_since_visit' => null, 'visits_28d' => 0, 'visits_prev_28d' => 0, 'upcoming_bookings' => 0, 'has_membership' => false, 'tenure_days' => 3,
        ]));

        // Smaller signs: a last credit, missed classes. Never more than 100.
        $this->assertSame(['missed_classes' => 10, 'last_credit' => 5], array_intersect_key($this->scored(['has_membership' => false, 'credits_remaining' => 1, 'misses_28d' => 2])[2],
            ['last_credit' => 1, 'missed_classes' => 1]));
        $this->assertSame(100, $this->scored(['days_since_visit' => 40, 'visits_28d' => 0, 'visits_prev_28d' => 8, 'misses_28d' => 3, 'upcoming_bookings' => 0,
            'has_membership' => false, 'failed_payments_90d' => 2])[0]);

        // Already gone (three months, nothing booked, no plan): lapsed, win-back's job.
        $this->assertSame('lapsed', $this->scored(['days_since_visit' => 120, 'visits_28d' => 0, 'visits_prev_28d' => 0, 'upcoming_bookings' => 0, 'has_membership' => false])[1]);
        $this->assertSame('high', $this->scored(['days_since_visit' => 120, 'visits_28d' => 0, 'visits_prev_28d' => 0, 'upcoming_bookings' => 0, 'has_membership' => true])[1], 'still paying: not gone');

        // Reasons are kept in plain words.
        $result = app(ChurnScores::class)->score($this->snapshot(['days_since_visit' => 16, 'upcoming_bookings' => 0]));
        $this->assertSame('No class in 16 days', $result['reasons'][0]['label']);
    }

    public function test_members_are_scored_each_night_after_the_snapshot(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $regular = $this->userIn($studio);
        $gone = $this->userIn($studio);
        $program = $this->program($studio);
        $this->travelTo($studio->parseLocal('2026-11-10 01:00'));
        foreach ([[$regular, [2, 5, 9, 12]], [$gone, [25, 30, 33]]] as [$member, $daysAgo]) {
            $attendee = $this->attendee($studio, $member);
            foreach ($daysAgo as $days) {
                Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($program, ['starts_at' => now()->subDays($days)])->id,
                    'attendee_id' => $attendee->id, 'user_id' => $member->id, 'status' => 'attended', 'booked_at' => now()->subDays($days + 1)]);
            }
        }
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($program, ['starts_at' => now()->addDays(2)])->id,
            'attendee_id' => Attendee::where('user_id', $regular->id)->value('id'), 'user_id' => $regular->id, 'status' => 'booked', 'booked_at' => now()]);

        $this->artisan('loft:housekeeping')->expectsOutputToContain('Risk scores: 0')->assertSuccessful(); // before 2 AM there
        $this->travelTo($studio->parseLocal('2026-11-10 03:00'));
        $this->artisan('loft:housekeeping')->expectsOutputToContain('Risk scores: 2')->assertSuccessful();
        $this->artisan('loft:housekeeping')->expectsOutputToContain('Risk scores: 0')->assertSuccessful();

        $scores = MemberRiskScore::where('studio_id', $studio->id)->get()->keyBy('user_id');
        $this->assertSame(['low', '2026-11-10', 'rules-v1'], [$scores[$regular->id]->level, $scores[$regular->id]->as_of->toDateString(), $scores[$regular->id]->model_version]);
        $this->assertSame('high', $scores[$gone->id]->level);
        $this->assertSame(MemberSnapshot::where('user_id', $gone->id)->value('id'), $scores[$gone->id]->member_snapshot_id);
        $this->assertContains('No class in 25 days', $scores[$gone->id]->concerns());

        app(AccountDeletion::class)->delete($gone->fresh());
        $this->assertSame(0, MemberRiskScore::where('user_id', $gone->id)->count(), 'deleted with the account');
    }

    public function test_the_churn_risk_email_reaches_members_who_just_turned_high(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver', 'name' => 'Skyline']);
        $automation = Automation::for($studio, 'churn_risk');
        $automation->fill(['active' => true])->save();
        $winBack = Automation::for($studio, 'win_back');
        $winBack->save();
        $consenting = fn (array $attributes = []) => tap($this->userIn($studio, 'member', $attributes), fn ($u) => $studio->studioUsers()->where('user_id', $u->id)->update(['email_marketing_consent_at' => now()]));
        $crossed = $consenting(['first_name' => 'Ana', 'email' => 'ana@example.com']);
        $stillHigh = $consenting();
        $noConsent = $this->userIn($studio);
        $afterWinBack = $consenting();
        $emailedLately = $consenting();
        $this->travelTo($studio->parseLocal('2026-11-10 09:00'));
        foreach ([$crossed, $stillHigh, $noConsent, $afterWinBack, $emailedLately] as $member) {
            $this->risk($studio, $member, '2026-11-09', $member->is($stillHigh) ? 'high' : 'medium', 50);
            $this->risk($studio, $member, '2026-11-10', 'high', 70);
        }
        $log = fn (User $member, Automation $from, int $daysAgo) => MessageDelivery::create(['studio_id' => $studio->id, 'user_id' => $member->id, 'automation_id' => $from->id,
            'channel' => 'email', 'category' => 'marketing', 'kind' => $from->trigger, 'dedupe_key' => 'old:'.$member->id.':'.$from->id, 'status' => 'sent'])
            ->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        $log($afterWinBack, $winBack, 5);
        $log($emailedLately, $automation, 20);

        $this->assertSame(0, app(Automations::class)->runScheduled($studio), 'from 10 AM');
        $this->travelTo($studio->parseLocal('2026-11-10 10:30'));
        $this->assertSame(2, app(Automations::class)->runScheduled($studio), 'Ana, and the one without consent (logged, not sent)');
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'churn_risk' && $m->hasTo('ana@example.com') && $m->headline === 'How are classes going, Ana?');
        $this->assertSame(1, Mail::queued(StudioMessage::class)->count());
        $this->assertSame('suppressed', MessageDelivery::where('user_id', $noConsent->id)->sole()->status);
        $this->assertSame(0, app(Automations::class)->runScheduled($studio), 'once');

        // Old scores don't send: scoring hasn't run for a few days.
        $this->travelTo($studio->parseLocal('2026-11-13 10:30'));
        $later = $consenting();
        $this->risk($studio, $later, '2026-11-10', 'high', 80);
        $this->assertSame(0, app(Automations::class)->runScheduled($studio));
        $this->assertSame('The day a member’s risk turns high (from 10 AM), at most once a month each', $automation->fresh()->timing());
    }

    public function test_staff_see_who_is_at_risk_and_why(): void
    {
        $studio = $this->studio();
        $owner = $this->userIn($studio, 'owner');
        $this->travel(-1)->days(); // Ana joined first, so newest-first would put Ben above her
        $ana = $this->userIn($studio, 'member', ['first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $this->travelBack();
        $ben = $this->userIn($studio, 'member', ['first_name' => 'Ben', 'last_name' => 'Ito']);
        $cal = $this->userIn($studio, 'member', ['first_name' => 'Cal', 'last_name' => 'Ng']);
        $today = now()->toDateString();
        $this->actingAs($owner);
        $this->get("/{$studio->slug}/admin/members?risk=at_risk")->assertOk()->assertSee('Risk is scored each night from 2 AM.');

        $this->risk($studio, $ana, now()->subDay()->toDateString(), 'medium', 45);
        $this->risk($studio, $ana, $today, 'high', 75, [['code' => 'no_visit_21', 'points' => 35, 'label' => 'No class in 23 days'], ['code' => 'payment_failed', 'points' => 20, 'label' => 'A payment failed recently']]);
        $this->risk($studio, $ben, $today, 'medium', 40, [['code' => 'no_visit_14', 'points' => 25, 'label' => 'No class in 15 days'], ['code' => 'booked_ahead', 'points' => -20, 'label' => 'Has a class booked']]);
        $this->risk($studio, $cal, $today, 'low', 0);

        $this->get("/{$studio->slug}/admin/members")->assertOk()->assertSee('Everyone')->assertSee('High risk')->assertSee('Some risk');
        $list = $this->get("/{$studio->slug}/admin/members?risk=at_risk")->assertOk()->assertSee('riskiest first');
        $list->assertSeeInOrder(['Ana Ruiz', 'Ben Ito'])->assertDontSee('Cal Ng');
        $this->get("/{$studio->slug}/admin/members?risk=at_risk&q=ben")->assertOk()->assertSee('Ben Ito')->assertDontSee('Ana Ruiz');
        $this->get("/{$studio->slug}/admin")->assertOk()->assertSee('At high risk of leaving')->assertSee('<strong>1</strong><span>At high risk of leaving</span>', false);

        $this->get("/{$studio->slug}/admin/members/{$ana->id}")->assertOk()->assertSee('Risk of leaving')->assertSee('75 of 100')
            ->assertSee('No class in 23 days')->assertSee('A payment failed recently')->assertSee('Before:')->assertSee('some risk');
        $this->get("/{$studio->slug}/admin/members/{$ben->id}")->assertOk()->assertSee('✓ Has a class booked');
        $this->get("/{$studio->slug}/admin/members/{$cal->id}")->assertOk()->assertSee('Low risk');
        $this->get("/{$studio->slug}/admin/automations")->assertOk()->assertSee('At risk of leaving');
        $this->get("/{$studio->slug}/admin/automations/churn_risk")->assertOk()->assertSee('the day a member’s churn risk turns', false);
    }

    public function test_the_training_export_is_pseudonymous_and_labelled(): void
    {
        $studio = $this->studio();
        $stayed = $this->userIn($studio, 'member', ['email' => 'stayed@example.com']);
        $left = $this->userIn($studio, 'member', ['email' => 'left@example.com']);
        $snap = fn (User $u, string $asOf, ?int $days) => MemberSnapshot::create($this->snapshot(['days_since_visit' => $days])->getAttributes()
            + ['studio_id' => $studio->id, 'user_id' => $u->id, 'as_of' => $asOf, 'created_at' => now()]);
        $first = now()->subDays(40)->toDateString();
        $second = now()->subDays(10)->toDateString();
        $snap($stayed, $first, 2);
        $snap($left, $first, 4);
        $snap($stayed, $second, 1);  // came back within 30 days
        $snap($left, $second, 34);   // no class since the first snapshot

        $file = storage_path('framework/testing/churn.csv');
        @mkdir(dirname($file), 0777, true);
        $this->artisan('loft:churn-export', ['studio' => $studio->slug, '--output' => $file])->expectsOutputToContain('Wrote 4 rows')->assertSuccessful();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(file_get_contents($file)))));
        unlink($file);

        $this->assertSame(['member', 'as_of', 'days_since_visit'], array_slice($rows[0], 0, 3));
        $this->assertSame('churned_30d', end($rows[0]));
        $byMember = collect(array_slice($rows, 1))->groupBy(0);
        $this->assertCount(2, $byMember, 'one pseudonym per member, the same on every row');
        foreach ($byMember->keys() as $pseudonym) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $pseudonym);
            $this->assertNotContains($pseudonym, [(string) $stayed->id, (string) $left->id]);
        }
        $labels = collect(array_slice($rows, 1))->mapWithKeys(fn ($r) => [$r[2].'@'.$r[1] => end($r)])->all();
        $this->assertSame(['2@'.$first => '0', '4@'.$first => '1', '1@'.$second => '', '34@'.$second => ''], $labels, 'labels only where 30 days later is known');
        $this->assertStringNotContainsString('@example.com', implode("\n", array_map(fn ($r) => implode(',', $r), $rows)));

        $this->artisan('loft:churn-export', ['studio' => 'nowhere'])->expectsOutputToContain('No studio at that web address.')->assertFailed();
    }
}
