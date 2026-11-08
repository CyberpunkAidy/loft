<?php

namespace Tests;

use App\Models\Attendee;
use App\Models\Category;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Plan;
use App\Models\Program;
use App\Models\Studio;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Stripe\StripeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function studio(array $attributes = []): Studio
    {
        $studio = Studio::factory()->create($attributes);
        Category::seedDefaults($studio);

        return $studio;
    }

    protected function userIn(Studio $studio, string $role = 'member', array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $studio->studioUsers()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    /** @param list<string> $planTags plan tag slugs (created if missing) */
    protected function program(Studio $studio, array $attributes = [], array $planTags = ['recreational']): Program
    {
        $program = Program::factory()->create(['studio_id' => $studio->id] + $attributes);
        $program->categories()->sync(Category::ensure($studio, 'plan_tag', $planTags));

        return $program->load('categories');
    }

    protected function classSession(Program $program, array $attributes = []): ClassSession
    {
        $start = $attributes['starts_at'] ?? now()->addDays(3)->setTime(17, 0);

        return ClassSession::create(array_merge([
            'studio_id' => $program->studio_id,
            'program_id' => $program->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHour(),
            'capacity' => 10,
            'status' => 'scheduled',
        ], $attributes));
    }

    protected function attendee(Studio $studio, User $user, array $attributes = []): Attendee
    {
        return Attendee::factory()->create(['studio_id' => $studio->id, 'user_id' => $user->id] + $attributes);
    }

    protected function credits(Studio $studio, User $user, int $credits = 5, array $attributes = []): Entitlement
    {
        return Entitlement::factory()->create([
            'studio_id' => $studio->id, 'user_id' => $user->id, 'credits_total' => $credits, 'credits_remaining' => $credits,
        ] + $attributes);
    }

    protected function membership(Studio $studio, User $user, ?int $weeklyLimit = null, array $attributes = []): Entitlement
    {
        return Entitlement::factory()->membership($weeklyLimit)->create(['studio_id' => $studio->id, 'user_id' => $user->id] + $attributes);
    }

    protected function plan(Studio $studio, array $attributes = []): Plan
    {
        return Plan::factory()->create(['studio_id' => $studio->id] + $attributes);
    }

    protected function waiver(Studio $studio, array $attributes = []): Waiver
    {
        $waiver = Waiver::factory()->create(['studio_id' => $studio->id] + $attributes);
        $studio->forgetActiveWaivers();

        return $waiver;
    }

    /** Configure the platform Stripe key and fake api.stripe.com. */
    protected function fakeStripe(array $responses = []): void
    {
        config(['loft.stripe.secret' => 'sk_test_platform', 'loft.stripe.webhook_secret' => 'whsec_test']);
        $this->app->forgetInstance(StripeClient::class);
        Http::preventStrayRequests();
        Http::fake($responses);
    }

    /** A signed webhook delivery. */
    protected function postWebhook(array $event): TestResponse
    {
        $payload = json_encode($event);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, (string) config('loft.stripe.webhook_secret'));

        return $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }
}
