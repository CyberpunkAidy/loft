<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One scan at a kiosk or reader, allowed or not, and why. */
#[Fillable(['studio_id', 'access_device_id', 'access_credential_id', 'attendee_id', 'booking_id', 'method', 'result', 'reason', 'occurred_at', 'created_at'])]
class AccessEvent extends Model
{
    public const UPDATED_AT = null;

    public const REASONS = [
        'checked_in' => 'Checked in',
        'already_checked_in' => 'Already checked in',
        'unknown_credential' => 'Card or code not recognised',
        'expired_code' => 'Expired code',
        'no_booking' => 'Not booked into a class now',
        'waitlisted' => 'On the waitlist',
        'waiver_required' => 'Waiver not signed',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AccessDevice::class, 'access_device_id');
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function reasonLabel(): string
    {
        return self::REASONS[$this->reason] ?? (string) $this->reason;
    }
}
