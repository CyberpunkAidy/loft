<?php

namespace App\Jobs;

use App\Services\Demo\DemoStudios;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds one spare demo studio (decision 44) on the queue (loft-worker), and queues itself again
 * while more are needed, so each run stays well inside the queue's retry window (a build can take
 * half a minute on a small database). Only one waits in the queue at a time; housekeeping tops up
 * hourly too.
 */
class RefillDemoPool implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** A worker still running the previous release can't run it; another try lands on the new one. */
    public int $tries = 3;

    public int $backoff = 20;

    public int $timeout = 80;

    /** A job that dies before it starts (a worker on the old release) stops blocking new ones after this. */
    public int $uniqueFor = 120;

    public function handle(DemoStudios $demos): void
    {
        if ($demos->refill(max: 1) === 1) {
            $demos->requestSpares(); // another, if the pool still isn't full
        }
    }
}
