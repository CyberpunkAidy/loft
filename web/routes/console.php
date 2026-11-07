<?php

use Illuminate\Support\Facades\Schedule;

// Run `php artisan schedule:work` (or a cron entry calling `schedule:run` every minute) in production.
Schedule::command('loft:housekeeping')->hourly()->withoutOverlapping();
