<?php

namespace App\Contracts;

use App\Models\DomainEvent;

/**
 * Something that consumes domain events from the outbox: automations, analytics, outgoing webhooks.
 * List handlers in config('loft.event_handlers').
 *
 * Delivery is at least once and not strictly ordered: a handler may see an event again after a
 * failure, so it must be idempotent (message_deliveries' dedupe keys make emails so).
 */
interface DomainEventHandler
{
    public function handles(string $type): bool;

    public function handle(DomainEvent $event): void;
}
