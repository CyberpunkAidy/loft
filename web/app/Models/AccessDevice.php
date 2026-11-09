<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A check-in kiosk or reader. It signs in with its own token (shown once, stored hashed), only ever
 * for its studio, and only to check people in: it isn't a user account.
 */
#[Fillable(['studio_id', 'name', 'token_hash', 'token_last4', 'created_by_user_id', 'last_seen_at', 'revoked_at'])]
class AccessDevice extends Model
{
    public const TOKEN_PREFIX = 'loftdev_';

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    /** A new device and its token (the only time the token is seen in full). @return array{AccessDevice, string} */
    public static function issue(Studio $studio, string $name, ?User $by = null): array
    {
        $token = self::TOKEN_PREFIX.Str::random(40);
        $device = $studio->accessDevices()->create([
            'name' => $name,
            'token_hash' => self::hash($token),
            'token_last4' => substr($token, -4),
            'created_by_user_id' => $by?->id,
        ]);

        return [$device, $token];
    }

    /** The device a token belongs to, if it's still in use. */
    public static function findByToken(?string $token): ?self
    {
        if (! is_string($token) || ! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        return self::where('token_hash', self::hash($token))->whereNull('revoked_at')->first();
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
