<?php

namespace App\Services;

use App\Jobs\SendText;
use App\Models\MessageDelivery;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every text goes through here (decision 48). Texts are sent from the studio's own Twilio number,
 * only to members who agreed to that kind at that studio: 'transactional' texts about their bookings,
 * or 'marketing' (news and offers, automations). Each is logged in message_deliveries like an email,
 * under a dedupe key so it goes out once, without the number or the words. A queued job sends it,
 * and Twilio reports delivery back to TwilioWebhookController.
 */
class Texts
{
    /** Marketing texts go out between these hours, studio time (the TCPA allows 8 AM to 9 PM; some states are stricter). */
    public const SEND_FROM_HOUR = 9;

    public const SEND_UNTIL_HOUR = 20;

    /** Shown with every box that opts someone in to texts (CTIA: frequency, charges, HELP and STOP). */
    public const DISCLOSURE = 'Message frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out.';

    /** Ticking a text box needs a number to text. */
    public static function assertTextable(?string $phone, string $field = 'phone'): void
    {
        if (! Phone::e164($phone)) {
            throw ValidationException::withMessages([$field => 'Enter a mobile number we can text, like (303) 555-0123, to get texts.']);
        }
    }

    /** The opt-in boxes, by what they agree to. */
    public static function optInLabel(Studio $studio, string $kind): string
    {
        return $kind === 'marketing' ? "Text me news and offers from {$studio->name}" : "Text me class reminders and booking updates from {$studio->name}";
    }

    /** What Twilio's error codes mean, for the delivery log and the Text messages page. */
    public const ERRORS = [
        20003 => 'Twilio refused your Account SID or Auth Token',
        21211 => 'Not a valid phone number',
        21408 => 'Your Twilio account can’t text that country yet (Twilio: Messaging → Settings → Geo permissions)',
        21610 => 'They replied STOP to your number',
        21612 => 'Twilio can’t text that number from yours',
        21614 => 'Not a mobile number',
        30003 => 'Their phone was off or out of reach',
        30004 => 'They’ve blocked texts from your number',
        30005 => 'That number doesn’t exist',
        30006 => 'A landline, or a carrier that doesn’t take texts',
        30007 => 'Their carrier filtered it as spam',
        30008 => 'Their carrier couldn’t deliver it',
        30032 => 'Your toll-free number isn’t verified yet',
        30034 => 'Your number isn’t registered for US texting yet (A2P 10DLC)',
    ];

    /**
     * Text $user from $studio if they agreed to this $category of text there. A booking text they
     * didn't agree to isn't logged (they get the email); a marketing one is logged as suppressed,
     * like marketing email. Null when nothing was logged, or $dedupeKey was already used.
     */
    public function send(?User $user, Studio $studio, string $kind, string $body, ?string $dedupeKey = null, string $category = 'transactional', ?int $domainEventId = null, ?int $automationId = null): ?MessageDelivery
    {
        if (! $user || ! $studio->textsReady()) {
            return null;
        }
        $allowed = $user->phone_e164 !== null && self::allows(StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->first(), $category);
        if (! $allowed && $category !== 'marketing') {
            return null;
        }

        return $this->queue($studio, $user, $kind, self::compose($studio, $body, $category), $dedupeKey, $category, $allowed, $category, null, $domainEventId, $automationId);
    }

    /**
     * Right after someone opts in: what they'll get and how to stop, as the carriers require
     * (CTIA). $granted: 'updates', 'marketing' or both.
     *
     * @param  list<string>  $granted
     */
    public function confirm(StudioUser $membership, array $granted): ?MessageDelivery
    {
        $studio = $membership->studio;
        $user = $membership->user;
        if (! $granted || ! $studio->textsReady() || ! $user?->phone_e164) {
            return null;
        }
        $what = match (true) {
            count($granted) > 1 => 'texts about your classes, and news and offers',
            $granted[0] === 'updates' => 'texts about your classes (reminders, waitlist spots, cancellations)',
            default => 'news and offers by text',
        };

        return $this->queue($studio, $user, 'texts_confirmed',
            "{$studio->name}: You’ll now get {$what}. Msg frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out.");
    }

    /** A test from Admin → Text messages, to any number. */
    public function test(Studio $studio, string $phone): ?MessageDelivery
    {
        return $this->queue($studio, null, 'text_test', "{$studio->name}: This is a test text from Loft. Texts to your members will come from this number.", to: $phone);
    }

    /** A test of an automation's text, to a staff member's own phone. */
    public function testTo(User $staff, Studio $studio, string $body): ?MessageDelivery
    {
        return $staff->phone_e164 ? $this->queue($studio, $staff, 'automation_test', self::compose($studio, $body, 'marketing')) : null;
    }

    /** "Skyline Aerial: …", and how to stop for marketing texts. */
    public static function compose(Studio $studio, string $body, string $category = 'transactional'): string
    {
        return $studio->name.': '.trim($body).($category === 'marketing' ? ' Reply STOP to opt out.' : '');
    }

    /**
     * Curly quotes, dashes and ellipses as plain characters. One character outside the GSM alphabet
     * makes the whole text Unicode, which fits 70 characters to a segment instead of 160.
     */
    public static function plain(string $text): string
    {
        return strtr($text, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ']);
    }

    public static function allows(?StudioUser $membership, string $category): bool
    {
        return (bool) ($category === 'marketing' ? $membership?->wantsTextMarketing() : $membership?->wantsTextUpdates());
    }

    /** "30034: Your number isn’t registered for US texting yet (A2P 10DLC)" */
    public static function error(?int $code, string $fallback): string
    {
        return $code ? $code.': '.(self::ERRORS[$code] ?? $fallback) : $fallback;
    }

    /**
     * When a marketing text may go out: now, or 9 AM studio time if it's outside the hours. Booking
     * texts go when they're due.
     */
    public static function sendAt(Studio $studio, string $category): ?Carbon
    {
        $local = $studio->localNow();
        if ($category !== 'marketing' || ($local->hour >= self::SEND_FROM_HOUR && $local->hour < self::SEND_UNTIL_HOUR)) {
            return null;
        }
        $day = $local->hour >= self::SEND_UNTIL_HOUR ? $local->copy()->addDay() : $local;

        return $studio->parseLocal($day->toDateString().sprintf(' %02d:00:00', self::SEND_FROM_HOUR));
    }

    /** Log it and queue the job. $consent: re-checked when the job runs ('transactional' or 'marketing'), or null for texts that don't need it. */
    private function queue(Studio $studio, ?User $user, string $kind, string $text, ?string $dedupeKey = null, string $category = 'transactional', bool $allowed = true, ?string $consent = null, ?string $to = null, ?int $domainEventId = null, ?int $automationId = null): ?MessageDelivery
    {
        $key = $dedupeKey ?? $kind.':sms:'.Str::uuid();
        $inserted = MessageDelivery::query()->insertOrIgnore([
            'studio_id' => $studio->id,
            'user_id' => $user?->id,
            'domain_event_id' => $domainEventId,
            'automation_id' => $automationId,
            'channel' => 'sms',
            'category' => $category,
            'kind' => $kind,
            'dedupe_key' => $key,
            'status' => $allowed ? 'queued' : 'suppressed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted === 0) {
            return null;
        }
        $delivery = MessageDelivery::where('dedupe_key', $key)->firstOrFail();
        if ($allowed) {
            $job = SendText::dispatch($delivery->id, self::plain($text), $to, $consent);
            if ($at = self::sendAt($studio, $category)) {
                $job->delay($at);
            }
        }

        return $delivery;
    }
}
