<?php

namespace Database\Factories;

use App\Models\Studio;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Studio> */
class StudioFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company().' Studio';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'timezone' => 'America/Denver',
            'currency' => 'usd',
            'accent_color' => '#c2410c',
            'contact_email' => fake()->safeEmail(),
            'email_from_name' => $name,
        ];
    }

    /** A studio with a connected, charge-ready Stripe account. */
    public function stripeReady(): static
    {
        return $this->state(fn () => [
            'stripe_account_id' => 'acct_'.Str::random(16),
            'stripe_charges_enabled' => true,
            'stripe_details_submitted' => true,
        ]);
    }
}
