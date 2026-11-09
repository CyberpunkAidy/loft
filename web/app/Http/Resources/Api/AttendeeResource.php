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
        ];
    }
}
