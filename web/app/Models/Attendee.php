<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

/**
 * A person who attends classes: a child on a family account, or the account holder ("self").
 */
#[Fillable(['studio_id', 'user_id', 'first_name', 'last_name', 'dob', 'is_self', 'medical_notes', 'emergency_contact', 'status', 'import_id',
    'health_answered_at', 'photo_consent', 'photo_consent_at', 'photo_consent_by'])]
class Attendee extends Model
{
    /** The photo question's answers (decision 45), as the member reads them. */
    public const PHOTO_CONSENT = [
        'public' => 'Yes, including on social media',
        'studio' => 'Only inside the studio (not online)',
        'none' => 'No photos or video',
    ];

    /** The same answers, short, for staff on rosters. */
    public const PHOTO_CONSENT_SHORT = [
        'public' => 'Photos OK',
        'studio' => 'In-studio photos only',
        'none' => 'No photos',
    ];

    /** The questions every person answers before booking, and the setting that turns each off. */
    public const QUESTIONS = ['health' => 'ask_health', 'photos' => 'ask_photo_consent'];

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

    /**
     * Rules for the answers to the studio's questions. With $required, each question the studio asks
     * must be answered (the website's form, and adding someone in the app); otherwise they're optional.
     * "Yes" to the health question needs the details, which are kept in medical_notes.
     *
     * @return array<string, mixed>
     */
    public static function answerRules(Studio $studio, bool $required): array
    {
        $asked = self::questionsAsked($studio);
        $need = fn (string $q) => $required && in_array($q, $asked, true) ? 'required' : 'nullable';

        return [
            'has_health_notes' => [$need('health'), 'boolean'],
            'medical_notes' => ['nullable', 'required_if_accepted:has_health_notes', 'string', 'max:2000'],
            'photo_consent' => [$need('photos'), Rule::in(array_keys(self::PHOTO_CONSENT))],
        ];
    }

    /** @return array<string, string> */
    public static function answerMessages(): array
    {
        return [
            'has_health_notes.required' => 'Choose yes or no.',
            'medical_notes.required_if_accepted' => 'Tell us about the injuries or conditions.',
            'photo_consent.required' => 'Choose one.',
            'photo_consent.in' => 'Choose one.',
        ];
    }

    /** @return list<string> the questions this studio asks: health, photos, both or neither */
    public static function questionsAsked(Studio $studio): array
    {
        return array_keys(array_filter(self::QUESTIONS, fn (string $setting) => (bool) $studio->setting($setting)));
    }

    /** @return list<string> the questions this person still has to answer before they book */
    public function questionsNeeded(Studio $studio): array
    {
        return array_values(array_filter(self::questionsAsked($studio), fn (string $q) => match ($q) {
            'health' => $this->health_answered_at === null,
            'photos' => $this->photo_consent === null,
        }));
    }

    /**
     * What to save from validated answers. Answering the health question records when (so "no" counts,
     * and staff can see how recent it is); "no" clears the details. A new photo answer records when and
     * who gave it, a parent for their child. Without a health answer, medical notes save as they are.
     *
     * @return array<string, mixed>
     */
    public function answersFrom(array $data, User $by): array
    {
        $out = [];
        if (isset($data['has_health_notes'])) {
            $yes = filter_var($data['has_health_notes'], FILTER_VALIDATE_BOOLEAN);
            $out['medical_notes'] = $yes ? (trim((string) ($data['medical_notes'] ?? '')) ?: null) : null;
            $out['health_answered_at'] = now();
        } elseif (array_key_exists('medical_notes', $data)) {
            $out['medical_notes'] = $data['medical_notes'];
        }
        if (! empty($data['photo_consent']) && $data['photo_consent'] !== $this->photo_consent) {
            $out += ['photo_consent' => $data['photo_consent'], 'photo_consent_at' => now(), 'photo_consent_by' => $by->id];
        }

        return $out;
    }

    /** @param list<string> $questions what's left to answer, from questionsNeeded() */
    public function questionsMessage(array $questions): string
    {
        $what = count($questions) === 1 ? 'a quick question' : 'two quick questions';

        return $this->is_self ? 'Answer '.$what.' before booking.' : "Answer {$what} about {$this->first_name} before booking.";
    }

    /** The health answer: null until answered, then whether there's anything instructors should know. */
    public function hasHealthNotes(): ?bool
    {
        return $this->health_answered_at === null ? null : filled($this->medical_notes);
    }

    use HasFactory;

    /** Column defaults, so new instances match what the database would store. */
    protected $attributes = ['status' => 'active', 'is_self' => false];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'is_self' => 'boolean',
            'health_answered_at' => 'datetime',
            'photo_consent_at' => 'datetime',
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

    /** Who gave the photo answer: the account holder, or staff who recorded it. */
    public function photoConsentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'photo_consent_by');
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

    /** NFC or RFID tags this person checks in with. */
    public function accessCredentials(): HasMany
    {
        return $this->hasMany(AccessCredential::class);
    }
}
