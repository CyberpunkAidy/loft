<?php

namespace App\Services;

use App\Models\MemberRiskScore;
use App\Models\MemberSnapshot;
use App\Models\Studio;

/**
 * Churn scoring, v1: rules over the nightly member snapshots (roadmap step 9, "rules first, AI
 * later"). Each rule adds or takes away points for one thing a studio would notice, and the reasons
 * are kept with the score, in plain words. Scores are written to member_risk_scores with the model
 * version; automations read only that table, so a trained model can replace these rules later.
 *
 * Levels: high (60+), medium (35+), low; and lapsed for people already gone (no class in
 * LAPSED_DAYS, nothing booked, no plan), who are win-back's business rather than this one's.
 */
class ChurnScores
{
    public const MODEL = 'rules-v1';

    public const HIGH = 60;

    public const MEDIUM = 35;

    public const LAPSED_DAYS = 90;

    /**
     * @return array{score: int, level: string, reasons: list<array{code: string, points: int, label: string}>}
     */
    public function score(MemberSnapshot $s): array
    {
        $reasons = [];
        $add = function (string $code, int $points, string $label) use (&$reasons) {
            $reasons[] = ['code' => $code, 'points' => $points, 'label' => $label];
        };
        $days = $s->days_since_visit;

        // Coming to class
        if ($days === null) {
            if ($s->tenure_days >= 14 && $s->upcoming_bookings === 0) {
                $add('never_visited', 40, "Joined {$s->tenure_days} days ago and hasn’t been to a class");
            }
        } elseif ($days >= 45) {
            $add('no_visit_45', 50, "No class in {$days} days");
        } elseif ($days >= 21) {
            $add('no_visit_21', 35, "No class in {$days} days");
        } elseif ($days >= 14) {
            $add('no_visit_14', 25, "No class in {$days} days");
        } elseif ($days >= 7) {
            $add('no_visit_7', 10, "No class in {$days} days");
        }
        if ($s->visits_prev_28d >= 2) {
            if ($s->visits_28d === 0) {
                $add('stopped_coming', 25, "Came {$s->visits_prev_28d} times the month before, not at all this month");
            } elseif ($s->visits_28d * 2 <= $s->visits_prev_28d) {
                $add('visits_halved', 15, "Classes down from {$s->visits_prev_28d} to {$s->visits_28d} a month");
            }
        }
        if ($s->misses_28d >= 2) {
            $add('missed_classes', 10, "Missed or cancelled late {$s->misses_28d} times this month");
        }

        // Plans and payments
        if ($s->failed_payments_90d > 0) {
            $add('payment_failed', 20, $s->failed_payments_90d === 1 ? 'A payment failed recently' : "{$s->failed_payments_90d} payments failed recently");
        }
        if (! $s->has_membership && $s->credits_remaining === 0) {
            $add('no_plan', 15, 'No membership or credits left');
        } elseif (! $s->has_membership && $s->credits_remaining === 1) {
            $add('last_credit', 5, 'One class credit left');
        }

        // What's keeping them
        if ($s->upcoming_bookings > 0) {
            $add('booked_ahead', -20, $s->upcoming_bookings === 1 ? 'Has a class booked' : "Has {$s->upcoming_bookings} classes booked");
        } elseif ($days !== null || $s->tenure_days >= 14) {
            $add('nothing_booked', 15, 'Nothing booked');
        }
        if ($s->has_membership && $s->visits_28d >= 4) {
            $add('regular', -20, "A regular: {$s->visits_28d} classes this month");
        }

        usort($reasons, fn ($a, $b) => $b['points'] <=> $a['points']);
        $score = max(0, min(100, array_sum(array_column($reasons, 'points'))));
        $gone = ($days ?? $s->tenure_days) > self::LAPSED_DAYS && $s->upcoming_bookings === 0 && ! $s->has_membership && $s->credits_remaining === 0;

        return [
            'score' => $score,
            'level' => match (true) {
                $gone => 'lapsed',
                $score >= self::HIGH => 'high',
                $score >= self::MEDIUM => 'medium',
                default => 'low',
            },
            'reasons' => $reasons,
        ];
    }

    /** Score the studio's latest snapshot, for anyone not scored yet. Returns scores written. */
    public function scoreLatest(Studio $studio): int
    {
        $asOf = MemberSnapshot::where('studio_id', $studio->id)->max('as_of');

        return $asOf ? $this->scoreDay($studio, substr((string) $asOf, 0, 10)) : 0;
    }

    /** Score every snapshot taken as of $asOf (Y-m-d) that has no score from this model yet. */
    public function scoreDay(Studio $studio, string $asOf): int
    {
        $scored = MemberRiskScore::where('studio_id', $studio->id)->whereDate('as_of', $asOf)->where('model_version', self::MODEL)->pluck('user_id');
        $rows = MemberSnapshot::where('studio_id', $studio->id)->whereDate('as_of', $asOf)->whereNotIn('user_id', $scored)->get()
            ->map(function (MemberSnapshot $snapshot) use ($studio, $asOf) {
                $result = $this->score($snapshot);

                return [
                    'studio_id' => $studio->id,
                    'user_id' => $snapshot->user_id,
                    'member_snapshot_id' => $snapshot->id,
                    'as_of' => $asOf,
                    'model_version' => self::MODEL,
                    'score' => $result['score'],
                    'level' => $result['level'],
                    'reasons' => json_encode($result['reasons']),
                    'created_at' => now(),
                ];
            });

        $written = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $written += MemberRiskScore::query()->insertOrIgnore($chunk->values()->all());
        }

        return $written;
    }

    /** The latest day scored at $studio (Y-m-d), if any. */
    public function latestDay(Studio $studio): ?string
    {
        $day = MemberRiskScore::where('studio_id', $studio->id)->where('model_version', self::MODEL)->max('as_of');

        return $day ? substr((string) $day, 0, 10) : null;
    }
}
