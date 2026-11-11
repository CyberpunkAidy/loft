<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person with a login. Users are global: the same account can be a member of one
 * studio and staff at another. Their role per studio lives in studio_users.
 */
#[Fillable(['first_name', 'last_name', 'email', 'phone', 'password', 'import_id', 'terms_accepted_at', 'terms_version', 'demo_expires_at', 'demo_studio_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** @var array<int, StudioUser|null> */
    private array $membershipCache = [];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'terms_accepted_at' => 'datetime',
            'demo_expires_at' => 'datetime',
        ];
    }

    /** An account made inside a demo studio (decision 44), deleted with it. */
    public function isDemo(): bool
    {
        return $this->demo_expires_at !== null;
    }

    /**
     * What a new account made inside $studio gets, so a demo's accounts go when it does. Every
     * place that makes accounts for a studio (joining, staff, imports) adds this.
     *
     * @return array<string, mixed>
     */
    public static function madeIn(Studio $studio): array
    {
        return $studio->isDemo() ? ['demo_expires_at' => $studio->demo_expires_at, 'demo_studio_id' => $studio->id] : [];
    }

    /** They agree to the current Terms of Service and Privacy Policy (account creation, invitation accepted). */
    public static function termsAccepted(): array
    {
        return ['terms_accepted_at' => now(), 'terms_version' => (string) config('loft.legal.terms_version')];
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function studioUsers(): HasMany
    {
        return $this->hasMany(StudioUser::class);
    }

    /** The member's app installs, with their push notification tokens. */
    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function studios(): BelongsToMany
    {
        return $this->belongsToMany(Studio::class, 'studio_users')
            ->withPivot(['role', 'stripe_customer_id'])
            ->withTimestamps();
    }

    public function membershipIn(Studio $studio): ?StudioUser
    {
        if (! array_key_exists($studio->id, $this->membershipCache)) {
            $this->membershipCache[$studio->id] = $this->studioUsers()->where('studio_id', $studio->id)->first();
        }

        return $this->membershipCache[$studio->id];
    }

    public function forgetMemberships(): void
    {
        $this->membershipCache = [];
    }

    /** True when the user's role in the studio is at least $role (member < instructor < admin < owner). */
    public function hasRoleIn(Studio $studio, string $role = 'member'): bool
    {
        $membership = $this->membershipIn($studio);

        return $membership !== null && $membership->atLeast($role);
    }
}
