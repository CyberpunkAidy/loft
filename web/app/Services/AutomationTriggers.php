<?php

namespace App\Services;

use App\Contracts\DomainEventHandler;
use App\Models\DomainEvent;

/** The outbox's first consumer: events that start an automation (registered in config/loft.php). */
class AutomationTriggers implements DomainEventHandler
{
    public function __construct(private readonly Automations $automations) {}

    public function handles(string $type): bool
    {
        return $type === 'booking.attended';
    }

    public function handle(DomainEvent $event): void
    {
        $this->automations->onEvent($event);
    }
}
