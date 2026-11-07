<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Remembers which scheduled emails went out (reminders, expiry warnings) so each sends once. */
#[Fillable(['kind', 'ref_id', 'sent_at'])]
class SentNotification extends Model
{
    public $timestamps = false;

    /** Record a notification; false if it was already recorded. */
    public static function once(string $kind, int $refId): bool
    {
        // insertOrIgnore is ON CONFLICT DO NOTHING on Postgres and INSERT OR IGNORE on SQLite.
        return self::query()->insertOrIgnore(['kind' => $kind, 'ref_id' => $refId, 'sent_at' => now()]) === 1;
    }
}
