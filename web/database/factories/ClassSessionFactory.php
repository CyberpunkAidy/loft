<?php

namespace Database\Factories;

use App\Models\ClassSession;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClassSession> */
class ClassSessionFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->addDays(3)->setTime(17, 0)->utc();

        return [
            'program_id' => Program::factory(),
            'studio_id' => fn (array $attrs) => Program::find($attrs['program_id'])->studio_id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'capacity' => 10,
            'status' => 'scheduled',
        ];
    }
}
