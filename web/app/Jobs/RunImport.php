<?php

namespace App\Jobs;

use App\Models\Import;
use App\Services\Imports\Imports;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Import a file on the queue (loft-worker): a big client list takes longer than a web request should. */
class RunImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** One attempt: Imports::run records a failure itself, and running again is staff's choice. */
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $importId) {}

    public function handle(Imports $imports): void
    {
        if ($import = Import::find($this->importId)) {
            $imports->run($import);
        }
    }
}
