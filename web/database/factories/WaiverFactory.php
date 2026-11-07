<?php

namespace Database\Factories;

use App\Models\Studio;
use App\Models\Waiver;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Waiver> */
class WaiverFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'title' => 'Release of liability',
            'body' => "I understand that aerial arts involve risk.\n\nI agree to follow instructions.",
            'version' => 1,
            'is_active' => true,
        ];
    }
}
