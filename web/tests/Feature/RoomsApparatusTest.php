<?php

namespace Tests\Feature;

use App\Models\Apparatus;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Room;
use App\Models\Studio;
use App\Services\Bookings;
use App\Support\BookingException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Rooms, apparatus capacity and waitlist limits (roadmap step 2). */
class RoomsApparatusTest extends TestCase
{
    /**
     * A room with apparatus: ['Silks' => [quantity, max_per_item], …].
     *
     * @return array{0: Room, 1: array<string, Apparatus>}
     */
    private function rig(Studio $studio, array $apparatus, int $capacity = 16, string $name = 'Main studio'): array
    {
        $room = $studio->rooms()->create(['name' => $name, 'max_capacity' => $capacity]);
        $made = [];
        foreach ($apparatus as $label => [$quantity, $perItem]) {
            $made[$label] = $room->apparatus()->create(['studio_id' => $studio->id, 'name' => $label, 'quantity' => $quantity, 'max_per_item' => $perItem]);
        }

        return [$room, $made];
    }

    /** @param array<int, array{0: int, 1: int}> $use apparatus id => [quantity, max_per_item] */
    private function sessionUsing(Studio $studio, Room $room, array $use, array $attributes = []): ClassSession
    {
        $session = $this->classSession($this->program($studio), ['room_id' => $room->id] + $attributes);
        $session->apparatus()->sync(collect($use)->map(fn ($u) => ['quantity' => $u[0], 'max_per_item' => $u[1]])->all());

        return $session;
    }

    private function member(Studio $studio): Attendee
    {
        $user = $this->userIn($studio);
        $this->credits($studio, $user, 10);

        return $this->attendee($studio, $user);
    }

    private function book(ClassSession $session, Attendee $attendee, ?int $apparatusId = null): Booking
    {
        return app(Bookings::class)->book($session->fresh(), $attendee, (int) $attendee->user_id, apparatusId: $apparatusId);
    }

