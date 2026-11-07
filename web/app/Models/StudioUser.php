<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's role in one studio, plus their Stripe customer on that studio's connected account.
 */
#[Fillable(['studio_id', 'user_id', 'role', 'stripe_customer_id'])]
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
