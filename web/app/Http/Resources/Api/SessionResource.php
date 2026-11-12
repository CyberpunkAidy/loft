<?php

namespace App\Http\Resources\Api;

use App\Models\ClassSession;
use App\Services\Bookings;
use App\Support\ApparatusSlot;
use Illuminate\Http\Request;

/**
 * A class on the schedule: when, what, who teaches, room, price, seats and waitlist, apparatus spots.
 * "mine" lists the signed-in member's bookings in it, when the request had a token (the controller
 * sets them as the myBookings relation).
 *
 * @mixin ClassSession
 */
class SessionResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $studio = $this->studio;
        $program = $this->program;
        $opensAt = app(Bookings::class)->opensAt($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status,
            'starts_at' => self::utc($this->starts_at),
            'ends_at' => self::utc($this->ends_at),
            'starts_at_local' => self::local($studio, $this->starts_at),
            'ends_at_local' => self::local($studio, $this->ends_at),
            'program' => [
                'id' => $program->id,
                'title' => $program->title,
                'description' => $program->description,
                'min_age' => $program->min_age,
                'max_age' => $program->max_age,
                'members_only' => (bool) $program->members_only,
                'categories' => $program->categories->map(fn ($c) => ['type' => $c->type, 'slug' => $c->slug, 'name' => $c->name])->values(),
            ],
            'instructor' => $this->instructor ? ['id' => $this->instructor->id, 'name' => $this->instructor->name] : null,
            'room' => $this->room ? ['id' => $this->room->id, 'name' => $this->room->name] : null,
            'drop_in_price_cents' => $this->price_cents ?? $program->dropin_price_cents,
            'currency' => $studio->currency,
            'delivery' => $this->delivery, // in_person | online | hybrid
            'capacity' => $this->capacity,
            'spots_left' => $this->spotsLeft(),
            'online_capacity' => $this->isHybrid() ? $this->online_capacity : null,
            'online_spots_left' => $this->onlineSpotsLeft(), // hybrid with a limit; null otherwise
            'is_full' => $this->isFull(),
            'waitlist' => ['count' => $this->waitlistCount(), 'capacity' => $this->waitlist_capacity, 'is_full' => $this->waitlistIsFull()],
            'apparatus' => $this->apparatusSlots()->map(fn (ApparatusSlot $slot) => [
                'id' => $slot->apparatus->id, 'name' => $slot->apparatus->name, 'spots' => $slot->spots(), 'spots_left' => $slot->left(),
            ])->values(),
            'booking_opens_at' => self::utc($opensAt),
            // Part of a series (decision 47): drop_ins false means only people signed up for it book it.
            'series' => $this->series_id && $this->series ? ['id' => $this->series->id, 'name' => $this->series->name, 'drop_ins' => $this->series->allow_dropins] : null,
            'mine' => $this->when($this->relationLoaded('myBookings'), fn () => $this->getRelation('myBookings')->map(fn ($b) => [
                'booking_id' => $b->id, 'attendee_id' => $b->attendee_id, 'status' => $b->status, 'position' => $b->position,
            ])->values()),
        ];
    }
}
