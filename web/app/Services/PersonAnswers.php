<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Saves a person's details with their answers to the studio's questions (decision 45), from the
 * website and the app alike. Each new photo answer goes into the member's history too, so the studio
 * can show what was agreed and when, even after it changes.
 */
class PersonAnswers
{
    public function __construct(private readonly DomainEvents $events) {}

    /** @param array<string, mixed> $data validated: Attendee::rules() and/or Attendee::answerRules() */
    public function save(Attendee $attendee, array $data, User $by): Attendee
    {
        return DB::transaction(function () use ($attendee, $data, $by) {
            $previous = $attendee->exists ? $attendee->photo_consent : null;
            $attendee->fill(Arr::except($data, ['has_health_notes', 'medical_notes', 'photo_consent']) + $attendee->answersFrom($data, $by))->save();
            if ($attendee->photo_consent !== $previous) {
                $this->events->record('consent.photos_answered', $attendee->studio_id, array_filter([
                    'answer' => $attendee->photo_consent,
                    'previous' => $previous,
                ]), (int) $attendee->user_id, $attendee->id);
            }

            return $attendee;
        });
    }
}