    private function expectReason(string $reason, callable $fn): BookingException
    {
        try {
            $fn();
        } catch (BookingException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());

            return $e;
        }
        $this->fail("Expected BookingException [{$reason}]");
    }

    public function test_bookings_take_a_spot_on_an_apparatus_with_an_item_number(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [5, 2], 'Lyra' => [3, 2]]);
        $session = $this->sessionUsing($studio, $room, [$a['Silks']->id => [2, 1], $a['Lyra']->id => [1, 2]]);
        $this->assertSame(4, $session->fresh()->spotsLeft(), 'apparatus spots, not the capacity of 10');

        $first = $this->book($session, $this->member($studio), $a['Silks']->id);
        $second = $this->book($session, $this->member($studio), $a['Silks']->id);
        $this->assertSame([$a['Silks']->id, 1, 'Silks 1'], [$first->apparatus_id, $first->apparatus_item, $first->apparatusLabel()]);
        $this->assertSame(2, $second->apparatus_item);

        // Silks are taken: asking for one waitlists, though the class itself has room.
        $waiting = $this->book($session, $this->member($studio), $a['Silks']->id);
        $this->assertSame(['waitlisted', $a['Silks']->id, null], [$waiting->status, $waiting->apparatus_id, $waiting->apparatus_item]);

        // No preference: whichever apparatus has the most room.
        $any = $this->book($session, $this->member($studio));
        $this->assertSame(['booked', $a['Lyra']->id, 1], [$any->status, $any->apparatus_id, $any->apparatus_item]);
        $this->assertSame(1, $session->fresh()->spotsLeft());
        $this->assertSame(1, $this->book($session, $this->member($studio))->apparatus_item, 'two share Lyra 1');

        // Every apparatus full: no preference waits for any.
        $anyWaiting = $this->book($session, $this->member($studio));
        $this->assertSame(['waitlisted', null], [$anyWaiting->status, $anyWaiting->apparatus_id]);
        $this->assertTrue($session->fresh()->isFull());

        $this->expectReason('apparatus', fn () => $this->book($session, $this->member($studio), $a['Lyra']->id + 100));
    }

    public function test_the_session_capacity_still_applies(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [6, 1]]);
        $session = $this->sessionUsing($studio, $room, [$a['Silks']->id => [6, 1]], ['capacity' => 2]);

        $this->book($session, $this->member($studio));
        $this->book($session, $this->member($studio));
        $this->assertSame('waitlisted', $this->book($session, $this->member($studio), $a['Silks']->id)->status);
        $this->assertSame(0, $session->fresh()->spotsLeft());
    }

    public function test_waitlist_entries_move_up_only_onto_the_apparatus_they_chose(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [1, 1], 'Lyra' => [1, 1]]);
        $session = $this->sessionUsing($studio, $room, [$a['Silks']->id => [1, 1], $a['Lyra']->id => [1, 1]]);
        $onSilks = $this->book($session, $this->member($studio), $a['Silks']->id);
        $onLyra = $this->book($session, $this->member($studio), $a['Lyra']->id);
        $wantsSilks = $this->book($session, $this->member($studio), $a['Silks']->id);
        $wantsAny = $this->book($session, $this->member($studio));
        $this->assertSame([1, 2], [$wantsSilks->position, $wantsAny->position]);

        // A Lyra opens: first in line wants Silks and keeps their place; the next takes the Lyra.
        app(Bookings::class)->cancel($onLyra, $onLyra->user_id);
        $this->assertSame(['waitlisted', 1], [$wantsSilks->fresh()->status, $wantsSilks->fresh()->position]);
        $this->assertSame(['booked', $a['Lyra']->id, 1], [$wantsAny->fresh()->status, $wantsAny->fresh()->apparatus_id, $wantsAny->fresh()->apparatus_item]);

        app(Bookings::class)->cancel($onSilks, $onSilks->user_id);
        $this->assertSame(['booked', 'Silks 1'], [$wantsSilks->fresh()->status, $wantsSilks->fresh()->apparatusLabel()]);
    }

    public function test_waitlist_limits(): void
    {
        $studio = $this->studio();
        $session = $this->classSession($this->program($studio), ['capacity' => 1, 'waitlist_capacity' => 1]);
        $this->book($session, $this->member($studio));
        $this->assertSame('waitlisted', $this->book($session, $this->member($studio))->status);
        $e = $this->expectReason('waitlist_full', fn () => $this->book($session, $this->member($studio)));
        $this->assertSame('This class and its waitlist are full.', $e->getMessage());

        $closed = $this->classSession($this->program($studio), ['capacity' => 1, 'waitlist_capacity' => 0]);
        $this->book($closed, $this->member($studio));
        $this->assertSame('This class is full.', $this->expectReason('waitlist_full', fn () => $this->book($closed, $this->member($studio)))->getMessage());

        // Members see it before trying; staff are told how to make room.
        $late = $this->member($studio);
        $this->actingAs($late->user)->get("/{$studio->slug}/book/{$session->id}")->assertOk()->assertSee('This class and its waitlist are full.');
        $this->actingAs($this->userIn($studio, 'owner'))
            ->post("/{$studio->slug}/admin/sessions/{$session->id}/bookings", ['attendee_id' => $late->id, 'comp' => '1'])
            ->assertSessionHas('error', "The class and its waitlist are full. To add {$late->name}, raise the capacity or the waitlist limit.");
        $this->assertSame(2, $session->bookings()->count());
    }

    public function test_managing_rooms_and_their_apparatus(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'owner'));
        $base = "/{$studio->slug}/admin/rooms";
        $this->get($base)->assertOk()->assertSee('No rooms yet');
        $this->get("{$base}/create")->assertOk();

        $this->post($base, ['name' => 'Main studio', 'max_capacity' => 16, 'new_apparatus' => [
            ['name' => 'Silks', 'quantity' => 6, 'max_per_item' => 1],
            ['name' => 'Lyra', 'quantity' => 3, 'max_per_item' => 2],
            ['name' => '', 'quantity' => '', 'max_per_item' => ''],
        ]])->assertRedirect();
        $room = Room::sole();
        [$lyra, $silks] = $room->apparatus->all(); // ordered by name
        $this->assertSame(['Lyra', 3, 2, 'Silks', 6, 1], [$lyra->name, $lyra->quantity, $lyra->max_per_item, $silks->name, $silks->quantity, $silks->max_per_item]);
        $this->get($base)->assertOk()->assertSee('Silks')->assertSee('6 × 1 person');
        $this->get("{$base}/{$room->id}/edit")->assertOk()->assertSee('Lyra');

        $this->post($base, ['name' => 'main studio', 'max_capacity' => 10])->assertSessionHasErrors('name');
        $this->post($base, ['name' => 'Annex', 'max_capacity' => 10, 'new_apparatus' => [['name' => 'Pole']]])
            ->assertSessionHasErrors(['new_apparatus.0.quantity', 'new_apparatus.0.max_per_item']);
        $this->post($base, ['name' => 'Annex', 'max_capacity' => 10, 'new_apparatus' => [
            ['name' => 'Pole', 'quantity' => 2, 'max_per_item' => 1], ['name' => 'pole ', 'quantity' => 2, 'max_per_item' => 1],
        ]])->assertSessionHasErrors('apparatus');

        // Fewer silks rigged: classes using more are cut back. Lyra removed: its bookings keep their spot.
        $session = $this->sessionUsing($studio, $room, [$silks->id => [6, 1], $lyra->id => [3, 2]]);
        $onLyra = $this->book($session, $this->member($studio), $lyra->id);
        $this->put("{$base}/{$room->id}", ['name' => 'Big room', 'max_capacity' => 14, 'apparatus' => [
            $silks->id => ['name' => 'Silks', 'quantity' => 4, 'max_per_item' => 1],
            $lyra->id => ['name' => 'Lyra', 'quantity' => 3, 'max_per_item' => 2, 'remove' => '1'],
        ]])->assertSessionHas('success');
        $this->assertSame('Big room', $room->fresh()->name);
        $this->assertSame([$silks->id => 4], $session->fresh()->apparatus->mapWithKeys(fn ($x) => [$x->id => $x->pivot->quantity])->all());
        $this->assertSame(['booked', null], [$onLyra->fresh()->status, $onLyra->fresh()->apparatus_id]);

        $this->delete("{$base}/{$room->id}")->assertRedirect($base);
        $this->assertNull($session->fresh()->room_id);
        $this->assertSame(0, Apparatus::count());
    }

    public function test_programs_give_their_sessions_a_room_apparatus_and_waitlist_limit(): void
    {
        $studio = $this->studio();
        $studio->seasons()->create(['name' => 'Now', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(3)->subDay()->toDateString(), 'is_current' => true]);
        [$room, $a] = $this->rig($studio, ['Silks' => [6, 2], 'Lyra' => [3, 2]]);
        $this->actingAs($this->userIn($studio, 'owner'));
        $fields = ['title' => 'Silks', 'type' => 'class', 'weekday' => now()->addDay()->dayOfWeek, 'start_time' => '16:30', 'duration_min' => 60, 'capacity' => 8, 'is_published' => '1'];

        $this->post("/{$studio->slug}/admin/programs", ['room_id' => $room->id, 'capacity' => 20] + $fields)
            ->assertSessionHasErrors(['capacity' => 'Main studio holds at most 16 people.']);
        $this->post("/{$studio->slug}/admin/programs", $fields + ['room_id' => $room->id, 'apparatus' => [$a['Silks']->id => ['quantity' => 7, 'max_per_item' => 3]]])
            ->assertSessionHasErrors(["apparatus.{$a['Silks']->id}.quantity", "apparatus.{$a['Silks']->id}.max_per_item"]);

        $this->post("/{$studio->slug}/admin/programs", $fields + [
            'room_id' => $room->id, 'waitlist_capacity' => 2,
            'apparatus' => [$a['Silks']->id => ['quantity' => 4, 'max_per_item' => 1], $a['Lyra']->id => ['quantity' => 0, 'max_per_item' => 2]],
        ])->assertSessionHas('success', 'Saved. 3 sessions created.');
        $program = Program::sole();
        $this->assertSame([$a['Silks']->id => [4, 1]], $program->apparatus->mapWithKeys(fn ($x) => [$x->id => [$x->pivot->quantity, $x->pivot->max_per_item]])->all());
        foreach ($program->sessions as $session) {
            $this->assertSame([$room->id, 2], [$session->room_id, $session->waitlist_capacity]);
            $this->assertSame(4, $session->apparatusSlots()->sole()->spots());
        }
        $this->get("/{$studio->slug}/admin/programs/{$program->id}/edit")->assertOk()->assertSee('Silks used');

        // Upcoming sessions keep their own settings unless the change is applied to them.
        $this->put("/{$studio->slug}/admin/programs/{$program->id}", $fields + ['room_id' => $room->id, 'apparatus' => [$a['Lyra']->id => ['quantity' => 3]]])
            ->assertSessionHas('success', 'Saved.');
        $this->assertSame(['Silks'], $program->sessions()->first()->apparatus->pluck('name')->all());
        $this->put("/{$studio->slug}/admin/programs/{$program->id}", $fields + ['room_id' => $room->id, 'waitlist_capacity' => '', 'apparatus' => [$a['Lyra']->id => ['quantity' => 3]], 'apply_upcoming' => '1'])
            ->assertSessionHas('success', 'Saved, and 3 upcoming sessions updated.');
        foreach ($program->sessions()->get() as $session) {
            $this->assertSame([null, 6], [$session->waitlist_capacity, $session->apparatusSlots()->sole()->spots()], 'Lyra: 3 × the rig’s 2');
        }
    }

    public function test_staff_set_a_sessions_room_and_apparatus_and_move_people(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [2, 1], 'Lyra' => [2, 1]]);
        $session = $this->classSession($this->program($studio, ['title' => 'Aerial Mix']), ['capacity' => 4]);
        $waiting = $this->book($session, $this->member($studio)); // no apparatus yet
        $this->actingAs($this->userIn($studio, 'owner'));
        $base = "/{$studio->slug}/admin/sessions/{$session->id}";

        $this->patch($base, ['capacity' => 20, 'room_id' => $room->id])->assertSessionHasErrors(['capacity' => 'Main studio holds at most 16 people.']);
        $this->patch($base, ['capacity' => 4, 'room_id' => $room->id, 'waitlist_capacity' => 5, 'apparatus' => [
            $a['Silks']->id => ['quantity' => 1, 'max_per_item' => 1], $a['Lyra']->id => ['quantity' => 2],
        ]])->assertSessionHas('success');
        $session->refresh();
        $this->assertSame([$room->id, 5, 3], [$session->room_id, $session->waitlist_capacity, $session->spotsLeft()]);
        $this->assertNull($waiting->fresh()->apparatus_id, 'an earlier booking keeps its seat without apparatus');

        $onSilks = $this->book($session, $this->member($studio), $a['Silks']->id);
        $this->post("/{$studio->slug}/admin/bookings/{$onSilks->id}/apparatus", ['apparatus_id' => $a['Lyra']->id])
            ->assertSessionHas('success', "{$onSilks->attendee->first_name} is now on Lyra 1.");
        $this->assertSame([$a['Lyra']->id, 1], [$onSilks->fresh()->apparatus_id, $onSilks->fresh()->apparatus_item]);
        $this->book($session, $this->member($studio), $a['Silks']->id);
        $this->post("/{$studio->slug}/admin/bookings/{$onSilks->id}/apparatus", ['apparatus_id' => $a['Silks']->id])
            ->assertSessionHas('error', 'Every spot on Silks is taken.');

        // Staff add someone on an apparatus.
        $kid = $this->member($studio);
        $this->post("{$base}/bookings", ['attendee_id' => $kid->id, 'apparatus_id' => $a['Lyra']->id])
            ->assertSessionHas('success', "{$kid->name} added on Lyra 2.");

        // Another class in the room at the same time, using the same silks.
        $other = $this->classSession($this->program($studio, ['title' => 'Silks Lab']), ['room_id' => $room->id, 'starts_at' => $session->starts_at->copy()->addMinutes(30)]);
        $other->apparatus()->sync([$a['Silks']->id => ['quantity' => 2, 'max_per_item' => 1]]);
        $this->get($base)->assertOk()
            ->assertSee('Main studio is also booked for')->assertSee('Silks Lab')->assertSee('Silks: 3 in use, 2 rigged')
            ->assertSee('Lyra 2')->assertSee('Silks: 1/1');
    }

    public function test_members_choose_their_apparatus(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [2, 1], 'Lyra' => [3, 1]]);
        $session = $this->sessionUsing($studio, $room, [$a['Silks']->id => [1, 1], $a['Lyra']->id => [3, 1]]);
        $this->book($session, $this->member($studio), $a['Silks']->id);
        $attendee = $this->member($studio);
        $this->actingAs($attendee->user);

        $this->get("/{$studio->slug}")->assertOk()->assertSee('3 spots left');
        $this->get("/{$studio->slug}/book/{$session->id}")->assertOk()
            ->assertSee('No preference')->assertSee('Full. You’ll join the waitlist for one.')->assertSee('3 spots left');

        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $attendee->id, 'apparatus_id' => $a['Lyra']->id])
            ->assertRedirect("/{$studio->slug}/account")
            ->assertSessionHas('success', "{$attendee->first_name} is booked for {$session->program->title} (Lyra 1)!");
        $this->get("/{$studio->slug}/account")->assertOk()->assertSee('Lyra 1')->assertSee('Main studio');

        // Signing a waiver first comes back to the same choice.
        $this->waiver($studio);
        $sibling = $this->attendee($studio, $attendee->user);
        $this->post("/{$studio->slug}/book/{$session->id}", ['attendee_id' => $sibling->id, 'apparatus_id' => $a['Lyra']->id])
            ->assertRedirectContains(urlencode("/{$studio->slug}/book/{$session->id}?attendee={$sibling->id}&apparatus={$a['Lyra']->id}"));
    }

    public function test_the_schedule_counts_apparatus_spots_without_a_query_per_class(): void
    {
        $studio = $this->studio();
        [$room, $a] = $this->rig($studio, ['Silks' => [4, 1]]);
        $add = fn (int $days) => $this->book(
            $this->sessionUsing($studio, $room, [$a['Silks']->id => [4, 1]], ['starts_at' => now()->addDays($days)->setTime(17, 0)]),
            $this->member($studio),
        );
        $queries = function () use ($studio) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get("/{$studio->slug}")->assertOk();

            return count(DB::getQueryLog());
        };

        $add(1);
        $add(2);
        $few = $queries();
        $add(3);
        $add(4);
        $add(5);
        $this->assertSame($few, $queries());
    }
}
