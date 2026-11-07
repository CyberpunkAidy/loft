<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Season> */
class SeasonFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'name' => 'Current season',
            'starts_on' => now()->subMonth()->toDateString(),
            'ends_on' => now()->addMonths(6)->toDateString(),
            'is_current' => true,
        ];
    }
}
