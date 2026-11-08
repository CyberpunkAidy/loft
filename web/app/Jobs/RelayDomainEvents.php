<?php

namespace App\Jobs;

use App\Services\DomainEvents;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Hands new domain events to their handlers. Queued after each transaction that records events
 * (one at a time: events recorded while it runs queue the next); the hourly housekeeping run
 * sweeps up anything left.
 */
class RelayDomainEvents implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function handle(DomainEvents $events): void
    {
        $events->relay();
    }
}
