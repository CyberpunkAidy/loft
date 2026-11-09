<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message to one member: what kind, whether it went out, and the key that stops it going out
 * twice. Holds no address or content; the member's account is unlinked if it's deleted.
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
    ];

    public const STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'failed' => 'Failed', 'suppressed' => 'Not sent (no consent)'];

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

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
