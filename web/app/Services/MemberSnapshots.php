<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\MemberSnapshot;
use App\Models\Studio;
use App\Models\StudioUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Each night, every member's activity at a studio, saved as it stood that night, for churn scoring
 * later (roadmap step 9). Statuses elsewhere get overwritten, so a model trained on today's tables
 * would see the future; snapshots record only what was known on each date.
 *
 * Taken once per studio per local day, after LOCAL_HOUR in the studio's time zone, by the hourly
 * housekeeping run. Counts cover classes before the snapshot date's midnight.
 */
class MemberSnapshots
{
    public const LOCAL_HOUR = 2;

    /** Today's snapshot for $studio, if it's past LOCAL_HOUR there and not taken yet. Returns rows written. */
    public function takeIfDue(Studio $studio): int
    {
        $local = $studio->localNow();
        if ($local->hour < self::LOCAL_HOUR) {
            return 0;
        }
        $asOf = $local->toDateString();
        if (MemberSnapshot::where('studio_id', $studio->id)->whereDate('as_of', $asOf)->exists()) {
            return 0;
        }

        return $this->take($studio, $asOf);
    }

    /** Snapshot every member as of $asOf (Y-m-d, the studio's local date). Safe to repeat: existing rows are kept. */
    public function take(Studio $studio, string $asOf): int
    {
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member')->get(['user_id', 'created_at']);
        if ($members->isEmpty()) {
            return 0;
        }
        $cutoff = $studio->parseLocal($asOf.' 00:00:00'); // UTC instant of the local midnight
        $days28 = $cutoff->copy()->subDays(28);
        $days56 = $cutoff->copy()->subDays(56);

        $bookings = DB::table('bookings')
            ->join('class_sessions', 'class_sessions.id', '=', 'bookings.class_session_id')
            ->where('bookings.studio_id', $studio->id)
            ->selectRaw(<<<'SQL'
                bookings.user_id,
                max(case when bookings.status = 'attended' and class_sessions.starts_at < ? then class_sessions.starts_at end) as last_visit,
                sum(case when bookings.status = 'attended' and class_sessions.starts_at >= ? and class_sessions.starts_at < ? then 1 else 0 end) as visits_28d,
                sum(case when bookings.status = 'attended' and class_sessions.starts_at >= ? and class_sessions.starts_at < ? then 1 else 0 end) as visits_prev_28d,
                sum(case when bookings.status in ('no_show', 'late_cancelled') and class_sessions.starts_at >= ? and class_sessions.starts_at < ? then 1 else 0 end) as misses_28d,
                sum(case when bookings.status in ('booked', 'waitlisted') and class_sessions.status = 'scheduled' and class_sessions.starts_at >= ? then 1 else 0 end) as upcoming
                SQL, [$cutoff, $days28, $cutoff, $days56, $days28, $days28, $cutoff, $cutoff])
            ->groupBy('bookings.user_id')
            ->get()->keyBy('user_id');

        $plans = Entitlement::where('studio_id', $studio->id)->whereIn('status', ['active', 'past_due'])
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['user_id', 'kind', 'credits_remaining'])->groupBy('user_id');

        $failedPayments = DomainEvent::where('studio_id', $studio->id)->where('type', 'payment.failed')
            ->whereBetween('occurred_at', [$cutoff->copy()->subDays(90), $cutoff])
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $attendees = Attendee::where('studio_id', $studio->id)->where('status', 'active')
            ->selectRaw('user_id, count(*) as n')->groupBy('user_id')->pluck('n', 'user_id');

        $asOfDate = Carbon::parse($asOf, $studio->timezone)->startOfDay();
        $daysSince = fn (Carbon $from) => max(0, (int) floor($from->diffInDays($asOfDate, false)));

        $rows = $members->map(function (StudioUser $m) use ($studio, $asOf, $bookings, $plans, $failedPayments, $attendees, $daysSince) {
            $b = $bookings->get($m->user_id);
            $held = $plans->get($m->user_id, collect());

            return [
                'studio_id' => $studio->id,
                'user_id' => $m->user_id,
                'as_of' => $asOf,
                'days_since_visit' => $b?->last_visit ? $daysSince($studio->local(Carbon::parse($b->last_visit, 'UTC'))->startOfDay()) : null,
                'visits_28d' => (int) ($b->visits_28d ?? 0),
                'visits_prev_28d' => (int) ($b->visits_prev_28d ?? 0),
                'misses_28d' => (int) ($b->misses_28d ?? 0),
                'upcoming_bookings' => (int) ($b->upcoming ?? 0),
                'has_membership' => $held->contains('kind', 'subscription'),
                'credits_remaining' => (int) $held->where('kind', 'credits')->sum('credits_remaining'),
                'failed_payments_90d' => (int) ($failedPayments[$m->user_id] ?? 0),
                'attendees' => (int) ($attendees[$m->user_id] ?? 0),
                'tenure_days' => $m->created_at ? $daysSince($studio->local($m->created_at)->startOfDay()) : 0,
                'imported' => $m->import_id !== null,
                'created_at' => now(),
            ];
        });

        $written = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $written += MemberSnapshot::query()->insertOrIgnore($chunk->values()->all());
        }

        return $written;
    }
}
