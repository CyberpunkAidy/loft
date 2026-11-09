<?php

namespace App\Services;

use App\Jobs\SendAutomatedMessage;
use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Automation;
use App\Models\DomainEvent;
use App\Models\MemberRiskScore;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sends each studio's automations (roadmap step 4):
 * - first_class: from the outbox. A first-visit booking.attended event queues the email for
 *   delay_minutes after check-in.
 * - birthday and win_back: checked by the hourly housekeeping run, from SEND_HOUR studio time.
 * - churn_risk: from SEND_HOUR, members whose latest risk score (App\Services\ChurnScores) turned
 *   high, reading only member_risk_scores; at most once per CHURN_COOLDOWN_DAYS, and not right after
 *   a win-back email.
 *
 * Every send goes through Messages as marketing, under a dedupe key, so it goes out once and only
 * to members who opted in. Each delivery is logged with its automation.
 */
class Automations
{
    /** Birthday and win-back emails go out from this hour, studio time. */
    public const SEND_HOUR = 10;

    /** Win-back reaches members who lapsed in the last this-many days past the threshold, not everyone ever. */
    public const WIN_BACK_WINDOW_DAYS = 7;

    /** A member gets the churn-risk email at most once in this many days… */
    public const CHURN_COOLDOWN_DAYS = 30;

    /** …and not within this many days of a win-back email. */
    public const CHURN_AFTER_WIN_BACK_DAYS = 14;

    public function __construct(private readonly Messages $messages) {}

    /** From the outbox (App\Services\AutomationTriggers): a first class queues the first_class email. */
    public function onEvent(DomainEvent $event): void
    {
        if ($event->type !== 'booking.attended' || empty($event->payload['first_visit']) || ! $event->user_id) {
            return;
        }
        $automation = Automation::where('studio_id', $event->studio_id)->where('trigger', 'first_class')->where('active', true)->first();
        if (! $automation) {
            return;
        }
        SendAutomatedMessage::dispatch(
            $automation->id,
            $event->user_id,
            $event->attendee_id,
            ['class' => $event->payload['program_title'] ?? 'class'],
            "automation:{$automation->id}:first_class:attendee:{$event->attendee_id}",
            $event->id,
        )->delay(now()->addMinutes($automation->delay_minutes));
    }

    /** Hourly: birthdays and win-backs due today at $studio. Returns emails queued or suppressed. */
    public function runScheduled(Studio $studio): int
    {
        $today = $studio->localNow();
        if ($today->hour < self::SEND_HOUR) {
            return 0;
        }
        $sent = 0;
        foreach (Automation::where('studio_id', $studio->id)->where('active', true)->whereIn('trigger', ['birthday', 'win_back', 'churn_risk'])->get() as $automation) {
            $automation->setRelation('studio', $studio);
            $sent += match ($automation->trigger) {
                'birthday' => $this->birthdays($automation, $today),
                'win_back' => $this->winBacks($automation, $today),
                'churn_risk' => $this->churnRisks($automation, $today),
            };
        }

        return $sent;
    }

    /** Send $automation to $user (and about $attendee). Null if it was already sent. */
    public function send(Automation $automation, User $user, ?Attendee $attendee, array $values, string $dedupeKey, ?int $domainEventId = null): ?MessageDelivery
    {
        return $this->messages->email($user, $this->message($automation, $user, $attendee, $values), $dedupeKey, 'marketing', $domainEventId, $automation->id);
    }

    /** Send the current text to a staff member, with sample values, marked as a test. */
    public function sendTest(Automation $automation, User $staff): ?MessageDelivery
    {
        $message = $this->message($automation, $staff, null, $this->sampleValues($automation));
        $message->headline = 'Test: '.$message->headline;
        $message->kind = 'automation_test';

        return $this->messages->email($staff, $message);
    }

    /** The email itself: placeholders filled in, paragraphs split on blank lines, a button to the schedule. */
    public function message(Automation $automation, User $user, ?Attendee $attendee, array $values = []): StudioMessage
    {
        $studio = $automation->studio;
        $values += [
            'first_name' => $user->first_name,
            'attendee_first_name' => $attendee?->first_name ?? $user->first_name,
            'who' => ! $attendee || $attendee->is_self ? 'you' : $attendee->first_name,
            'studio' => $studio->name,
            'days' => $automation->days(),
        ];
        $paragraphs = array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', Automation::fillPlaceholders($automation->body, $values)))));

        return new StudioMessage(
            $studio,
            Automation::fillPlaceholders($automation->subject, $values),
            $paragraphs,
            [],
            [],
            route('studio.schedule', $studio),
            Automation::TRIGGERS[$automation->trigger]['action'],
            $automation->trigger,
        );
    }

    /** @return array<string, mixed> stand-ins for previews and test sends */
    public function sampleValues(Automation $automation): array
    {
        return [
            'attendee_first_name' => 'Maya',
            'who' => 'Maya',
            'class' => $automation->studio->programs()->where('is_published', true)->orderBy('title')->value('title') ?? 'Kids Aerial Silks',
            'days' => $automation->days(),
        ];
    }

