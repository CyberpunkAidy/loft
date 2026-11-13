<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's role in one studio, plus their Stripe customer on that studio's connected account, and
 * what they agreed to hear from it: marketing email, booking texts and marketing texts.
 */
#[Fillable([
    'studio_id', 'user_id', 'role', 'stripe_customer_id',
    'email_marketing_consent_at', 'email_marketing_consent_source', 'email_unsubscribed_at',
    'import_id', 'invited_at', 'invitation_accepted_at',
    'sms_updates_at', 'sms_updates_source', 'sms_marketing_at', 'sms_marketing_source', 'sms_stopped_at',
])]
class StudioUser extends Model
{
    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['role' => 'member'];

    /** Ordered from least to most access. */
    public const ROLES = [
        'member' => 'Member',
        'instructor' => 'Instructor',
        'admin' => 'Admin',
        'owner' => 'Owner',
    ];

    public const STAFF_ROLES = ['instructor', 'admin', 'owner'];

    protected function casts(): array
    {
        return [
            'email_marketing_consent_at' => 'datetime',
            'email_unsubscribed_at' => 'datetime',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
            'sms_updates_at' => 'datetime',
            'sms_marketing_at' => 'datetime',
            'sms_stopped_at' => 'datetime',
        ];
    }

    /** Opted in to this studio's marketing emails, and hasn't unsubscribed since. */
    public function allowsMarketingEmail(): bool
    {
        return $this->email_marketing_consent_at !== null && $this->email_unsubscribed_at === null;
    }

    /** Agreed to texts about their bookings (reminders, waitlist moves, cancelled classes). */
    public function wantsTextUpdates(): bool
    {
        return $this->sms_updates_at !== null && $this->sms_stopped_at === null;
    }

    /** Agreed to the studio's news and offers by text (TCPA: written consent, kept apart from booking texts). */
    public function wantsTextMarketing(): bool
    {
        return $this->sms_marketing_at !== null && $this->sms_stopped_at === null;
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function rank(string $role): int
    {
        $index = array_search($role, array_keys(self::ROLES), true);

        return $index === false ? -1 : $index;
    }

    public function atLeast(string $role): bool
    {
        return self::rank($this->role) >= self::rank($role);
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }
}
