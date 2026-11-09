<?php

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;

/** One person's place in a class: booked, waitlisted, attended or cancelled. @mixin \App\Models\Booking */
class BookingResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $session = $this->classSession;
        $studio = $session->studio;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'position' => $this->position,
            'apparatus' => $this->apparatusLabel(),
            'attendee' => ['id' => $this->attendee->id, 'first_name' => $this->attendee->first_name, 'last_name' => $this->attendee->last_name],
            'session' => [
                'id' => $session->id,
                'title' => $session->program->title,
                'status' => $session->status,
                'starts_at' => self::utc($session->starts_at),
                'ends_at' => self::utc($session->ends_at),
                'starts_at_local' => self::local($studio, $session->starts_at),
                'room' => $session->room?->name,
            ],
            'booked_at' => self::utc($this->booked_at),
            'cancelled_at' => self::utc($this->cancelled_at),
            'can_cancel' => in_array($this->status, ['booked', 'waitlisted'], true) && ! $session->hasStarted(),
        ];
    }
}
