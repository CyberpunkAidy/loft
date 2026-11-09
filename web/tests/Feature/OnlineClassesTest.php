<?php

namespace Tests\Feature;

use App\Listeners\StudioEmails;
use App\Models\AccessDevice;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\DomainEvent;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\CheckInCodes;
use App\Services\PlanCheckout;
use App\Services\Stripe\StripeWebhooks;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Live virtual classes (roadmap step 10): online and hybrid classes, online spots, and joining through the meeting link. */
class OnlineClassesTest extends TestCase
{
    private const LINK = 'https://zoom.us/j/123456789?pwd=abc';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** An API call; whoever was signed in to the website stays signed in. */
    private function api(string $method, string $uri, ?string $token, array $data = []): TestResponse
    {
        $web = $this->app['auth']->guard('web')->user();
        $this->app['auth']->forgetGuards();
        $response = $this->json($method, '/api/v1/'.$uri, $data, $token ? ['Authorization' => "Bearer {$token}"] : []);
        $this->app['auth']->forgetGuards();
        $this->app['auth']->setDefaultDriver('web');
        if ($web) {
            $this->actingAs($web);
        }

        return $response;
    }

    /** A member with a membership, and a class they can book. */
    private function setUpClass(Studio $studio, array $session): array
    {
        $member = $this->userIn($studio, 'member', ['first_name' => 'Pat']);
        $this->membership($studio, $member);
        $class = $this->classSession($this->program($studio, ['title' => 'Flow']), $session);

        return [$member, $class];
    }

    public function test_hybrid_classes_keep_online_spots_apart_from_the_studio(): void
    {
        $studio = $this->studio();
        [$member, $class] = $this->setUpClass($studio, ['capacity' => 1, 'delivery' => 'hybrid', 'online_capacity' => 2, 'meeting_url' => self::LINK]);
        [$ana, $ben, $cy, $di] = collect(['Ana', 'Ben', 'Cy', 'Di'])->map(fn ($n) => $this->attendee($studio, $member, ['first_name' => $n]))->all();
        $this->actingAs($member);
        $book = "/{$studio->slug}/book/{$class->id}";

        $this->get($book)->assertOk()->assertSee('In the studio and online')->assertSee('Where')
            ->assertSee('1 spot left')->assertSee('2 spots left. The link appears 15 minutes before class.', false);
        $this->get("/{$studio->slug}")->assertOk()->assertSee('In studio or online')->assertSee('1 spot left');

        // The studio's one seat, then its waitlist; online spots are separate and never waitlisted.
        $this->post($book, ['attendee_id' => $ana->id, 'online' => '0'])->assertRedirect()->assertSessionHas('success', 'Ana is booked for Flow!');
        $this->post($book, ['attendee_id' => $ben->id, 'online' => '1'])->assertSessionHas('success', 'Ben is booked online for Flow! The link to join appears under Upcoming 15 minutes before class.');
        $this->post($book, ['attendee_id' => $cy->id])->assertSessionHas('success', 'That class is full, so Cy is #1 on the waitlist. We\'ll email you if a spot opens.');
        $this->post($book, ['attendee_id' => $di->id, 'online' => '1'])->assertSessionHas('success');
        $this->assertSame([['Ana', 'booked', false], ['Ben', 'booked', true], ['Cy', 'waitlisted', false], ['Di', 'booked', true]],
            Booking::with('attendee')->orderBy('id')->get()->map(fn ($b) => [$b->attendee->first_name, $b->status, $b->online_spot])->all());
        $this->assertSame([1, 2, 1], [$class->bookedCount(), $class->onlineCount(), $class->waitlistCount()]);
        $this->assertTrue(DomainEvent::where('type', 'booking.booked')->where('payload->online', true)->exists());

        // Every online spot taken.
        $eve = $this->attendee($studio, $member, ['first_name' => 'Eve']);
        $this->post($book, ['attendee_id' => $eve->id, 'online' => '1'])->assertSessionHas('error', 'Every online spot is taken. Book a spot in the studio, or try another class.');
        $this->get("/{$studio->slug}")->assertSee('Full · 1 waiting');

        // Cancelling an online spot frees an online spot, not a studio seat: Cy stays on the waitlist.
        $this->post("/{$studio->slug}/bookings/".Booking::where('attendee_id', $ben->id)->value('id').'/cancel')->assertRedirect();
        $this->assertSame('waitlisted', Booking::where('attendee_id', $cy->id)->value('status'));
        $this->get("/{$studio->slug}")->assertSee('Studio full · online open');
        $class->update(['waitlist_capacity' => 1]); // the studio's seat and waitlist both full: online is still open
        $this->get($book)->assertOk()->assertSee('name="online" value="1" checked', false)->assertSee('name="online" value="0"  disabled', false)->assertDontSee('This class and its waitlist are full.');
        $this->api('GET', "studios/{$studio->slug}/sessions/{$class->id}", $member->createToken('Phone', ['member'])->plainTextToken)->assertJsonPath('booking.closed', false);
        $this->post($book, ['attendee_id' => $eve->id, 'online' => '1'])->assertSessionHas('success');

        // The studio seat freeing up moves Cy in.
        $this->post("/{$studio->slug}/bookings/".Booking::where('attendee_id', $ana->id)->value('id').'/cancel')->assertRedirect();
        $this->assertSame('booked', Booking::where('attendee_id', $cy->id)->value('status'));

        // Online spots are only a hybrid class's: elsewhere "online" is ignored.
        $other = $this->classSession($class->program, ['capacity' => 5, 'starts_at' => now()->addDays(4)]);
        $this->post("/{$studio->slug}/book/{$other->id}", ['attendee_id' => $ana->id, 'online' => '1'])->assertSessionHas('success', 'Ana is booked for Flow!');
        $this->assertFalse(Booking::where('class_session_id', $other->id)->sole()->online_spot);

        // The roster shows who's online.
        $this->actingAs($this->userIn($studio, 'owner'));
        $this->get("/{$studio->slug}/admin/sessions/{$class->id}")->assertOk()
            ->assertSee('In the studio and online')->assertSee('1/1 booked · 2/2 online · 0/1 waitlisted')->assertSee('<span class="badge badge-info">Online</span>', false);
    }

