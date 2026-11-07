<?php

namespace Tests\Feature;

use App\Services\Bookings;
use Tests\TestCase;

/** One studio can never see or change another's data, and roles gate the admin. */
class TenancyTest extends TestCase
{
    public function test_child_ids_from_another_studio_are_not_found(): void
    {
        $a = $this->studio();
        $b = $this->studio();
        $admin = $this->userIn($a, 'admin');
        $programB = $this->program($b);
        $sessionB = $this->classSession($programB);
        $planB = $this->plan($b);
        $memberB = $this->userIn($b);
        $this->actingAs($admin);

        $this->get("/{$a->slug}/admin/programs/{$programB->id}/edit")->assertNotFound();
        $this->get("/{$a->slug}/admin/sessions/{$sessionB->id}")->assertNotFound();
        $this->post("/{$a->slug}/admin/sessions/{$sessionB->id}/cancel")->assertNotFound();
        $this->get("/{$a->slug}/admin/plans/{$planB->id}/edit")->assertNotFound();
        $this->get("/{$a->slug}/admin/members/{$memberB->id}")->assertNotFound();
        $this->get("/{$a->slug}/classes/{$programB->id}")->assertNotFound();
        $this->assertSame('scheduled', $sessionB->fresh()->status);
    }

    public function test_staff_of_one_studio_cannot_open_another_studios_admin(): void
    {
        $a = $this->studio();
        $b = $this->studio();
        $this->actingAs($this->userIn($a, 'owner'));

        $this->get("/{$b->slug}/admin")->assertForbidden();
        $this->get("/{$b->slug}/admin/checkin")->assertForbidden();
    }

    public function test_roles(): void
    {
        $studio = $this->studio();
        $member = $this->userIn($studio, 'member');
        $instructor = $this->userIn($studio, 'instructor');

        $this->actingAs($member)->get("/{$studio->slug}/admin/checkin")->assertForbidden();
        $this->actingAs($instructor)->get("/{$studio->slug}/admin")->assertRedirect("/{$studio->slug}/admin/checkin");
        $this->actingAs($instructor)->get("/{$studio->slug}/admin/checkin")->assertOk();
        $this->actingAs($instructor)->get("/{$studio->slug}/admin/programs")->assertForbidden();
        $this->actingAs($instructor)->get("/{$studio->slug}/admin/settings")->assertForbidden();
    }

    public function test_members_cannot_touch_each_others_family_or_bookings(): void
    {
        $studio = $this->studio();
        $mine = $this->userIn($studio);
        $theirs = $this->userIn($studio);
        $this->credits($studio, $theirs);
        $theirKid = $this->attendee($studio, $theirs);
        $booking = app(Bookings::class)->book($this->classSession($this->program($studio)), $theirKid, $theirs->id);
        $this->actingAs($mine);

        $this->get("/{$studio->slug}/account/family/{$theirKid->id}")->assertNotFound();
        $this->get("/{$studio->slug}/waiver/{$theirKid->id}")->assertNotFound();
        $this->post("/{$studio->slug}/bookings/{$booking->id}/cancel")->assertSessionHas('error');
        $this->assertSame('booked', $booking->fresh()->status);
        $this->post("/{$studio->slug}/book/{$booking->class_session_id}", ['attendee_id' => $theirKid->id])->assertNotFound();
    }
}
