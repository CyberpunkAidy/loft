<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one member, by email or text (channel email or sms): what kind, whether it went
 * out, and the key that stops it going out twice. Holds no address, number or content; the member's
 * account is unlinked if it's deleted.
 */
#[Fillable([
    'studio_id', 'user_id', 'domain_event_id', 'automation_id', 'channel', 'category', 'kind', 'dedupe_key', 'status',
    'provider_message_id', 'error', 'sent_at',
])]
class MessageDelivery extends Model
{
    public const KINDS = [
        'booked' => 'Booking confirmation',
        'waitlisted' => 'Waitlist confirmation',
        'promoted' => 'Moved in from the waitlist',
        'cancelled' => 'Booking cancelled',
        'session_cancelled' => 'Class cancelled',
        'purchase' => 'Purchase receipt',
        'payment_failed' => 'Payment failed',
        'checkout_failed' => 'Checkout payment failed',
        'welcome' => 'Welcome',
        'reminder' => 'Class reminder',
        'credits_expiring' => 'Credits expiring',
        'first_class' => 'After a first class (automation)',
        'birthday' => 'Birthday (automation)',
        'win_back' => 'We miss you (automation)',
        'churn_risk' => 'At risk of leaving (automation)',
        'automation_test' => 'Automation test',
        'receipt' => 'Receipt (point of sale)',
        'shop_order' => 'Shop order, ready to pick up',
        'texts_confirmed' => 'Signed up for texts',
        'text_test' => 'Test text',
        'text_reply' => 'A member’s text, forwarded',
    ];

    /** Texts go on from sent (Twilio has it) to delivered (the phone has it), when Twilio reports back. */
    public const STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'suppressed' => 'Not sent (no consent)'];

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? ucfirst(str_replace('_', ' ', $this->kind));
    }

    public function isText(): bool
    {
        return $this->channel === 'sms';
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