    public function test_members_join_an_online_class_through_its_link(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $start = now()->addHours(2)->startOfMinute();
        [$member, $class] = $this->setUpClass($studio, ['delivery' => 'online', 'capacity' => 30, 'meeting_url' => self::LINK, 'starts_at' => $start, 'room_id' => $studio->rooms()->create(['name' => 'Big room', 'max_capacity' => 30])->id]);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $this->actingAs($member);

        $this->get("/{$studio->slug}")->assertOk()->assertSee('<span class="badge badge-info">Online</span>', false)->assertDontSee('Big room');
        $this->get("/{$studio->slug}/book/{$class->id}")->assertOk()->assertSee('This class is online.')->assertDontSee('>Where<', false);
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $maya->id])
            ->assertSessionHas('success', 'Maya is booked online for Flow! The link to join appears under Upcoming 15 minutes before class.');
        $booking = Booking::sole();
        $this->assertFalse($booking->online_spot, 'everyone in an online class is online: no separate spots');
        $this->assertTrue($booking->attendsOnline());
        $this->assertSame('Online. The link to join is in your account from 15 minutes before class.', StudioEmails::details($booking->fresh())['Where']);

        // Too early: no link, and a time to come back.
        $opens = $studio->local($start->copy()->subMinutes(15))->format('g:i A');
        $this->get("/{$studio->slug}/account")->assertOk()->assertSee('<strong>Online</strong>', false)
            ->assertSee("The link to join appears here at {$opens}.")->assertDontSee('Join online')->assertDontSee(self::LINK);
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertRedirect("/{$studio->slug}/account")
            ->assertSessionHas('warning', 'The link opens '.$studio->local($start->copy()->subMinutes(15))->format('g:i A, M j').', 15 minutes before class.');
        $this->assertSame('booked', $booking->fresh()->status);

        // From 15 minutes before: joining hands over the link and checks them in.
        $this->travelTo($start->copy()->subMinutes(10));
        $this->get("/{$studio->slug}/account")->assertSee('Join online')->assertSee(route('studio.bookings.join', ['studio' => $studio, 'booking' => $booking]))->assertDontSee(self::LINK);
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertRedirect(self::LINK);
        $this->assertSame('attended', $booking->fresh()->status);
        $this->assertSame(1, DomainEvent::where('type', 'booking.attended')->count());
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertRedirect(self::LINK); // rejoining after a dropped call
        $this->assertSame(1, DomainEvent::where('type', 'booking.attended')->count());
        $this->travelTo($start->copy()->addMinutes(30));
        $this->get("/{$studio->slug}/account")->assertSee('Checked in')->assertSee('Join online');

        // After class: gone from Upcoming, and the link is closed.
        $this->travelTo($start->copy()->addMinutes(61));
        $this->get("/{$studio->slug}/account")->assertDontSee('Join online');
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertSessionHas('warning', 'That class has ended.');

        // Another member's booking isn't theirs to join.
        $this->travelTo($start->copy()->subMinutes(5));
        $this->actingAs($this->userIn($studio));
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertNotFound();
    }

    public function test_joining_needs_an_online_booking_and_a_link(): void
    {
        $studio = $this->studio();
        $start = now()->addMinutes(5)->startOfMinute();
        [$member, $class] = $this->setUpClass($studio, ['delivery' => 'online', 'starts_at' => $start]);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $this->actingAs($member);
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $maya->id])->assertSessionHas('success');
        $booking = Booking::sole();

        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertSessionHas('warning', 'The studio hasn’t added the link for this class yet. Please contact them.');
        $class->update(['meeting_url' => self::LINK]);
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertRedirect(self::LINK);

        // An in-person booking has nothing to join, and a cancelled one is closed.
        $inPerson = $this->classSession($class->program, ['starts_at' => $start, 'meeting_url' => self::LINK]);
        $this->post("/{$studio->slug}/book/{$inPerson->id}", ['attendee_id' => $this->attendee($studio, $member)->id])->assertSessionHas('success');
        $this->get("/{$studio->slug}/bookings/".$inPerson->bookings()->value('id').'/join')->assertSessionHas('warning', 'That booking is for the studio, not online.');
        $this->assertSame('booked', $inPerson->bookings()->value('status'));
        $class->update(['status' => 'cancelled']);
        $this->get("/{$studio->slug}/bookings/{$booking->id}/join")->assertSessionHas('warning', 'That booking isn’t active, so there’s nothing to join.');
    }

    public function test_the_door_does_not_check_in_people_booked_online(): void
    {
        $studio = $this->studio();
        $start = now()->addMinutes(20)->startOfMinute();
        [$member, $class] = $this->setUpClass($studio, ['delivery' => 'hybrid', 'starts_at' => $start, 'meeting_url' => self::LINK]);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $this->actingAs($member);
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $maya->id, 'online' => '1'])->assertSessionHas('success');

        [, $token] = AccessDevice::issue($studio, 'Front door');
        $code = app(CheckInCodes::class)->current($maya)['code'];
        $this->api('POST', 'devices/check-ins', $token, ['credential' => ['kind' => 'qr', 'value' => $code]])
            ->assertOk()->assertJsonPath('data.result', 'denied')->assertJsonPath('data.reason', 'no_booking');

        // Nor anyone in an online class.
        $leo = $this->attendee($studio, $member, ['first_name' => 'Leo']);
        $online = $this->classSession($class->program, ['delivery' => 'online', 'starts_at' => $start, 'meeting_url' => self::LINK]);
        $this->post("/{$studio->slug}/book/{$online->id}", ['attendee_id' => $leo->id])->assertSessionHas('success');
        $this->api('POST', 'devices/check-ins', $token, ['credential' => ['kind' => 'qr', 'value' => app(CheckInCodes::class)->current($leo)['code']]])
            ->assertJsonPath('data.reason', 'no_booking');

        // In the studio, they're checked in as usual.
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $leo->id, 'online' => '0'])->assertSessionHas('success');
        $this->api('POST', 'devices/check-ins', $token, ['credential' => ['kind' => 'qr', 'value' => app(CheckInCodes::class)->current($leo)['code']]])
            ->assertJsonPath('data.result', 'granted');
    }

    public function test_staff_set_where_a_class_happens(): void
    {
        $studio = $this->studio();
        $studio->seasons()->create(['name' => 'Now', 'starts_on' => now()->toDateString(), 'ends_on' => now()->addWeeks(3)->subDay()->toDateString(), 'is_current' => true]);
        $this->actingAs($this->userIn($studio, 'owner'));
        $fields = ['title' => 'Stretch', 'type' => 'class', 'weekday' => now()->addDay()->dayOfWeek, 'start_time' => '18:00', 'duration_min' => 45, 'capacity' => 8, 'is_published' => '1'];

        $this->get("/{$studio->slug}/admin/programs/create")->assertOk()->assertSee('Meeting link')->assertSee('Online spots');
        $this->post("/{$studio->slug}/admin/programs", $fields + ['delivery' => 'online', 'online_capacity' => 5, 'meeting_url' => 'not a link'])->assertSessionHasErrors('meeting_url');
        $this->post("/{$studio->slug}/admin/programs", $fields + ['delivery' => 'online', 'online_capacity' => 5, 'meeting_url' => self::LINK])
            ->assertSessionHas('success', 'Saved. 3 sessions created.');
        $program = $studio->programs()->sole();
        $this->assertSame(['online', null, self::LINK], [$program->delivery, $program->online_capacity, $program->meeting_url], 'online spots are only for hybrid classes');
        $this->assertSame([['online', null, self::LINK]], $program->sessions()->get()->map(fn ($s) => [$s->delivery, $s->online_capacity, $s->meeting_url])->unique()->values()->all());

        // Changing the program and applying it to upcoming sessions.
        $this->put("/{$studio->slug}/admin/programs/{$program->id}", $fields + ['delivery' => 'hybrid', 'online_capacity' => 12, 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'apply_upcoming' => '1'])
            ->assertSessionHas('success');
        $this->assertSame([['hybrid', 12, 'https://meet.google.com/abc-defg-hij']], $program->sessions()->get()->map(fn ($s) => [$s->delivery, $s->online_capacity, $s->meeting_url])->unique()->values()->all());

        // One session on its own.
        $session = $program->sessions()->orderBy('starts_at')->first();
        $this->get("/{$studio->slug}/admin/sessions/{$session->id}")->assertOk()->assertSee('https://meet.google.com/abc-defg-hij');
        $member = $this->userIn($studio);
        $this->membership($studio, $member);
        app(Bookings::class)->book($session, $this->attendee($studio, $member), $member->id, online: true);

        // Someone's booked online, so it has to stay hybrid (here and when applied from the program).
        $this->patch("/{$studio->slug}/admin/sessions/{$session->id}", ['capacity' => 8, 'delivery' => 'in_person'])
            ->assertSessionHasErrors(['delivery' => '1 person is booked online. Keep this class in the studio and online, or remove them first.']);
        $this->put("/{$studio->slug}/admin/programs/{$program->id}", $fields + ['delivery' => 'in_person', 'apply_upcoming' => '1'])
            ->assertSessionHasErrors(['delivery' => '1 upcoming class has people booked online. Keep it in the studio and online, or don’t apply the change to upcoming sessions.']);
        $this->assertSame('hybrid', $program->fresh()->delivery);
        $this->patch("/{$studio->slug}/admin/sessions/{$session->id}", ['capacity' => 8, 'delivery' => 'hybrid', 'online_capacity' => 3, 'meeting_url' => self::LINK])
            ->assertSessionHas('success', 'Session updated.');
        $this->assertSame(['hybrid', 3, self::LINK], [$session->fresh()->delivery, $session->fresh()->online_capacity, $session->fresh()->meeting_url]);

        // Without anyone online, it can move back into the studio.
        $other = $program->sessions()->orderByDesc('starts_at')->first();
        $this->patch("/{$studio->slug}/admin/sessions/{$other->id}", ['capacity' => 8, 'delivery' => 'in_person', 'online_capacity' => 3])->assertSessionHas('success');
        $this->assertSame(['in_person', null], [$other->fresh()->delivery, $other->fresh()->online_capacity]);
        $this->put("/{$studio->slug}/admin/programs/{$program->id}", $fields + ['delivery' => 'in_person'])->assertSessionHas('success');
        $this->assertSame('in_person', $program->fresh()->delivery);
    }

    public function test_the_api_books_online_spots_and_hands_out_the_link(): void
    {
        $studio = $this->studio();
        $start = now()->addHour()->startOfMinute();
        [$member, $class] = $this->setUpClass($studio, ['capacity' => 1, 'delivery' => 'hybrid', 'online_capacity' => 5, 'meeting_url' => self::LINK, 'starts_at' => $start]);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $token = $member->createToken('Phone', ['member'])->plainTextToken;

        $this->api('GET', "studios/{$studio->slug}/sessions/{$class->id}", null)->assertOk()
            ->assertJsonPath('data.delivery', 'hybrid')->assertJsonPath('data.online_capacity', 5)->assertJsonPath('data.online_spots_left', 5);
        $booking = $this->api('POST', "studios/{$studio->slug}/sessions/{$class->id}/bookings", $token, ['attendee_id' => $maya->id, 'online' => true])
            ->assertCreated()->assertJsonPath('data.online', true)->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.join_opens_at', $start->copy()->subMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.session.delivery', 'hybrid')
            ->assertJsonPath('message', 'Maya is booked online for Flow! Join from 15 minutes before class.')
            ->assertJsonMissing(['meeting_url' => self::LINK])->json('data.id');
        $this->api('GET', "studios/{$studio->slug}/sessions/{$class->id}", null)->assertJsonPath('data.online_spots_left', 4)->assertJsonPath('data.spots_left', 1);

        $this->api('POST', "studios/{$studio->slug}/bookings/{$booking}/join", $token)->assertStatus(422)
            ->assertJsonPath('error.code', 'not_open_yet')->assertJsonPath('error.opens_at', $start->copy()->subMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z'));
        $this->travelTo($start->copy()->subMinutes(15));
        $this->api('POST', "studios/{$studio->slug}/bookings/{$booking}/join", $token)->assertOk()->assertExactJson(['data' => ['url' => self::LINK]]);
        $this->assertSame('attended', Booking::find($booking)->status);

        // Someone else's token can't join it.
        $other = $this->userIn($studio)->createToken('Phone', ['member'])->plainTextToken;
        $this->api('POST', "studios/{$studio->slug}/bookings/{$booking}/join", $other)->assertNotFound()->assertJsonPath('error.code', 'not_found');
    }

    public function test_buying_a_plan_finishes_an_online_booking(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $member = $this->userIn($studio);
        $plan = $this->plan($studio, ['kind' => 'pack', 'credits' => 5, 'stripe_price_id' => 'price_1']);
        $class = $this->classSession($this->program($studio), ['capacity' => 1, 'delivery' => 'hybrid', 'online_capacity' => 3]);
        $maya = $this->attendee($studio, $member, ['first_name' => 'Maya']);
        $this->actingAs($member);

        // No plan yet: the choice of online carries through to the plan page and checkout.
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $maya->id, 'online' => '1'])->assertOk()
            ->assertSee('name="online" value="1"', false);
        $this->fakeStripe([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
        ]);
        $this->post("/{$studio->slug}/checkout/{$plan->id}", ['session_id' => $class->id, 'attendee_id' => $maya->id, 'online' => '1'])
            ->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['metadata']['online'] == 1 && $r['metadata']['session_id'] == $class->id
            && str_ends_with($r['cancel_url'], "/book/{$class->id}?online=1"));
        $inPerson = $this->classSession($class->program);
        $this->assertSame(['session_id' => $inPerson->id, 'attendee_id' => $maya->id], app(PlanCheckout::class)->bookingContext($studio, $member, $inPerson->id, $maya->id, null, true),
            'online spots are only for hybrid classes');

        app(StripeWebhooks::class)->fulfillCheckout($studio, [
            'id' => 'cs_1', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1', 'currency' => 'usd', 'amount_total' => 5000,
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $member->id, 'session_id' => $class->id, 'attendee_id' => $maya->id, 'online' => '1'],
        ]);
        $booking = Booking::sole();
        $this->assertSame(['booked', true], [$booking->status, $booking->online_spot]);
        $this->assertSame([0, 1], [$class->bookedCount(), $class->onlineCount()]);

        // If the class stopped being hybrid before the payment cleared, it's a seat in the studio.
        $inPerson->update(['starts_at' => now()->addDays(5), 'ends_at' => now()->addDays(5)->addHour()]);
        app(StripeWebhooks::class)->fulfillCheckout($studio, [
            'id' => 'cs_2', 'mode' => 'payment', 'status' => 'complete', 'payment_status' => 'paid', 'customer' => 'cus_1', 'currency' => 'usd', 'amount_total' => 5000,
            'metadata' => ['loft_studio_id' => $studio->id, 'loft_plan_id' => $plan->id, 'loft_user_id' => $member->id, 'session_id' => $inPerson->id, 'attendee_id' => $maya->id, 'online' => '1'],
        ]);
        $this->assertSame(['booked', false], [$inPerson->bookings()->sole()->status, $inPerson->bookings()->sole()->online_spot]);
    }

    public function test_classes_are_in_the_studio_unless_staff_say_otherwise(): void
    {
        $studio = $this->studio();
        $class = $this->classSession($this->program($studio));
        $this->assertSame(['in_person', 'In the studio', false, false, null], [$class->fresh()->delivery, $class->deliveryLabel(), $class->isOnline(), $class->isHybrid(), $class->onlineSpotsLeft()]);
        $this->assertNull(ClassSession::find($class->id)->online_capacity);
    }
}
