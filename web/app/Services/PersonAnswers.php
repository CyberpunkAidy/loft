<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Saves a person's details with their answers to the studio's questions (decision 45), from the
 * website and the app alike, and photo answers staff record for a member (decision 46). Each new
 * photo answer goes into the member's history too, so the studio can show what was agreed, when, and
 * how it heard, even after it changes.
 */
class PersonAnswers
{
    public function __construct(
        private readonly DomainEvents $events,
        private readonly Messages $messages,
    ) {}

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

    /**
     * Staff record a member's photo answer, from what the member told them ($note: "At the front
     * desk"). The account holder is emailed, so a wrong answer gets noticed and changed. Returns false
     * when that was already the answer: nothing changes and no email goes.
     */
    public function recordByStaff(Attendee $attendee, string $answer, string $note, User $staff): bool
    {
        if ($attendee->photo_consent === $answer) {
            return false;
        }
        $event = DB::transaction(function () use ($attendee, $answer, $note, $staff) {
            $previous = $attendee->photo_consent;
            $attendee->update(['photo_consent' => $answer, 'photo_consent_at' => now(), 'photo_consent_by' => $staff->id, 'photo_consent_note' => $note]);

            return $this->events->record('consent.photos_answered', $attendee->studio_id, array_filter([
                'answer' => $answer,
                'previous' => $previous,
                'staff_id' => $staff->id,
                'note' => $note,
            ]), (int) $attendee->user_id, $attendee->id);
        });

        $studio = $attendee->studio;
        $whose = $attendee->is_self ? 'your' : "{$attendee->first_name}’s";
        $this->messages->email($attendee->user, new StudioMessage(
            $studio,
            ucfirst($whose).' photo answer was recorded',
            ["{$staff->first_name} at {$studio->name} recorded {$whose} answer to the photo and video question, from what you told them."],
            ['For' => $attendee->name, 'Photos and video' => Attendee::PHOTO_CONSENT[$answer], 'How we heard' => $note],
            ['If that isn’t right, you can change it any time, and your answer replaces this one.'],
            route('studio.attendees.edit', ['studio' => $studio, 'attendee' => $attendee]),
            'Check or change it',
            'photo_answer_recorded',
        ), domainEventId: $event->id);

        return true;
    }
}
