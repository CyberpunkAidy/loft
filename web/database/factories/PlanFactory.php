<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'studio_id' => Studio::factory(),
            'name' => '5-class pack',
            'kind' => 'pack',
            'price_cents' => 10000,
            'currency' => 'usd',
            'credits' => 5,
            'active' => true,
        ];
    }

    public function membership(?int $weeklyLimit = null): static
    {
        return $this->state(fn () => ['name' => 'Monthly membership', 'kind' => 'subscription', 'price_cents' => 9000, 'billing_interval' => 'month', 'credits' => null, 'weekly_limit' => $weeklyLimit]);
    }

    public function synced(): static
    {
        return $this->state(fn () => ['stripe_product_id' => 'prod_'.Str::random(12), 'stripe_price_id' => 'price_'.Str::random(12)]);
    }
}
