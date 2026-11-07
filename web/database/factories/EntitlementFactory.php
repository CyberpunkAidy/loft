<?php

namespace Database\Factories;

use App\Models\Entitlement;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Entitlement> */
class EntitlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'user_id' => User::factory(),
            'kind' => 'credits',
            'status' => 'active',
            'credits_total' => 5,
            'credits_remaining' => 5,
            'expires_at' => now()->addDays(90),
        ];
    }

    public function membership(?int $weeklyLimit = null): static
    {
        return $this->state(fn () => ['kind' => 'subscription', 'credits_total' => null, 'credits_remaining' => null, 'expires_at' => null, 'weekly_limit' => $weeklyLimit]);
    }
}
