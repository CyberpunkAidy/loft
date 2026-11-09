<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Studio;
use Tests\TestCase;

/** `composer setup` and `migrate:fresh --seed` build the demo studio; keep it working. */
class DemoSeederTest extends TestCase
{
    public function test_the_demo_studio_seeds(): void
    {
        $this->seed();

        $studio = Studio::where('slug', 'skyline')->sole();
        $this->assertSame(['Flex room', 'Main studio'], $studio->rooms()->orderBy('name')->pluck('name')->all());
        $this->assertSame(['Silks 1', 'Silks 2'], Booking::with('apparatus')->whereHas('classSession.program', fn ($q) => $q->where('title', 'Adult Silks Basics'))
            ->where('status', 'booked')->orderBy('apparatus_item')->get()->map->apparatusLabel()->all());
        $this->assertSame(['first_class'], $studio->automations()->where('active', true)->pluck('trigger')->all());
        $this->assertSame([[1001, 'card', 11000, null], [1002, 'stripe', 1287, 'to_pick_up']], $studio->orders()->orderBy('number')->get()
            ->map(fn ($o) => [$o->number, $o->method, $o->total_cents, $o->fulfillment_status])->all());
        $this->get('/skyline')->assertOk();
    }
}