    private function birthdays(Automation $automation, Carbon $today): int
    {
        $studio = $automation->studio;
        $dates = [$today->format('m-d')];
        if ($today->format('m-d') === '02-28' && ! $today->isLeapYear()) {
            $dates[] = '02-29'; // leap-day birthdays are celebrated on the 28th in other years
        }
        $monthDay = DB::getDriverName() === 'pgsql' ? "to_char(dob, 'MM-DD')" : "strftime('%m-%d', dob)";

        $sent = 0;
        Attendee::with('user')->where('studio_id', $studio->id)->where('status', 'active')->whereNotNull('dob')
            ->whereIn(DB::raw($monthDay), $dates)
            ->whereIn('user_id', StudioUser::where('studio_id', $studio->id)->select('user_id'))
            ->each(function (Attendee $attendee) use ($automation, $today, &$sent) {
                if ($attendee->user && $this->send($automation, $attendee->user, $attendee, [], "automation:{$automation->id}:birthday:attendee:{$attendee->id}:{$today->year}")) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Members whose last class was $days to $days + WIN_BACK_WINDOW_DAYS - 1 days ago (studio dates)
     * and who have nothing booked. Once per lapse: a new visit makes a new dedupe key.
     */
    private function winBacks(Automation $automation, Carbon $today): int
    {
        $studio = $automation->studio;
        $days = $automation->days();
        $lapsedOn = $today->copy()->startOfDay()->subDays($days);
        $before = $studio->parseLocal($lapsedOn->copy()->addDay()->toDateString().' 00:00:00');
        $since = $studio->parseLocal($lapsedOn->copy()->subDays(self::WIN_BACK_WINDOW_DAYS - 1)->toDateString().' 00:00:00');

        $lapsed = DB::table('bookings')
            ->join('class_sessions', 'class_sessions.id', '=', 'bookings.class_session_id')
            ->where('bookings.studio_id', $studio->id)->where('bookings.status', 'attended')
            ->groupBy('bookings.user_id')
            ->selectRaw('bookings.user_id, max(class_sessions.starts_at) as last_visit')
            ->havingRaw('max(class_sessions.starts_at) >= ? and max(class_sessions.starts_at) < ?', [$since, $before])
            ->pluck('last_visit', 'user_id');
        if ($lapsed->isEmpty()) {
            return 0;
        }
        $booked = DB::table('bookings')->join('class_sessions', 'class_sessions.id', '=', 'bookings.class_session_id')
            ->where('bookings.studio_id', $studio->id)->whereIn('bookings.user_id', $lapsed->keys())
            ->whereIn('bookings.status', ['booked', 'waitlisted'])->where('class_sessions.status', 'scheduled')
            ->where('class_sessions.starts_at', '>', now())->distinct()->pluck('bookings.user_id')->all();
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member')
            ->whereIn('user_id', $lapsed->keys()->diff($booked))->with('user')->get();

        $sent = 0;
        foreach ($members as $member) {
            $lastVisit = $studio->local(Carbon::parse($lapsed[$member->user_id], 'UTC'))->startOfDay();
            $key = "automation:{$automation->id}:win_back:user:{$member->user_id}:{$lastVisit->toDateString()}";
            if ($member->user && $this->send($automation, $member->user, null, ['days' => (int) $lastVisit->diffInDays($today->copy()->startOfDay())], $key)) {
                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Members whose latest score is high and whose score before it wasn't: they just crossed. Only
     * fresh scores (today's or yesterday's) count, so a studio that hasn't been scored for a while
     * doesn't get a burst.
     */
    private function churnRisks(Automation $automation, Carbon $today): int
    {
        $studio = $automation->studio;
        $day = MemberRiskScore::where('studio_id', $studio->id)->where('model_version', ChurnScores::MODEL)->max('as_of');
        $day = $day ? substr((string) $day, 0, 10) : null;
        if (! $day || $day < $today->copy()->subDay()->toDateString()) {
            return 0;
        }
        $high = MemberRiskScore::where('studio_id', $studio->id)->where('model_version', ChurnScores::MODEL)->whereDate('as_of', $day)
            ->where('level', 'high')->pluck('user_id');
        if ($high->isEmpty()) {
            return 0;
        }
        $before = MemberRiskScore::where('studio_id', $studio->id)->where('model_version', ChurnScores::MODEL)->whereIn('user_id', $high)
            ->whereDate('as_of', '<', $day)->whereDate('as_of', '>=', Carbon::parse($day)->subDays(7)->toDateString())
            ->orderByDesc('as_of')->get(['user_id', 'level'])->unique('user_id')->pluck('level', 'user_id');
        $crossed = $high->filter(fn ($userId) => ($before[$userId] ?? null) !== 'high');

        $winBack = Automation::where('studio_id', $studio->id)->where('trigger', 'win_back')->value('id');
        $recent = MessageDelivery::where('studio_id', $studio->id)->whereIn('user_id', $crossed)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('automation_id', $automation->id)->where('created_at', '>=', now()->subDays(self::CHURN_COOLDOWN_DAYS)))
                ->orWhere(fn ($q) => $q->where('automation_id', $winBack)->where('created_at', '>=', now()->subDays(self::CHURN_AFTER_WIN_BACK_DAYS))))
            ->pluck('user_id')->unique();

        $sent = 0;
        $members = StudioUser::where('studio_id', $studio->id)->where('role', 'member')->whereIn('user_id', $crossed->diff($recent))->with('user')->get();
        foreach ($members as $member) {
            $key = "automation:{$automation->id}:churn_risk:user:{$member->user_id}:{$day}";
            if ($member->user && $this->send($automation, $member->user, null, [], $key)) {
                $sent++;
            }
        }

        return $sent;
    }
}
