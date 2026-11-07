<?php

namespace Database\Factories;

use App\Models\Attendee;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attendee> */
class AttendeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'user_id' => User::factory(),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'dob' => now()->subYears(12)->toDateString(),
            'status' => 'active',
        ];
    }
}
