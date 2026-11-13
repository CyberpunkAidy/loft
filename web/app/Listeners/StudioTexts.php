<?php

namespace App\Listeners;

use App\Events\SessionBookingCancelled;
use App\Events\WaitlistPromoted;
use App\Models\Booking;
use App\Models\Studio;
use App\Services\Texts;
use Illuminate\Events\Dispatcher;

/**
 * The booking messages that also go by text (decision 48), to members who agreed to booking texts
 * at the studio, as well as the email: moved in from the waitlist, and a class the studio cancelled.
 * Reminders come from the hourly housekeeping run. Each can be turned off on Admin → Text messages.
 */
class StudioTexts
{
    public function __construct(private readonly Texts $texts) {}

    public function subscribe(Dispatcher $events): array
    {
        return [
            WaitlistPromoted::class => 'waitlistPromoted',
            SessionBookingCancelled::class => 'sessionCancelled',
        ];
    }

    public function waitlistPromoted(WaitlistPromoted $event): void
    {
        $b = $event->booking;
        if ($b->studio->setting('text_waitlist')) {
            $this->texts->send($b->user, $b->studio, 'promoted', self::promoted($b), "promoted:booking:{$b->id}:sms");
        }
    }

    public function sessionCancelled(SessionBookingCancelled $event): void
    {
        $b = $event->booking;
        if ($b->studio->setting('text_cancellations')) {
            $this->texts->send($b->user, $b->studio, 'session_cancelled', self::cancelled($b), "session_cancelled:booking:{$b->id}:sms");
        }
    }

    /** "Reminder: Leo has Kids Aerial Silks tomorrow at 4:30 PM. Your classes: https://…" */
    public static function reminder(Booking $b): string
    {
        $who = $b->attendee->is_self ? 'you have' : $b->attendee->first_name.' has';

        return 'Reminder: '.$who.' '.$b->classSession->program->title.' '.self::when($b).'.'
            .($b->attendsOnline() ? ' It’s online: the link to join is in your account.' : '')
            .' Your classes: '.route('studio.account', $b->studio);
    }

    /** "A spot opened, so Leo is in Kids Aerial Silks on Fri, Nov 14 at 4:30 PM. …" */
    public static function promoted(Booking $b): string
    {
        $who = $b->attendee->is_self ? 'you’re' : $b->attendee->first_name.' is';

        return 'A spot opened, so '.$who.' in '.$b->classSession->program->title.' '.self::when($b).'. Can’t make it? Please cancel so someone else can have the spot: '.route('studio.account', $b->studio);
    }

    /** "Sorry, Kids Aerial Silks on Tue, Nov 17 at 4:30 PM is cancelled (Leo was booked). …" */
    public static function cancelled(Booking $b): string
    {
        return 'Sorry, '.$b->classSession->program->title.' '.self::when($b).' is cancelled'
            .($b->attendee->is_self ? '' : ' ('.$b->attendee->first_name.' was booked)')
            .'. Any credit used is back in your account. Find another class: '.route('studio.schedule', $b->studio);
    }

    /** "today at 4:30 PM", "tomorrow at 4:30 PM" or "on Fri, Nov 14 at 4:30 PM", studio time. */
    private static function when(Booking $b): string
    {
        $studio = $b->studio;
        $start = $studio->local($b->classSession->starts_at);
        $today = $studio->localNow()->startOfDay();
        $day = match (true) {
            $start->isSameDay($today) => 'today',
            $start->isSameDay($today->copy()->addDay()) => 'tomorrow',
            default => 'on '.$start->format('D, M j'),
        };

        return $day.' at '.$start->format('g:i A');
    }

    /** For the Text messages page: what each booking text looks like, with sample names. */
    public static function samples(Studio $studio): array
    {
        $url = fn (string $route) => route($route, $studio);
        $day = $studio->localNow()->addDays(3)->format('D, M j');

        return [
            'text_reminders' => "{$studio->name}: Reminder: Maya has Kids Aerial Silks tomorrow at 4:30 PM. Your classes: ".$url('studio.account'),
            'text_waitlist' => "{$studio->name}: A spot opened, so Maya is in Kids Aerial Silks on {$day} at 4:30 PM. Can’t make it? Please cancel so someone else can have the spot: ".$url('studio.account'),
            'text_cancellations' => "{$studio->name}: Sorry, Kids Aerial Silks on {$day} at 4:30 PM is cancelled (Maya was booked). Any credit used is back in your account. Find another class: ".$url('studio.schedule'),
        ];
    }
}
