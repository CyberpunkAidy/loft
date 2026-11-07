<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tenant. Everything a studio owns hangs off one of these relationships, and routes
 * resolve child models through them (scoped bindings), so one studio can't reach another's rows.
 */
#[Fillable([
    'slug', 'name', 'timezone', 'currency', 'accent_color', 'contact_email', 'email_from_name', 'settings',
    'stripe_account_id', 'stripe_charges_enabled', 'stripe_details_submitted', 'stripe_portal_configuration_id',
])]
class Studio extends Model
{
    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['timezone' => 'America/Denver', 'currency' => 'usd', 'accent_color' => '#c2410c', 'stripe_charges_enabled' => false, 'stripe_details_submitted' => false];

    /** Booking policy defaults (the plugin's Settings::defaults()). Stored overrides live in `settings`. */
    public const DEFAULT_SETTINGS = [
        'late_cancel_hours' => 12,
        'late_cancel_keeps_credit' => false, // true = return the credit even on late cancels
        'no_show_consumes_credit' => true,
        'booking_opens_days_before' => 30,
        'booking_closes_minutes_before' => 0,
        'credit_validity_days' => 90,
        'waiver_validity_days' => 365,
        'reminder_hours_before' => 24,
        'waitlist_auto_promote' => true,
        'minor_age' => 18,
        'schedule_weeks' => 2,
    ];

    private ?Waiver $activeWaiverCache = null;

    private bool $activeWaiverLoaded = false;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'stripe_charges_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function setting(string $key): mixed
    {
        $stored = $this->settings ?? [];

        return array_key_exists($key, $stored) ? $stored[$key] : (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    /** @return array<string, mixed> */
    public function allSettings(): array
    {
        return array_merge(self::DEFAULT_SETTINGS, $this->settings ?? []);
    }

    /* ------------------------------------------------------------------ Time */

    /** A UTC datetime shown in the studio's timezone. */
    public function local(CarbonInterface $utc): Carbon
    {
        return Carbon::instance($utc)->copy()->setTimezone($this->timezone);
    }

    public function localNow(): Carbon
    {
        return Carbon::now($this->timezone);
    }

    /** Parse a studio-local date/time string into UTC. */
    public function parseLocal(string $local): Carbon
    {
        return Carbon::parse($local, $this->timezone)->utc();
    }

    /* ------------------------------------------------------------------ Display */

    public function accent(): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', (string) $this->accent_color) ? $this->accent_color : '#c2410c';
    }

    public function stripeReady(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

    public function activeWaiver(): ?Waiver
    {
        if (! $this->activeWaiverLoaded) {
            $this->activeWaiverCache = $this->waivers()->where('is_active', true)->latest('id')->first();
            $this->activeWaiverLoaded = true;
        }

        return $this->activeWaiverCache;
    }

    public function forgetActiveWaiver(): void
    {
        $this->activeWaiverLoaded = false;
        $this->activeWaiverCache = null;
    }

    public function currentSeason(): ?Season
    {
        return $this->seasons()->where('is_current', true)->orderByDesc('starts_on')->first();
    }

    /* ------------------------------------------------------------------ Relations */

    public function studioUsers(): HasMany
    {
        return $this->hasMany(StudioUser::class);
    }

    /** Every user with any role here. */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'studio_users')
            ->withPivot(['role', 'stripe_customer_id'])
            ->withTimestamps();
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    public function entitlements(): HasMany
    {
        return $this->hasMany(Entitlement::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function waivers(): HasMany
    {
        return $this->hasMany(Waiver::class);
    }
}
