<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\Program;
use App\Models\Series;
use App\Models\SeriesEnrollment;
use App\Models\Studio;
use App\Services\Bookings;
use App\Services\Orders;
use App\Services\SeriesSignups;
use App\Services\SessionGenerator;
use App\Support\BookingException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Decision 47: series sign-up, paid in full, booking every class; late joining, make-ups, drop-ins. */
class SeriesTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $sessions = [];

    /** @var list<array<string, mixed>> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');

            return match (true) {
                $path === 'customers' => Http::response(['id' => 'cus_'.Str::random(8)]),
                $path === 'checkout/sessions' => $this->createSession($request),
                str_ends_with($path, '/expire') => $this->expireSession(Str::between($path, 'checkout/sessions/', '/expire')),
                isset($this->sessions[Str::after($path, 'checkout/sessions/')]) => Http::response($this->sessions[Str::after($path, 'checkout/sessions/')]),
                default => Http::response(['error' => ['message' => "No such object: {$path}"]], 404),
            };
        }]);
    }

    private function createSession(Request $request)
    {
        $id = 'cs_'.(count($this->created) + 1);
        $this->created[] = $request->data();
        $this->sessions[$id] = ['id' => $id, 'object' => 'checkout.session', 'mode' => 'payment', 'status' => 'open', 'payment_status' => 'unpaid',
            'url' => "https://checkout.stripe.com/c/pay/{$id}", 'expires_at' => (int) $request['expires_at'], 'metadata' => $request['metadata'], 'payment_intent' => null];

        return Http::response($this->sessions[$id]);
    }

    private function expireSession(string $id)
    {
        $this->sessions[$id]['status'] = 'expired';

        return Http::response($this->sessions[$id]);
    }

    private function pay(string $id): void
    {
        $order = Order::findOrFail($this->sessions[$id]['metadata']['loft_order_id']);
        $this->sessions[$id] = ['status' => 'complete', 'payment_status' => 'paid', 'payment_intent' => "pi_{$id}",
            'amount_subtotal' => $order->subtotal_cents, 'amount_total' => $order->total_cents, 'currency' => 'usd', 'total_details' => ['amount_discount' => 0, 'amount_tax' => 0]] + $this->sessions[$id];
    }

    private function webhook(string $type, string $id, string $eventId): TestResponse
    {
        return $this->postWebhook(['id' => $eventId, 'type' => $type, 'account' => 'acct_A', 'data' => ['object' => ['id' => $id, 'object' => 'checkout.session']]]);
    }

    private function signups(): SeriesSignups
    {
        return app(SeriesSignups::class);
    }

    /** A studio taking payments, and four weekly Silks classes from three days out, in a $200 series. */
    private function series(array $attributes = [], int $capacity = 3, ?Studio $studio = null): Series
    {
        $studio ??= $this->studio(['name' => 'Skyline', 'stripe_account_id' => 'acct_A', 'stripe_charges_enabled' => true]);
        $program = $this->program($studio, ['title' => 'Silks Level 1', 'capacity' => $capacity]);
        $first = now()->addDays(3)->setTime(18, 0);
        foreach (range(0, 3) as $week) {
            $this->classSession($program, ['starts_at' => $first->copy()->addWeeks($week), 'capacity' => $capacity]);
        }
        $series = Series::create($attributes + [
            'studio_id' => $studio->id,
            'program_id' => $program->id,
            'name' => 'Silks Level 1',
            'starts_on' => $studio->local($first)->toDateString(),
            'ends_on' => $studio->local($first->copy()->addWeeks(3))->toDateString(),
            'price_cents' => 20000,
        ]);
        $series->attachSessions();

        return $series;
    }

    private function member(Studio $studio, string $first = 'Leo'): array
    {
        $user = $this->userIn($studio, 'member', ['first_name' => 'Priya']);

        return [$user, $this->attendee($studio, $user, ['first_name' => $first, 'last_name' => 'Shah'])];
    }

    public function test_the_price_and_who_can_join_change_as_the_series_runs(): void
    {
        $series = $this->series(['join_until_class' => 2]);
        $quote = $this->signups()->quote($series);
        $this->assertSame([4, 4, 20000, 3, true], [$quote['total'], $quote['left'], $quote['price_cents'], $quote['spots_left'], $quote['open']]);
        $this->assertSame(4, $series->sessions()->count());

        // After the first class: three left, at three quarters of the price.
        $this->travelTo($series->sessions()->first()->starts_at->copy()->addMinutes(5));
        $quote = $this->signups()->quote($series);
        $this->assertSame([3, 15000, true], [$quote['left'], $quote['price_cents'], $quote['open']]);

        // Once the second class has started, it's too late.
        $this->travelTo($series->sessions()->skip(1)->first()->starts_at->copy()->addMinutes(5));
        $this->assertSame([false, 'It’s too late to join this series. Ask the studio about the next one.'], [$this->signups()->quote($series)['open'], $this->signups()->quote($series)['closed']]);
    }

    public function test_signing_up_holds_a_spot_and_paying_books_every_class_with_one_email(): void
    {
        $series = $this->series();
        [$user, $leo] = $this->member($series->studio);

        $url = $this->signups()->checkout($series, $leo, $user, 'https://loft.test/done', 'https://loft.test/back?order=__ORDER__');
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_1', $url);
        $enrollment = SeriesEnrollment::sole();
        $this->assertSame(['pending', 4, 20000, 'web'], [$enrollment->status, $enrollment->classes, $enrollment->price_cents, $enrollment->source]);
        $this->assertSame(2, $this->signups()->quote($series)['spots_left']); // held while paying
        $this->assertSame('series', $this->created[0]['metadata']['loft_kind']);
        $this->assertSame(20000, (int) $this->created[0]['line_items'][0]['price_data']['unit_amount']);
        $this->assertStringStartsWith('Silks Level 1 (', $this->created[0]['line_items'][0]['price_data']['product_data']['name']);
        $this->assertStringEndsWith(') · Leo', $this->created[0]['line_items'][0]['price_data']['product_data']['name']);
        $this->assertSame(0, Booking::count());

        $this->pay('cs_1');
        $this->webhook('checkout.session.completed', 'cs_1', 'evt_1')->assertOk();
        $this->webhook('checkout.session.completed', 'cs_1', 'evt_1_retry')->assertOk(); // once only

        $order = Order::sole();
        $this->assertSame(['paid', 20000, 'series'], [$order->status, $order->total_cents, $order->items()->sole()->kind]);
        $this->assertSame(['active', 4], [$enrollment->fresh()->status, $enrollment->fresh()->classes]);
        $bookings = Booking::orderBy('id')->get();
        $this->assertSame([4, ['booked'], ['series'], [null], [$enrollment->id]], [$bookings->count(), $bookings->pluck('status')->unique()->values()->all(),
            $bookings->pluck('source')->unique()->values()->all(), $bookings->pluck('entitlement_id')->unique()->values()->all(), $bookings->pluck('series_enrollment_id')->unique()->values()->all()]);
        $this->assertSame(2, $this->signups()->quote($series)['spots_left']);
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'series_signup' && $m->headline === 'You’re signed up for Silks Level 1!' && $m->details['Classes'] === '4');
        Mail::assertNotQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'booked');
        $this->assertSame('Signed up for Silks Level 1 (4 classes)', DomainEvent::where('type', 'series.enrolled')->sole()->label());

        // Signing up again is refused.
        $this->expectExceptionObject(new BookingException('already_enrolled', 'Leo is already signed up for this series.'));
        $this->signups()->checkout($series, $leo, $user, 'https://loft.test/done', 'https://loft.test/back');
    }

    public function test_a_checkout_that_expires_lets_the_spot_go(): void
    {
        $series = $this->series(capacity: 1);
        [$user, $leo] = $this->member($series->studio);
        [$other, $maya] = $this->member($series->studio, 'Maya');

        $this->signups()->checkout($series, $leo, $user, 'https://loft.test/done', 'https://loft.test/back');
        $this->assertSame([0, false, 'This series is full.'], [$this->signups()->quote($series)['spots_left'], $this->signups()->quote($series)['open'], $this->signups()->quote($series)['closed']]);
        try {
            $this->signups()->checkout($series, $maya, $other, 'https://loft.test/done', 'https://loft.test/back');
            $this->fail('The held spot should keep the series full.');
        } catch (BookingException $e) {
            $this->assertSame('full', $e->reason);
        }

        $this->expireSession('cs_1');
        $this->webhook('checkout.session.expired', 'cs_1', 'evt_2')->assertOk();
        $this->assertSame(['released', 'expired'], [SeriesEnrollment::where('attendee_id', $leo->id)->sole()->status, Order::sole()->status]);
        $this->assertSame(1, $this->signups()->quote($series)['spots_left']);
        $this->assertNotNull($this->signups()->checkout($series, $maya, $other, 'https://loft.test/done', 'https://loft.test/back'));
    }

    public function test_a_free_series_signs_up_at_once_and_questions_and_waivers_come_first(): void
    {
        $series = $this->series(['price_cents' => 0]);
        $studio = $series->studio;
        [$user, $leo] = $this->member($studio);
        $leo->update(['photo_consent' => null]);

        try {
            $this->signups()->checkout($series, $leo, $user, 'https://loft.test/done', 'https://loft.test/back');
            $this->fail('The photo question comes first.');
        } catch (BookingException $e) {
            $this->assertSame(['questions', ['photos']], [$e->reason, $e->context['questions']]);
        }
        $leo->update(['photo_consent' => 'public']);
        $waiver = $this->waiver($studio, ['title' => 'Release']);
        try {
            $this->signups()->checkout($series, $leo, $user, 'https://loft.test/done', 'https://loft.test/back');
            $this->fail('The waiver comes first.');
        } catch (BookingException $e) {
            $this->assertSame(['waiver', 'Leo needs to sign “Release” before signing up.'], [$e->reason, $e->getMessage()]);
        }
        $waiver->update(['is_active' => false]);
        $studio->unsetRelation('activeWaivers');

        $this->assertNull($this->signups()->checkout(Series::find($series->id), Attendee::find($leo->id), $user, 'https://loft.test/done', 'https://loft.test/back'));
        $this->assertSame(['active', 4], [SeriesEnrollment::sole()->status, Booking::where('status', 'booked')->count()]);
        $this->assertSame(0, Order::count());
    }

    public function test_series_classes_are_for_the_people_in_it_unless_the_series_takes_drop_ins(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        $walkIn = $this->userIn($studio);
        $this->credits($studio, $walkIn);
        $sam = $this->attendee($studio, $walkIn, ['first_name' => 'Sam']);
        $class = $series->sessions()->first();

        try {
            app(Bookings::class)->book($class, $sam, $walkIn->id);
            $this->fail('A series class should be closed to drop-ins.');
        } catch (BookingException $e) {
            $this->assertSame(['series_only', 'This class is part of the Silks Level 1 series. Sign up for the series to join it.'], [$e->reason, $e->getMessage()]);
        }
        $this->assertSame('booked', app(Bookings::class)->book($class, $sam, $walkIn->id, 'admin')->status); // staff can still add someone

        $series->update(['allow_dropins' => true]);
        $this->assertSame('booked', app(Bookings::class)->book($series->sessions()->skip(1)->first()->fresh(), $sam, $walkIn->id)->status);
    }

    public function test_cancelling_in_time_earns_a_make_up_that_books_another_series_class(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        [$user, $leo] = $this->member($studio);
        $this->signups()->checkout($series->fresh(), $leo, $user, 'https://loft.test/done', 'https://loft.test/back');
        $this->pay('cs_1');
        $this->webhook('checkout.session.completed', 'cs_1', 'evt_1')->assertOk();
        $bookings = Booking::orderBy('id')->get();

        $result = app(Bookings::class)->cancel($bookings[1], $user->id);
        $this->assertSame(['status' => 'cancelled', 'credit_refunded' => false, 'makeup' => true], $result);
        $makeup = Entitlement::sole();
        $this->assertSame([true, 1, 'Make-up class (Silks Level 1)'], [$makeup->isMakeup(), $makeup->credits_remaining, $makeup->name()]);
        $this->assertSame($series->makeupsExpireAt()->toDateTimeString(), $makeup->expires_at->toDateTimeString());
        $this->assertSame('Got a make-up class (for a series class cancelled in time)', DomainEvent::where('type', 'entitlement.granted')->sole()->label());

        // A late cancel earns nothing.
        $this->travelTo($bookings[2]->classSession->starts_at->copy()->subHours(2));
        $this->assertSame(['status' => 'late_cancelled', 'credit_refunded' => false, 'makeup' => false], app(Bookings::class)->cancel($bookings[2]->fresh(), $user->id));
        $this->assertSame(1, Entitlement::count());

        // The make-up books a class in another series that takes no drop-ins.
        $other = $this->series(['name' => 'Silks Level 1 (Thursdays)'], studio: $studio);
        $booking = app(Bookings::class)->book($other->sessions()->orderBy('starts_at')->get()->last(), $leo, $user->id);
        $this->assertSame(['booked', $makeup->id, 0], [$booking->status, $booking->entitlement_id, $makeup->fresh()->credits_remaining]);
    }

    public function test_staff_add_someone_and_voiding_the_sale_takes_them_out(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        $owner = $this->userIn($studio, 'owner');
        [$user, $leo] = $this->member($studio);
        $leo->update(['photo_consent' => null]); // staff don't need the questions answered

        $enrollment = $this->signups()->addByStaff($series, $leo, $owner, 'cash', 15000, 'Paid at the desk');
        $this->assertSame(['active', 'staff', 15000, 4], [$enrollment->status, $enrollment->source, $enrollment->price_cents, Booking::where('status', 'booked')->count()]);
        $order = Order::sole();
        $this->assertSame(['in_person', 'cash', 15000, 'paid'], [$order->channel, $order->method, $order->total_cents, $order->status]);
        app(Bookings::class)->cancel(Booking::orderBy('id')->first(), $user->id); // earns a make-up

        app(Orders::class)->void($order, $owner);
        $this->assertSame('withdrawn', $enrollment->fresh()->status);
        $this->assertSame(0, Booking::where('status', 'booked')->count());
        $this->assertSame('cancelled', Entitlement::sole()->status);
        $this->assertSame('Taken out of Silks Level 1', DomainEvent::where('type', 'series.withdrawn')->sole()->label());
        Mail::assertQueued(StudioMessage::class, fn (StudioMessage $m) => $m->kind === 'series_withdrawn' && $m->details['Classes cancelled'] === '3');
        $this->assertSame(3, $this->signups()->quote($series)['spots_left']);
    }

    public function test_classes_added_to_the_program_later_join_its_series(): void
    {
        $series = $this->series();
        $program = Program::find($series->program_id);
        $extra = $this->classSession($program, ['starts_at' => $series->sessions()->first()->starts_at->copy()->addDay()]);
        $this->assertNull($extra->fresh()->series_id);
        $user = $this->userIn($series->studio);
        app(Bookings::class)->book($extra, $this->attendee($series->studio, $user), $user->id, 'admin', skipEntitlement: true); // kept by the sync
        app(SessionGenerator::class)->sync($program); // saving the program re-attaches
        $this->assertSame($series->id, $extra->fresh()->series_id);
    }

    public function test_staff_set_up_a_series_see_who_is_in_it_and_add_someone(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        $owner = $this->userIn($studio, 'owner');
        [$user, $leo] = $this->member($studio);
        $program = Program::find($series->program_id);
        $this->actingAs($owner);

        $this->get("/{$studio->slug}/admin/series")->assertOk()->assertSee('Silks Level 1')->assertSee('3 spots left')->assertSee('Open');
        $this->get("/{$studio->slug}/admin/series/create?program={$program->id}")->assertOk()->assertSee('Price for the whole series');
        $first = $series->sessions()->first();
        $day = fn (int $weeks) => $studio->local($first->starts_at->copy()->addWeeks($weeks))->toDateString();
        $form = ['program_id' => $program->id, 'name' => 'Silks Level 1 · Late start', 'starts_on' => $day(1), 'ends_on' => $day(3), 'price' => '150',
            'join_until_class' => '1', 'makeups' => '1', 'allow_dropins' => '0', 'signups_open' => '1'];
        $this->post("/{$studio->slug}/admin/series", $form)->assertSessionHasErrors(['starts_on' => 'These dates overlap Silks Level 1 ('.$series->datesLabel().'). A class can only be in one series.']);

        // Shorten the first series to its first class, then the new one takes the other three.
        $this->put("/{$studio->slug}/admin/series/{$series->id}", ['name' => 'Silks Level 1', 'ends_on' => $day(0), 'program_id' => $program->id, 'starts_on' => $day(0),
            'price' => '50', 'join_until_class' => '2', 'makeups' => '1', 'signups_open' => '1'])->assertSessionHas('success', 'Saved. The series has 1 class.');
        $this->post("/{$studio->slug}/admin/series", $form)->assertRedirect()->assertSessionHas('success', 'Series created with 3 classes.');
        $created = Series::where('name', 'Silks Level 1 · Late start')->sole();
        $this->assertSame([15000, 1, true, false], [$created->price_cents, $created->join_until_class, $created->makeups, $created->allow_dropins]);
        $this->assertSame([3, 1], [$created->sessions()->count(), $series->sessions()->count()]);

        $this->get("/{$studio->slug}/admin/series/{$created->id}?q=Leo")->assertOk()->assertSee('Leo Shah')->assertSee('Add to series');
        $this->post("/{$studio->slug}/admin/series/{$created->id}/people", ['attendee_id' => $leo->id, 'method' => 'comp'])
            ->assertRedirect()->assertSessionHas('success', 'Leo is signed up and booked into 3 classes.');
        $this->get("/{$studio->slug}/admin/series/{$created->id}")->assertOk()->assertSee('Leo Shah')->assertSee('Free')->assertSee('Added by staff')->assertSee('Take out');
        $this->get("/{$studio->slug}/admin/sessions/{$created->sessions()->first()->id}")->assertOk()->assertSee('Series');
        $this->get("/{$studio->slug}/admin/programs/{$program->id}/edit")->assertOk()->assertSee('Silks Level 1 · Late start');

        $enrollment = SeriesEnrollment::sole();
        $this->post("/{$studio->slug}/admin/series/{$created->id}/people/{$enrollment->id}/withdraw")->assertRedirect()
            ->assertSessionHas('success', 'Leo is out of the series, and 3 classes were cancelled. To give money back, refund their payment from the member’s page or Sales.');
        $this->actingAs($user)->get("/{$studio->slug}/admin/series")->assertForbidden();
    }

    public function test_members_find_a_series_sign_up_and_pay(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        [$user, $leo] = $this->member($studio);
        $class = $series->sessions()->first();

        $this->get("/{$studio->slug}")->assertOk()->assertSee('Series open for sign-up')->assertSee('Series: Silks Level 1')->assertSee('Part of a series');
        $this->get("/{$studio->slug}/series/{$series->id}")->assertOk()->assertSee('$200.00')->assertSee('for all 4 classes')->assertSee('3 spots left')
            ->assertSee('Sign in or create an account to sign up');
        $this->get("/{$studio->slug}/classes/{$series->program_id}")->assertOk()->assertSee('Sign up for a series');

        $this->actingAs($user);
        $this->get("/{$studio->slug}/book/{$class->id}")->assertOk()->assertSee('This class is part of the Silks Level 1 series')->assertSee('See the series');
        $this->post("/{$studio->slug}/book/{$class->id}", ['attendee_id' => $leo->id])->assertRedirect("/{$studio->slug}/series/{$series->id}");
        $this->get("/{$studio->slug}/series/{$series->id}")->assertOk()->assertSee('Leo Shah')->assertSee('Sign up and pay $200.00');

        $this->post("/{$studio->slug}/series/{$series->id}", ['attendee_id' => $leo->id])->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');
        $this->assertStringContainsString("/series/{$series->id}/done?session_id={CHECKOUT_SESSION_ID}", $this->created[0]['success_url']);
        $this->get("/{$studio->slug}/series/{$series->id}")->assertSee('Paying now');

        $this->pay('cs_1');
        $this->get("/{$studio->slug}/series/{$series->id}/done?session_id=cs_1")->assertRedirect("/{$studio->slug}/account")
            ->assertSessionHas('success', 'Thanks! You’re signed up for Silks Level 1, and booked into every class. We’ve emailed you the details.');
        $this->get("/{$studio->slug}/account")->assertOk()->assertSee('Series');
        $this->get("/{$studio->slug}/series/{$series->id}")->assertSee('Already signed up');

        // Cancelling one in time says so, and the make-up shows on the series page.
        $booking = Booking::where('class_session_id', $class->id)->sole();
        $this->post("/{$studio->slug}/bookings/{$booking->id}/cancel")
            ->assertSessionHas('success', 'Cancelled. You’ve got a make-up class to use within two weeks of the series ending: book any class with it.');
        $this->get("/{$studio->slug}/series/{$series->id}")->assertSee('Book with your make-up');
        $this->get("/{$studio->slug}/account/billing")->assertOk()->assertSee('Make-up class (Silks Level 1)');
    }

    public function test_coming_back_from_checkout_without_paying_lets_the_spot_go(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        [$user, $leo] = $this->member($studio);
        $this->actingAs($user)->post("/{$studio->slug}/series/{$series->id}", ['attendee_id' => $leo->id]);
        $order = Order::sole();
        $this->assertStringContainsString("/series/{$series->id}?cancelled={$order->id}", $this->created[0]['cancel_url']);

        $this->get("/{$studio->slug}/series/{$series->id}?cancelled={$order->id}")->assertOk()->assertDontSee('Paying now');
        $this->assertSame(['expired', 'released'], [$order->fresh()->status, SeriesEnrollment::sole()->status]);
    }

    public function test_the_app_lists_series_and_signs_up_through_checkout(): void
    {
        $series = $this->series();
        $studio = $series->studio;
        [$user, $leo] = $this->member($studio);
        $token = $user->createToken('Test phone', ['member'])->plainTextToken;
        $api = function (string $method, string $uri, array $data = []) use ($token) {
            $this->app['auth']->forgetGuards();
            $response = $this->json($method, '/api/v1/'.$uri, $data, ['Authorization' => "Bearer {$token}"]);
            $this->app['auth']->forgetGuards();

            return $response;
        };
        $base = "studios/{$studio->slug}";

        $api('GET', "{$base}/series")->assertOk()->assertJsonPath('data.0.name', 'Silks Level 1')->assertJsonPath('data.0.price_now_cents', 20000)
            ->assertJsonPath('data.0.spots_left', 3)->assertJsonPath('data.0.open', true);
        $class = $series->sessions()->first();
        $api('GET', "{$base}/sessions/{$class->id}")->assertJsonPath('data.series.id', $series->id)->assertJsonPath('data.series.drop_ins', false)
            ->assertJsonPath('booking.closed_code', 'series_only');
        $api('POST', "{$base}/sessions/{$class->id}/bookings", ['attendee_id' => $leo->id])->assertStatus(422)
            ->assertJsonPath('error.code', 'series_only')->assertJsonPath('error.series_id', $series->id);

        $signup = $api('POST', "{$base}/series/{$series->id}/signups", ['attendee_id' => $leo->id])->assertCreated()
            ->assertJsonPath('data.signed_up', false)->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/c/pay/cs_1')->assertJsonPath('data.checkout_id', 'cs_1');
        $this->assertStringContainsString('/checkout/return', $signup->json('data.return_url'));
        $api('GET', "{$base}/series/{$series->id}")->assertJsonPath('data.mine.0.status', 'pending')->assertJsonCount(4, 'data.classes');

        $this->pay('cs_1');
        $api('GET', "{$base}/checkouts/cs_1")->assertOk()->assertJsonPath('data.kind', 'series')->assertJsonPath('data.fulfilled', true);
        $api('GET', "{$base}/series/{$series->id}")->assertJsonPath('data.mine.0.status', 'active');
        $api('POST', "{$base}/series/{$series->id}/signups", ['attendee_id' => $leo->id])->assertStatus(409)->assertJsonPath('error.code', 'already_enrolled');
    }
}
