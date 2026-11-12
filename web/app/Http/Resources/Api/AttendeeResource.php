<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** Someone on the member's account who takes classes (themselves, or a child). @mixin \App\Models\Attendee */
class AttendeeResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'dob' => $this->dob?->toDateString(),
            'is_self' => (bool) $this->is_self,
            'is_minor' => $this->isMinor((int) $this->studio->setting('minor_age')),
            'emergency_contact' => $this->emergency_contact,
            'medical_notes' => $this->medical_notes,
            // The studio's questions (decision 45): null until answered.
            'has_health_notes' => $this->hasHealthNotes(),
            'health_answered_at' => self::utc($this->health_answered_at),
            'photo_consent' => $this->photo_consent,
            'photo_consent_at' => self::utc($this->photo_consent_at),
            // Recorded by staff from what the member told them (decision 46), and how they heard.
            'photo_consent_by_studio' => $this->photoRecordedByStudio(),
            'photo_consent_note' => $this->photoRecordedByStudio() ? $this->photo_consent_note : null,
            'questions_needed' => $this->questionsNeeded($this->studio),
        ];
    }
}
