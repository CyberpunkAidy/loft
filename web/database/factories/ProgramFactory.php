<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Program> */
class ProgramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'title' => fake()->randomElement(['Aerial Silks', 'Lyra', 'Trapeze', 'Flexibility', 'Hip Hop', 'Ballet']).' '.fake()->randomElement(['Basics', 'Level 2', 'Open Practice']),
            'type' => 'class',
            'weekday' => 2,
            'start_time' => '17:00',
            'duration_min' => 60,
            'capacity' => 10,
            'is_published' => true,
        ];
    }
}
