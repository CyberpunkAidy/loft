<?php

namespace App\Services;

use App\Contracts\DomainEventHandler;
use App\Jobs\RelayDomainEvents;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Studio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * The outbox. record() writes an event in the caller's transaction, so the event exists exactly
 * when the change it describes does. relay() then hands events to the handlers in
 * config('loft.event_handlers'), marking each relayed; a handler that throws is retried, up to
 * MAX_ATTEMPTS times. Events are also the history: statuses elsewhere get overwritten.
 */
class DomainEvents
{
    public const MAX_ATTEMPTS = 5;

    /** @param array<string, mixed> $payload IDs and facts only: no names or email addresses */
    public function record(string $type, Studio|int|null $studio, array $payload = [], ?int $userId = null, ?int $attendeeId = null): DomainEvent
    {
        if (! isset(DomainEvent::TYPES[$type])) {
            throw new InvalidArgumentException("Unknown domain event type [{$type}].");
        }
        $event = DomainEvent::create([
            'uuid' => (string) Str::uuid(),
            'studio_id' => $studio instanceof Studio ? $studio->id : $studio,
            'type' => $type,
            'user_id' => $userId,
            'attendee_id' => $attendeeId,
            'payload' => $payload,
            'occurred_at' => now(),
        ]);
        if ($this->handlerClasses()) {
            RelayDomainEvents::dispatch()->afterCommit();
        }

        return $event;
    }

    /** An event about a booking, with the class it's for. */
    public function forBooking(string $type, Booking $booking, array $extra = []): DomainEvent
    {
        $session = $booking->classSession;

        return $this->record($type, $booking->studio_id, [
            'booking_id' => $booking->id,
            'session_id' => $booking->class_session_id,
            'program_id' => $session?->program_id,
            'program_title' => $session?->program?->title,
            'starts_at' => $session?->starts_at?->toIso8601String(),
        ] + $extra, $booking->user_id, $booking->attendee_id);
    }

    /** An event about a membership or credit balance. */
    public function forEntitlement(string $type, Entitlement $entitlement, array $extra = []): DomainEvent
    {
        return $this->record($type, $entitlement->studio_id, [
            'entitlement_id' => $entitlement->id,
            'plan_id' => $entitlement->plan_id,
            'plan_name' => $entitlement->plan?->name,
            'kind' => $entitlement->kind,
        ] + $extra, $entitlement->user_id);
    }

    /**
     * Hand unrelayed events to the handlers, oldest first. Each event is relayed in its own
     * transaction with the row locked (skipping rows another worker holds), so a failure rolls back
     * only that event's handler work. Returns how many were relayed.
     */
    public function relay(int $limit = 500): int
    {
        $handlers = array_map(fn (string $class) => app($class), $this->handlerClasses());
        if (! $handlers) {
            return DomainEvent::whereNull('relayed_at')->update(['relayed_at' => now()]);
        }

        $relayed = 0;
        $ids = DomainEvent::whereNull('relayed_at')->where('relay_attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try {
                $relayed += DB::transaction(function () use ($id, $handlers) {
                    $event = DomainEvent::whereKey($id)->whereNull('relayed_at')
                        ->lock(DB::getDriverName() === 'pgsql' ? 'for update skip locked' : true)->first();
                    if (! $event) {
                        return 0; // another worker has it, or had it
                    }
                    foreach ($handlers as $handler) {
                        if ($handler->handles($event->type)) {
                            $handler->handle($event);
                        }
                    }
                    $event->update(['relayed_at' => now(), 'relay_error' => null]);

                    return 1;
                });
            } catch (Throwable $e) {
                report($e);
                DomainEvent::whereKey($id)->increment('relay_attempts', 1, ['relay_error' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        }

        return $relayed;
    }

    /** @return list<class-string<DomainEventHandler>> */
    private function handlerClasses(): array
    {
        return array_values((array) config('loft.event_handlers', []));
    }
}
