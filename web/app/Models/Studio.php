<?php

namespace App\Models;

use App\Services\StudioBilling;
use App\Support\BillingState;
use App\Support\Color;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
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
    'stripe_tax_rate_id', 'stripe_tax_rate_milli', 'stripe_terminal_location_id',
    'trial_ends_at', 'comped_at', 'comp_note', 'billing_customer_id', 'billing_subscription_id', 'billing_status',
    'billing_period_ends_at', 'billing_cancels_at', 'billing_payment_failed_at', 'billing_ended_at',
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
        'sales_tax_milli' => 0, // sales tax on taxable products, in thousandths of a percent (8875 = 8.875%)
    ];

    /** @var Collection<int, Waiver>|null */
    private ?Collection $activeWaiversCache = null;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'stripe_charges_enabled' => 'boolean',
            'stripe_details_submitted' => 'boolean',
            'trial_ends_at' => 'datetime',
            'comped_at' => 'datetime',
            'billing_period_ends_at' => 'datetime',
            'billing_cancels_at' => 'datetime',
            'billing_payment_failed_at' => 'datetime',
            'billing_ended_at' => 'datetime',
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

    /** Text color for labels on the brand color. */
    public function accentInk(): string
    {
        return Color::inkOn($this->accent());
    }

    /** The brand color as text on white, darkened if it's too light to read. */
    public function accentText(): string
    {
        return Color::textOnWhite($this->accent());
    }

    /** The first letter of the name, for the studio's badge and favicon. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim($this->name), 0, 1)) ?: '?';
    }

    public function stripeReady(): bool
    {
        return $this->stripe_account_id !== null && $this->stripe_charges_enabled;
    }

    /** Where the studio stands with its Loft subscription: comped, subscribed, trial, grace or paused. */
    public function billing(): BillingState
    {
        return app(StudioBilling::class)->state($this);
    }

    /** Paused for want of a Loft subscription: staff see only the subscription page, members can't book or buy. */
    public function isPaused(): bool
    {
        return $this->billing()->paused();
    }

    /** Waivers in force, with the programs they're attached to. */
    public function activeWaivers(): Collection
    {
        return $this->activeWaiversCache ??= $this->waivers()->where('is_active', true)->with('programs:id,title')->orderBy('id')->get();
    }

    public function forgetActiveWaivers(): void
    {
        $this->activeWaiversCache = null;
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

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function accessDevices(): HasMany
    {
        return $this->hasMany(AccessDevice::class);
    }

    public function accessCredentials(): HasMany
    {
        return $this->hasMany(AccessCredential::class);
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(AccessEvent::class);
    }

    public function cardReaders(): HasMany
    {
        return $this->hasMany(CardReader::class)->orderBy('label');
    }

    /** Sales tax on taxable products, as a percentage (8.25). */
    public function salesTaxPercent(): float
    {
        return ((int) $this->setting('sales_tax_milli')) / 1000;
    }

    public function automations(): HasMany
    {
        return $this->hasMany(Automation::class);
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

    /** Payments staff recorded by hand (see OfflinePayments). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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
