<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An NFC or RFID tag (card, key fob, sticker) given to someone on a member's account. Only a keyed
 * hash of its UID is kept, plus the last few characters to tell tags apart.
 */
#[Fillable(['studio_id', 'attendee_id', 'kind', 'value_hash', 'value_last4', 'label', 'last_used_at', 'revoked_at'])]
class AccessCredential extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /** A tag's UID as readers report it, in one form: "04:a2:3b…", "04 A2 3B…" and "04A23B…" are the same tag. */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9a-z]/i', '', $value));
    }

    /** Keyed with the app key, so a copy of the database doesn't reveal tag UIDs. */
    public static function hash(string $value): string
    {
        return hash_hmac('sha256', 'tag:'.self::normalize($value), (string) config('app.key'));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
