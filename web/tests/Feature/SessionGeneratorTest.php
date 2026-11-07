<?php

namespace Tests\Feature;

use App\Services\SessionGenerator;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SessionGeneratorTest extends TestCase
{
    public function test_weekly_sessions_keep_local_time_across_daylight_saving(): void
    {
        $this->travelTo(Carbon::parse('2026-10-01 12:00', 'UTC'));
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $season = $studio->seasons()->create(['name' => 'Fall', 'starts_on' => '2026-10-19', 'ends_on' => '2026-11-15', 'is_current' => true]);
        $program = $this->program($studio, ['weekday' => 2, 'start_time' => '17:00', 'duration_min' => 75, 'capacity' => 6, 'season_id' => $season->id]);

        $result = app(SessionGenerator::class)->sync($program);

        $this->assertSame(['created' => 4, 'removed' => 0], $result); // Tuesdays Oct 20, 27, Nov 3, 10
        $starts = $program->sessions()->orderBy('starts_at')->get()->map(fn ($s) => $s->starts_at->format('Y-m-d H:i'))->all();
        // 17:00 MDT (UTC-6) before Nov 1, 17:00 MST (UTC-7) after.
        $this->assertSame(['2026-10-20 23:00', '2026-10-27 23:00', '2026-11-04 00:00', '2026-11-11 00:00'], $starts);
        $first = $program->sessions()->orderBy('starts_at')->first();
        $this->assertSame(75, (int) $first->starts_at->diffInMinutes($first->ends_at));
        $this->assertSame(6, $first->capacity);
        $this->assertSame($season->id, $first->season_id);
    }

    public function test_events_get_one_session_and_reruns_add_nothing(): void
    {
        $studio = $this->studio();
        $date = now()->addDays(9)->toDateString();
        $program = $this->program($studio, ['type' => 'event', 'weekday' => null, 'event_date' => $date, 'start_time' => '13:00', 'dropin_price_cents' => 6000]);

        $this->assertSame(['created' => 1, 'removed' => 0], app(SessionGenerator::class)->sync($program));
        $this->assertSame(['created' => 0, 'removed' => 0], app(SessionGenerator::class)->sync($program));
        $this->assertSame(6000, $program->sessions()->first()->price_cents);
        $this->assertSame($date.' 13:00', $studio->local($program->sessions()->first()->starts_at)->format('Y-m-d H:i'));
    }

    public function test_changing_the_time_replaces_unbooked_future_sessions_but_keeps_booked_ones(): void
    {
        $studio = $this->studio();
        $studio->seasons()->create(['name' => 'Now', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(4)->toDateString(), 'is_current' => true]);
        $program = $this->program($studio, ['weekday' => now()->addDays(2)->dayOfWeek, 'start_time' => '17:00']);
        app(SessionGenerator::class)->sync($program);
        $count = $program->sessions()->count();
        $booked = $program->sessions()->orderBy('starts_at')->first();
        $user = $this->userIn($studio);
        $booked->bookings()->create(['studio_id' => $studio->id, 'attendee_id' => $this->attendee($studio, $user)->id, 'user_id' => $user->id, 'status' => 'booked', 'booked_at' => now()]);

        $program->update(['start_time' => '18:00']);
        $result = app(SessionGenerator::class)->sync($program->fresh());

        $this->assertSame($count - 1, $result['removed']);
        $this->assertSame($count, $result['created']);
        $this->assertTrue($booked->fresh() !== null, 'the booked session stays');
    }

    public function test_no_season_or_weekday_means_no_sessions(): void
    {
        $studio = $this->studio();
        $this->assertSame(['created' => 0, 'removed' => 0], app(SessionGenerator::class)->sync($this->program($studio)));
    }
}
