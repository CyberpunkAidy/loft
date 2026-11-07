<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Services\Bookings;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class HousekeepingTest extends TestCase
{
    public function test_reminders_go_out_once_in_the_right_hour(): void
    {
        Mail::fake();
        $studio = $this->studio(); // reminder 24h before
        $user = $this->userIn($studio);
        $this->membership($studio, $user);
        $program = $this->program($studio);
        $due = app(Bookings::class)->book($this->classSession($program, ['starts_at' => now()->addHours(24)->subMinutes(20)]), $this->attendee($studio, $user), $user->id);
        app(Bookings::class)->book($this->classSession($program, ['starts_at' => now()->addHours(30)]), $this->attendee($studio, $user), $user->id);
        Mail::assertQueued(StudioMessage::class, 2); // the booking confirmations

        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();

        Mail::assertQueued(StudioMessage::class, fn ($m) => $m->kind === 'reminder' && $m->details['Attendee'] === $due->attendee->name);
        $this->assertSame(1, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'reminder')->count());
    }

    public function test_expiry_and_credit_warnings(): void
    {
        Mail::fake();
        $studio = $this->studio();
        $user = $this->userIn($studio);
        $expired = $this->credits($studio, $user, 5, ['expires_at' => now()->subHour()]);
        $soon = $this->credits($studio, $user, 5, ['expires_at' => now()->addDays(3)]);
        $comped = $this->membership($studio, $user, null, ['expires_at' => now()->subDay()]);
        $billed = $this->membership($studio, $user, null, ['expires_at' => now()->subDay(), 'stripe_subscription_id' => 'sub_1']);

        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->artisan('loft:housekeeping')->assertSuccessful();

        $this->assertSame('expired', $expired->fresh()->status);
        $this->assertSame('active', $soon->fresh()->status);
        $this->assertSame('expired', $comped->fresh()->status);
        $this->assertSame('active', $billed->fresh()->status, 'Stripe decides when billed memberships end');
        $this->assertSame(1, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'credits_expiring')->count());
    }
}
