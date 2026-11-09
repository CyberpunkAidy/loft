<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A person who attends classes: a child on a family account, or the account holder ("self").
 */
#[Fillable(['studio_id', 'user_id', 'first_name', 'last_name', 'dob', 'is_self', 'medical_notes', 'emergency_contact', 'status'])]
class Attendee extends Model
{
    /** @return array<string, mixed> what a member can set on someone in their family (website and app) */
    public static function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'dob' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'emergency_contact' => ['nullable', 'string', 'max:190'],
            'medical_notes' => ['nullable', 'string', 'max:2000'],
            'is_self' => ['nullable', 'boolean'],
        ];
    }

    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'active', 'is_self' => false];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'is_self' => 'boolean',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(WaiverSignature::class);
    }

    /** Age in whole years on a date (today by default), or null without a date of birth. */
    public function age(?CarbonInterface $on = null): ?int
    {
        if (! $this->dob) {
            return null;
        }

        return (int) $this->dob->diffInYears($on ?? now(), absolute: true);
    }

    public function isMinor(int $minorAge = 18): bool
    {
        $age = $this->age();

        return $age !== null && $age < $minorAge;
    }
}
