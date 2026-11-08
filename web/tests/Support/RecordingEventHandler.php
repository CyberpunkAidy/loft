<?php

namespace Tests\Support;

use App\Contracts\DomainEventHandler;
use App\Models\DomainEvent;
use RuntimeException;

/** A domain event handler for tests: remembers what it saw, and fails on the types it's told to. */
class RecordingEventHandler implements DomainEventHandler
{
    /** @var list<string> */
    public static array $seen = [];

    /** @var list<string> */
    public static array $failOn = [];

    public function handles(string $type): bool
    {
        return str_starts_with($type, 'booking.');
    }

    public function handle(DomainEvent $event): void
    {
        if (in_array($event->type, self::$failOn, true)) {
            throw new RuntimeException("Handler failed on {$event->type}");
        }
        self::$seen[] = $event->type;
    }
}
