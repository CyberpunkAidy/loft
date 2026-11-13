<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message a studio sets up once that then goes out by itself (App\Services\Automations), by email,
 * text or both (decision 48). One per trigger per studio. Every automation counts as marketing: it
 * goes only to members who opted in to that channel, with an unsubscribe link or "Reply STOP".
 */
#[Fillable(['studio_id', 'trigger', 'conditions', 'channel', 'delay_minutes', 'subject', 'body', 'sms_body', 'active'])]
class Automation extends Model
{
    protected $attributes = ['channel' => 'email', 'delay_minutes' => 0, 'active' => false];

    /**
     * Each trigger: what it's called, when it sends, the placeholders its text can use, the button,
     * and the text a studio starts from.
     */
    public const TRIGGERS = [
        'first_class' => [
            'label' => 'After a first class',
            'description' => 'Thank someone for their first class at the studio, and invite them back.',
            'placeholders' => ['first_name', 'attendee_first_name', 'who', 'studio', 'class'],
            'action' => 'Book the next class',
            'delay_minutes' => 180,
            'subject' => 'Thanks for coming to {class}!',
            'body' => "Hi {first_name},\n\nThanks for joining us for {class}. We hope {who} had a great time.\n\nWhenever you're ready for the next one, it's all on the schedule.",
            'sms' => 'Thanks for coming to {class}! We hope {who} had a great time. Book the next one: {link}',
        ],
        'birthday' => [
            'label' => 'Birthday',
            'description' => 'Wish members, and the children on their account, a happy birthday.',
            'placeholders' => ['first_name', 'attendee_first_name', 'who', 'studio'],
            'action' => 'Book a class',
            'subject' => 'Happy birthday, {attendee_first_name}!',
            'body' => "Hi {first_name},\n\nEveryone at {studio} wishes {attendee_first_name} a very happy birthday!\n\nWe can't wait to see {who} in class.",
            'sms' => 'Happy birthday, {attendee_first_name}! Everyone at {studio} hopes it’s a great one.',
        ],
        'win_back' => [
            'label' => 'We miss you',
            'description' => 'Reach out when a member hasn’t been to class for a while and has nothing booked.',
            'placeholders' => ['first_name', 'studio', 'days'],
            'action' => 'See what’s on',
            'days' => 30,
            'subject' => 'We miss you at {studio}',
            'body' => "Hi {first_name},\n\nIt's been {days} days since we last saw you at {studio}, and your spot is waiting.\n\nTake a look at what's coming up this week.",
            'sms' => 'Hi {first_name}, it’s been {days} days and we miss you! See what’s on this week: {link}',
        ],
        'churn_risk' => [
            'label' => 'At risk of leaving',
            'description' => 'Check in when a member’s churn risk turns high (coming less, nothing booked, a plan running out), before they stop coming.',
            'placeholders' => ['first_name', 'studio'],
            'action' => 'See what’s on',
            'subject' => 'How are classes going, {first_name}?',
            'body' => "Hi {first_name},\n\nWe haven't seen as much of you at {studio} lately, and we wanted to check in. Is there a class time that would suit you better, or something you'd like to try?\n\nJust reply to this email, or take a look at what's coming up.",
            'sms' => 'Hi {first_name}, we haven’t seen as much of you lately. Is there a class time that would suit you better? See what’s on: {link}',
        ],
    ];

    public const PLACEHOLDERS = [
        'first_name' => 'the account holder’s first name',
        'attendee_first_name' => 'the student’s first name (the account holder’s, or their child’s)',
        'who' => '“you”, or the child’s first name when the class was a child’s',
        'studio' => 'the studio’s name',
        'class' => 'the class they came to',
        'days' => 'days since their last class',
        'link' => 'a link to your schedule (texts only)',
    ];

    /** How it goes out. Texts need Twilio connected (Admin → Text messages). */
    public const CHANNELS = ['email' => 'Email', 'sms' => 'Text message', 'both' => 'Email and text'];

    /** Longest text a studio can write: with the studio's name and "Reply STOP to opt out." it stays around two text segments. */
    public const SMS_MAX = 250;

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'active' => 'boolean',
            'delay_minutes' => 'integer',
        ];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** A studio's automation for $trigger, or a new one filled with the starting text. */
    public static function for(Studio $studio, string $trigger): self
    {
        return self::firstOrNew(['studio_id' => $studio->id, 'trigger' => $trigger], self::defaults($trigger));
    }

    /** @return array<string, mixed> */
    public static function defaults(string $trigger): array
    {
        $t = self::TRIGGERS[$trigger];

        return [
            'subject' => $t['subject'],
            'body' => $t['body'],
            'sms_body' => $t['sms'],
            'delay_minutes' => $t['delay_minutes'] ?? 0,
            'conditions' => isset($t['days']) ? ['days' => $t['days']] : null,
            'active' => false,
        ];
    }

    public function label(): string
    {
        return self::TRIGGERS[$this->trigger]['label'];
    }

    /** @return list<string> */
    public function placeholders(): array
    {
        return self::TRIGGERS[$this->trigger]['placeholders'];
    }

    public function sendsEmail(): bool
    {
        return $this->channel !== 'sms';
    }

    public function sendsText(): bool
    {
        return in_array($this->channel, ['sms', 'both'], true);
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? self::CHANNELS['email'];
    }

    /** The text version, or the starting text if the studio hasn't written one. */
    public function smsText(): string
    {
        return $this->sms_body ?? self::TRIGGERS[$this->trigger]['sms'];
    }

    /** @return list<string> placeholders the text version can use: the email's, plus {link} */
    public function smsPlaceholders(): array
    {
        return [...$this->placeholders(), 'link'];
    }

    public function days(): int
    {
        return (int) ($this->conditions['days'] ?? self::TRIGGERS['win_back']['days']);
    }

    /** "3 hours after the class", "On the birthday, from 10 AM"… */
    public function timing(): string
    {
        return match ($this->trigger) {
            'first_class' => match (true) {
                $this->delay_minutes === 0 => 'Right after the first class is checked in',
                $this->delay_minutes % 60 === 0 => ($h = intdiv($this->delay_minutes, 60)).' '.($h === 1 ? 'hour' : 'hours').' after the first class',
                default => $this->delay_minutes.' minutes after the first class',
            },
            'birthday' => 'On the birthday, from 10 AM studio time',
            'win_back' => $this->days().' days after their last class, if nothing is booked (from 10 AM)',
            'churn_risk' => 'The day a member’s risk turns high (from 10 AM), at most once a month each',
        };
    }

    /** Replace {placeholders} in $text. */
    public static function fillPlaceholders(string $text, array $values): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $values) ? (string) $values[$m[1]] : $m[0], $text);
    }

    /** @return list<string> {placeholders} in $text this trigger doesn't offer ($sms: the text version, which also has {link}) */
    public function unknownPlaceholders(string $text, bool $sms = false): array
    {
        preg_match_all('/\{([a-z_]+)\}/', $text, $m);

        return array_values(array_diff(array_unique($m[1]), $sms ? $this->smsPlaceholders() : $this->placeholders()));
    }
}
