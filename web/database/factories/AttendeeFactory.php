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
            // Has answered the studio's questions (decision 45): nothing to tell, photos fine.
            'health_answered_at' => now(),
            'photo_consent' => 'public',
            'photo_consent_at' => now(),
        ];
    }

    /** Not asked the studio's questions yet, like everyone was when they arrived. */
    public function unanswered(): static
    {
        return $this->state(['health_answered_at' => null, 'photo_consent' => null, 'photo_consent_at' => null]);
    }
}
