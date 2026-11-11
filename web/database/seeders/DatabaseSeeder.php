<?php

namespace Database\Seeders;

use App\Models\Studio;
use App\Services\Demo\SampleStudio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * The sample studio to click around in locally: `php artisan migrate:fresh --seed`, then sign in at
 * /login. SEED_BUSY=1 adds a lived-in month (what each demo studio gets; decision 44).
 *   owner@example.com / password      studio owner (admin)
 *   coach@example.com / password      instructor (check-in only)
 *   parent@example.com / password     member with two kids and a membership
 *   adult@example.com / password      member with a class pack
 */
class DatabaseSeeder extends Seeder
{
    public function run(SampleStudio $sample): void
    {
        Event::fake(); // no welcome/booking emails for sample data

        $studio = Studio::create(SampleStudio::ATTRIBUTES + ['slug' => 'skyline', 'contact_email' => 'hello@skyline.example']);
        $sample->build($studio, fn (string $key) => "{$key}@example.com", ['password' => 'password'], busy: (bool) env('SEED_BUSY', false));

        $this->command?->info("Sample studio: /{$studio->slug}. Sign in as owner@example.com / password");
    }
}
