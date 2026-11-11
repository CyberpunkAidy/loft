<?php

namespace App\Jobs;

use App\Services\Demo\DemoStudios;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Builds spare demo studios (decision 44) on the queue (loft-worker) after one is taken, so the
 * next visitor's starts instantly. One at a time; housekeeping tops up hourly too.
 */
class RefillDemoPool implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function handle(DemoStudios $demos): void
    {
        $demos->refill();
    }
}
