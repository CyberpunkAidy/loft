<?php

namespace Tests\Feature;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Booking;
use App\Models\DomainEvent;
use App\Models\Entitlement;
use App\Models\Import;
use App\Models\MemberSnapshot;
use App\Models\Order;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Services\Bookings;
use App\Services\ChurnScores;
use App\Services\Imports\Dates;
use App\Services\Imports\Imports;
use App\Services\MemberInvitations;
use App\Support\CsvReader;
use App\Support\ImportException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Data import from another system (decision 37): members and families, credits and memberships, invitations, carrying memberships on. */
class ImportTest extends TestCase
{
    private const CLIENTS = <<<'CSV'
    First Name,Last Name,Email,Mobile Phone,Birth Date,Emergency Contact,Emergency Contact Phone,Medical Notes,Email Opt-In
    Maya,Lee,priya@example.com,,03/14/2017,Arjun Lee,303-555-0102,Peanut allergy,Yes
    Priya,Lee,priya@example.com,303-555-0101,06/02/1988,Arjun Lee,303-555-0102,,Yes
    Leo,Lee,priya@example.com,,9/1/2019,,,,
    Sam,Ortiz,SAM@EXAMPLE.COM,,,,,,No
    ,,nobody@example.com,,,,,,
    Nina,Park,not-an-email,,,,,,
    Olga,Other,olga@example.com,,,,,,Yes
    Mo,Member,mo@example.com,,13/45/1990,,,,
    Rachel,Smith,rae@example.com,,,,,,Yes
    CSV;

    /** @var array<string, array<string, mixed>> path => object served by the fake Stripe API */
    private array $objects = [];

    /** @var list<array<string, mixed>> Checkout Sessions created */
    private array $checkouts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->fakeStripe(['api.stripe.com/*' => function (Request $request) {
            $path = Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1/');
            if ($request->method() === 'POST' && $path === 'customers') {
                return Http::response(['id' => 'cus_member']);
            }
            if ($request->method() === 'POST' && $path === 'checkout/sessions') {
                $this->checkouts[] = $request->data();

                return Http::response(['id' => 'cs_new', 'url' => 'https://checkout.stripe.com/c/cs_new']);
            }
            if ($request->method() === 'POST') {
                return Http::response(['id' => basename($path)]);
            }

            return isset($this->objects[$path]) ? Http::response($this->objects[$path]) : Http::response(['error' => ['message' => 'No such object']], 404);
        }]);
    }

    private function upload(Studio $studio, string $kind, string $csv, string $name = 'clients.csv'): TestResponse
    {
        return $this->post("/{$studio->slug}/admin/import", ['kind' => $kind, "file_{$kind}" => UploadedFile::fake()->createWithContent($name, $csv)]);
    }

    /** Upload a file and import it as it was matched. */
    private function import(Studio $studio, string $kind, string $csv): Import
    {
        $this->upload($studio, $kind, $csv)->assertRedirect();
        $import = Import::latest('id')->first();
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run")->assertRedirect("/{$studio->slug}/admin/import/{$import->id}");

        return $import->refresh();
    }

    private function adminOf(Studio $studio): User
    {
        $admin = $this->userIn($studio, 'admin');
        $this->actingAs($admin);

        return $admin;
    }

    public function test_a_client_list_is_previewed_then_imported_without_emailing_anyone(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $this->adminOf($studio);
        $olga = User::factory()->create(['email' => 'olga@example.com', 'first_name' => 'Olga', 'last_name' => 'Original', 'phone' => '111']);
        $this->userIn($this->studio(), 'member', [])->forceFill([])->save();
        $this->studio()->studioUsers()->create(['user_id' => $olga->id, 'role' => 'member']); // she uses Loft at another studio
        $mo = $this->userIn($studio, 'member', ['email' => 'mo@example.com', 'first_name' => 'Mo', 'last_name' => 'Member']);
        $rae = $this->userIn($studio, 'member', ['email' => 'rae@example.com']);
        $this->attendee($studio, $rae, ['first_name' => 'Rae', 'last_name' => 'Smith', 'is_self' => true]);
        StudioUser::where('user_id', $rae->id)->update(['email_unsubscribed_at' => now()->subWeek(), 'email_marketing_consent_at' => now()->subYear()]);
        $users = User::count();

        $response = $this->upload($studio, 'people', self::CLIENTS);
        $import = Import::sole();
        $response->assertRedirect("/{$studio->slug}/admin/import/{$import->id}");
        $this->assertSame(['first_name' => 0, 'last_name' => 1, 'email' => 2, 'phone' => 3, 'dob' => 4, 'medical_notes' => 7, 'emergency_name' => 5, 'emergency_phone' => 6, 'marketing' => 8], $import->mapping);
        $this->assertSame('mdy', $import->options['date_order']);

        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertOk()
            ->assertSee('<strong>2</strong> new accounts, <strong>1</strong> person who already uses Loft at another studio, <strong>2</strong> already members here.', false)
            ->assertSee('Maya Lee (')
            ->assertSee('Peanut allergy')
            ->assertSee('2 rows skipped')
            ->assertSee('No name')
            ->assertSee('“not-an-email” isn’t an email address')
            ->assertSee('Birthday “13/45/1990” wasn’t understood')
            ->assertSee('Import 5 accounts');
        $this->assertSame($users, User::count(), 'previewing changes nothing');
        $preview = collect(app(Imports::class)->plan($import)->items)->keyBy('email');
        $this->assertSame(['Olga', 'Original', 'existing'], [$preview['olga@example.com']['first_name'], $preview['olga@example.com']['last_name'], $preview['olga@example.com']['status']]);

        $this->post("/{$studio->slug}/admin/import/{$import->id}/run")->assertRedirect();
        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertNull($import->csv, 'the file isn’t kept');
        $this->assertSame(2, $import->tally('created_accounts'));
        $this->assertSame(3, $import->tally('added_members'));
        $this->assertSame(7, $import->tally('added_people'));
        $this->assertFalse(Attendee::where('first_name', 'Rachel')->sole()->is_self, 'Rae has herself on her account already');
        $this->assertFalse(StudioUser::where('user_id', $rae->id)->sole()->allowsMarketingEmail(), 'an unsubscribe stands');
        $this->assertCount(2, $import->report['skipped']);

        $priya = User::where('email', 'priya@example.com')->sole();
        $this->assertSame(['Priya', 'Lee', '303-555-0101', $import->id], [$priya->first_name, $priya->last_name, $priya->phone, $priya->import_id]);
        $family = Attendee::where('user_id', $priya->id)->get()->keyBy('first_name');
        $this->assertSame(['Maya', 'Priya', 'Leo'], $family->keys()->all());
        $this->assertSame(['Maya' => false, 'Priya' => true, 'Leo' => false], $family->map->is_self->all(), 'the adult holds the account, though listed second');
        $this->assertSame(['Maya' => '2017-03-14', 'Priya' => '1988-06-02', 'Leo' => '2019-09-01'], $family->map(fn ($a) => $a->dob?->toDateString())->all());
        $this->assertSame('Peanut allergy', $family['Maya']->medical_notes);
        $this->assertSame('Arjun Lee, 303-555-0102', $family['Maya']->emergency_contact);
        $membership = StudioUser::where('studio_id', $studio->id)->where('user_id', $priya->id)->sole();
        $this->assertSame(['member', $import->id], [$membership->role, $membership->import_id]);
        $this->assertTrue($membership->allowsMarketingEmail());
        $this->assertSame('import', $membership->email_marketing_consent_source);

        $sam = User::where('email', 'sam@example.com')->sole();
        $this->assertFalse(StudioUser::where('studio_id', $studio->id)->where('user_id', $sam->id)->sole()->allowsMarketingEmail());

        $olga->refresh(); // joins this studio as she is
        $this->assertSame(['Original', '111', null], [$olga->last_name, $olga->phone, $olga->import_id]);
        $this->assertTrue($olga->hasRoleIn($studio, 'member'));
        $this->assertTrue(Attendee::where('studio_id', $studio->id)->where('user_id', $olga->id)->where('is_self', true)->exists());

        $this->assertSame(1, Attendee::where('studio_id', $studio->id)->where('user_id', $mo->id)->count(), 'an existing member gets a self attendee for the row');
        $this->assertNull(Attendee::where('user_id', $mo->id)->sole()->dob, 'the unreadable birthday is left blank');
        $this->assertSame(3, DomainEvent::where('type', 'member.joined')->where('payload->source', 'import')->count());
        Mail::assertNothingQueued();
        Mail::assertNothingSent();

        // The same file again adds nobody.
        $again = $this->import($studio, 'people', self::CLIENTS);
        $this->assertSame([0, 0, 0], [$again->tally('created_accounts'), $again->tally('added_members'), $again->tally('added_people')]);
        $this->assertSame(9, collect($again->report['skipped'])->count());
    }

    public function test_children_can_have_a_parent_email_column_and_full_names(): void
    {
        $studio = $this->studio(['currency' => 'gbp']);
        $this->adminOf($studio);
        $csv = "Student Name,Email,Parent Email,Parent Name,DOB,Allergies,Newsletter\n"
            ."Cam Brooks,cam@example.com,dana@example.com,,,,\n"
            ."\"Brooks, Ava\",,dana@example.com,Dana Brooks,14/03/2017,,yes\n"
            ."Ben Brooks,,dana@example.com,Dana Brooks,02/09/2019,Asthma,\n"
            ."Kit Solo,,solo@example.com,,05/05/2016,,\n"
            ."Fay Future,,solo@example.com,,05/05/2099,,\n";

        $import = $this->import($studio, 'people', $csv);
        $this->assertSame('dmy', $import->options['date_order']);
        $dana = User::where('email', 'dana@example.com')->sole();
        $this->assertSame(['Dana', 'Brooks'], [$dana->first_name, $dana->last_name]);
        $kids = Attendee::where('user_id', $dana->id)->orderBy('id')->get();
        $this->assertSame(['Cam', 'Ava', 'Ben'], $kids->pluck('first_name')->all(), 'Cam has an email of their own, but Dana books for them');
        $this->assertNull(User::where('email', 'cam@example.com')->first());
        $this->assertSame([null, '2017-03-14', '2019-09-02'], $kids->map(fn ($a) => $a->dob?->toDateString())->all());
        $this->assertFalse(StudioUser::where('user_id', $dana->id)->sole()->allowsMarketingEmail(), 'a child’s row doesn’t opt the parent in');
        $this->assertNull(Attendee::where('first_name', 'Fay')->sole()->dob, 'a birthday in the future is left blank');
        $this->assertFalse($kids->contains('is_self', true), 'the parent doesn’t take classes');
        $this->assertSame('Asthma', $kids[2]->medical_notes);

        $solo = User::where('email', 'solo@example.com')->sole();
        $this->assertSame(['Solo', 'family'], [$solo->first_name, $solo->last_name]);
        $this->assertStringContainsString('named “Solo family”', collect($import->report['warnings'])->pluck('message')->join(' '));
        $this->assertStringContainsString('Birthday “05/05/2099” wasn’t understood', collect($import->report['warnings'])->pluck('message')->join(' '));
    }

    public function test_files_from_spreadsheets_are_read_as_they_come(): void
    {
        $studio = $this->studio();
        $this->adminOf($studio);
        $csv = "\xEF\xBB\xBFPrénom;Nom;E-mail;Notes médicales\nZoë;Bérénice;zoe@example.com;\"Knee injury,\nno inversions\"\n";

        $import = $this->import($studio, 'people', $csv);
        $this->assertSame('done', $import->status);
        $zoe = User::where('email', 'zoe@example.com')->sole();
        $this->assertSame(['Zoë', 'Bérénice'], [$zoe->first_name, $zoe->last_name], 'French column names, accents and all');
        $this->assertSame("Knee injury,\nno inversions", Attendee::where('user_id', $zoe->id)->sole()->medical_notes);

        $latin1 = mb_convert_encoding("First Name,Last Name,Email\nRenée,Côté,renee@example.com\n", 'Windows-1252', 'UTF-8');
        $this->import($studio, 'people', $latin1);
        $this->assertSame('Côté', User::where('email', 'renee@example.com')->sole()->last_name);

        $this->upload($studio, 'people', '')->assertSessionHasErrors(['file_people' => 'That file is empty.']);
        $this->upload($studio, 'people', "PK\x03\x04binary")->assertSessionHasErrors('file_people');
        $this->upload($studio, 'people', "First Name,Email\n")->assertSessionHasErrors('file_people');
        $this->post("/{$studio->slug}/admin/import", ['kind' => 'plans'])->assertSessionHasErrors(['file_plans' => 'Choose the CSV file to import.']);

        $this->expectException(ImportException::class);
        CsvReader::parse("a\n".str_repeat("x\n", CsvReader::MAX_ROWS + 1));
    }

    public function test_staff_can_change_how_columns_are_matched(): void
    {
        $studio = $this->studio();
        $this->adminOf($studio);
        $this->upload($studio, 'people', "Surname,Given,Contact,Born\nLee,Priya,priya@example.com,2/3/1988\n")->assertRedirect();
        $import = Import::sole();
        $this->assertArrayNotHasKey('email', $import->mapping);
        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertOk()->assertSee('Choose the column with people’s email addresses.');
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run")->assertSessionHas('error', 'Choose the column with people’s email addresses.');
        $this->assertSame('draft', $import->refresh()->status);

        $this->put("/{$studio->slug}/admin/import/{$import->id}", [
            'mapping' => ['first_name' => '1', 'last_name' => '0', 'email' => '2', 'dob' => '3', 'phone' => '99'],
            'date_order' => 'dmy',
        ])->assertRedirect();
        $import->refresh();
        $this->assertSame(['first_name' => 1, 'last_name' => 0, 'email' => 2, 'dob' => 3], $import->mapping, 'a column that isn’t there is dropped');
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run");
        $this->assertSame('1988-03-02', Attendee::sole()->dob->toDateString());

        $this->put("/{$studio->slug}/admin/import/{$import->id}", ['mapping' => ['email' => '0']])->assertRedirect();
        $this->assertSame(['first_name' => 1, 'last_name' => 0, 'email' => 2, 'dob' => 3], $import->refresh()->mapping, 'nothing changes once imported');
    }

    public function test_undo_removes_what_nobody_has_used(): void
    {
        $studio = $this->studio();
        $admin = $this->adminOf($studio);
        $olga = User::factory()->create(['email' => 'olga@example.com']);
        $import = $this->import($studio, 'people', self::CLIENTS);
        $priya = User::where('email', 'priya@example.com')->sole();
        $maya = Attendee::where('first_name', 'Maya')->sole();
        $program = $this->program($studio);
        Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($program)->id, 'attendee_id' => $maya->id, 'user_id' => $priya->id, 'status' => 'booked', 'booked_at' => now()]);

        $this->actingAs($admin)->post("/{$studio->slug}/admin/import/{$import->id}/undo")
            ->assertSessionHas('success', 'Import undone. 2 records were kept because they’re in use (booked, accepted an invitation, or a plan was used).');

        $this->assertSame('undone', $import->refresh()->status);
        $this->assertSame(['Maya'], Attendee::where('user_id', $priya->id)->pluck('first_name')->all());
        $this->assertTrue($priya->fresh()->hasRoleIn($studio, 'member'), 'kept: Maya is booked');
        $this->assertNull(User::where('email', 'sam@example.com')->first(), 'an account the import created and nobody used is gone');
        $this->assertNotNull($olga->fresh(), 'an account from before stays');
        $this->assertFalse($olga->fresh()->hasRoleIn($studio, 'member'));
        $this->post("/{$studio->slug}/admin/import/{$import->id}/undo")->assertSessionHas('error', 'This import can no longer be undone.');
        $this->assertSame(['removed' => 0, 'kept' => 0], app(Imports::class)->undo($import));

        $later = $this->import($studio, 'people', "First Name,Last Name,Email\nAl,Late,al@example.com\n");
        $this->travel(Import::UNDO_DAYS + 1)->days();
        $this->assertFalse($later->refresh()->canUndo());
    }

    public function test_imported_people_have_been_coming_already(): void
    {
        $studio = $this->studio(['timezone' => 'UTC']);
        $this->adminOf($studio);
        $this->import($studio, 'people', "First Name,Last Name,Email\nPat,Regular,pat@example.com\nJo,Gone,jo@example.com\nNew,Person,new@example.com\n");
        $pat = User::where('email', 'pat@example.com')->sole();
        $booking = Booking::create(['studio_id' => $studio->id, 'class_session_id' => $this->classSession($this->program($studio), ['starts_at' => now()->subHour()])->id,
            'attendee_id' => Attendee::where('user_id', $pat->id)->sole()->id, 'user_id' => $pat->id, 'status' => 'booked', 'booked_at' => now()]);
        app(Bookings::class)->mark($booking, 'attended');
        $this->assertFalse(DomainEvent::where('type', 'booking.attended')->sole()->payload['first_visit'], 'not their first class at the studio');

        // Three weeks on: an imported former client with nothing is lapsed, not "never visited";
        // one still holding credits who hasn't come is at risk; a new joiner who never came is too.
        $jo = User::where('email', 'jo@example.com')->sole();
        $new = User::where('email', 'new@example.com')->sole();
        StudioUser::where('user_id', $new->id)->update(['import_id' => null]);
        $this->credits($studio, $jo, 3);
        $this->credits($studio, $new, 3);
        $this->travel(21)->days();
        $score = function (User $user) use ($studio) {
            $snapshot = new MemberSnapshot(['studio_id' => $studio->id, 'user_id' => $user->id, 'days_since_visit' => null, 'visits_28d' => 0, 'visits_prev_28d' => 0,
                'misses_28d' => 0, 'upcoming_bookings' => 0, 'has_membership' => false, 'credits_remaining' => 0, 'failed_payments_90d' => 0,
                'attendees' => 1, 'tenure_days' => 21, 'imported' => StudioUser::where('user_id', $user->id)->value('import_id') !== null]);

            return $snapshot;
        };
        $rules = app(ChurnScores::class);
        $this->assertSame('lapsed', $rules->score($score($jo))['level']);
        $holding = $score($jo)->forceFill(['credits_remaining' => 3]);
        $this->assertContains('not_since_import', array_column($rules->score($holding)['reasons'], 'code'));
        $this->assertNotContains('never_visited', array_column($rules->score($holding)['reasons'], 'code'));
        $this->assertContains('never_visited', array_column($rules->score($score($new)->forceFill(['credits_remaining' => 3]))['reasons'], 'code'));
    }

    public function test_only_the_studios_admins_can_import(): void
    {
        $studio = $this->studio();
        $this->actingAs($this->userIn($studio, 'instructor'));
        $this->get("/{$studio->slug}/admin/import")->assertForbidden();

        $other = $this->studio();
        $this->adminOf($other);
        $this->upload($other, 'people', "First Name,Last Name,Email\nA,B,a@example.com\n");
        $import = Import::sole();
        $this->actingAs($this->userIn($studio, 'admin'));
        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertNotFound();
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run")->assertNotFound();
    }

    public function test_drafts_are_cleared_after_a_week(): void
    {
        $studio = $this->studio();
        $this->adminOf($studio);
        $this->upload($studio, 'people', "First Name,Last Name,Email\nA,B,a@example.com\n");
        $this->get("/{$studio->slug}/admin/import/template/people")->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->travel(6)->days();
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame(1, Import::count());
        $this->travel(2)->days();
        $this->artisan('loft:housekeeping')->assertSuccessful();
        $this->assertSame(0, Import::count());
    }

    public function test_dates_in_either_order(): void
    {
        $this->assertSame('2017-03-14', Dates::parse('3/14/2017')->toDateString());
        $this->assertSame('2017-03-14', Dates::parse('14.03.2017', 'dmy')->toDateString());
        $this->assertSame('2017-03-04', Dates::parse('2017-03-04 00:00:00')->toDateString());
        $this->assertSame('1985-04-03', Dates::parse('4/3/85')->toDateString());
        $this->assertSame('2017-03-04', Dates::parse('Mar 4, 2017')->toDateString());
        $this->assertNull(Dates::parse('2/30/2017'));
        $this->assertSame(['Name', 'Email'], CsvReader::parse("\xEF\xBB\xBFName,Email\nA,a@example.com\n")['headers'], 'no byte-order mark on the first column');
        $this->assertNull(Dates::parse('soon'));
        $this->assertSame('dmy', Dates::detectOrder(['1/2/2020', '25/12/2020']));
        $this->assertSame('mdy', Dates::detectOrder(['1/2/2020', '12/25/2020'], 'dmy'));
        $this->assertSame('dmy', Dates::detectOrder(['1/2/2020'], 'dmy'));
    }

    /* ------------------------------------------------------------------ Credits and memberships */

    private function membersAndPlans(Studio $studio): array
    {
        $this->import($studio, 'people', "First Name,Last Name,Email\nPriya,Lee,priya@example.com\nSam,Ortiz,sam@example.com\n");

        return [
            $this->plan($studio, ['name' => '10-class pack', 'kind' => 'pack', 'credits' => 10, 'credit_validity_days' => 90]),
            $this->plan($studio, ['name' => 'Unlimited', 'kind' => 'subscription', 'billing_interval' => 'month', 'price_cents' => 15000, 'credits' => null, 'stripe_price_id' => 'price_unl']),
        ];
    }

    public function test_credits_and_memberships_come_over_on_matching_plans(): void
    {
        $studio = $this->studio(['timezone' => 'America/Denver']);
        $this->adminOf($studio);
        [$pack, $unlimited] = $this->membersAndPlans($studio);
        $this->studio()->studioUsers()->create(['user_id' => User::factory()->create(['email' => 'elsewhere@example.com'])->id, 'role' => 'member']);
        $csv = "Client Email,Client Name,Pricing Option,Remaining,Expiration Date\n"
            ."priya@example.com,Priya Lee,10 Class Pack,6,3/31/2027\n"
            ."sam@example.com,Sam Ortiz,Unlimited Monthly,,12/31/2026\n"
            ."priya@example.com,Priya Lee,10 Class Pack,4,1/1/2020\n"
            ."priya@example.com,Priya Lee,10 Class Pack,0,3/31/2027\n"
            ."stranger@example.com,Who,10 Class Pack,5,3/31/2027\n"
            ."elsewhere@example.com,Else,10 Class Pack,5,3/31/2027\n"
            ."sam@example.com,Sam Ortiz,Workshop,1,3/31/2027\n"
            ."sam@example.com,Sam Ortiz,Unlimited Monthly,,\n"
            ."priya@example.com,Priya Lee,10 Class Pack,3,\n";

        $this->upload($studio, 'plans', $csv)->assertRedirect();
        $import = Import::where('kind', 'plans')->sole();
        $this->assertSame(['email' => 0, 'full_name' => 1, 'plan' => 2, 'credits_left' => 3, 'expires' => 4], $import->mapping);
        $this->assertSame(['10classpack' => $pack->id, 'unlimitedmonthly' => 0, 'workshop' => 0], $import->options['plans'], 'matched by name');
        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertOk()->assertSee('Unlimited Monthly')->assertSee('“Unlimited Monthly” isn’t matched to one of your plans');

        $this->put("/{$studio->slug}/admin/import/{$import->id}", [
            'mapping' => $import->mapping,
            'date_order' => 'mdy',
            'plans' => ['10classpack' => $pack->id, 'unlimitedmonthly' => $unlimited->id, 'workshop' => 0],
        ]);
        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertOk()
            ->assertSee('<strong>2</strong> packs and <strong>1</strong> membership to add.', false)
            ->assertSee('Expired on Jan 1, 2020')
            ->assertSee('No classes left')
            ->assertSee('No member with stranger@example.com. Import your members first')
            ->assertSee('“Workshop” isn’t matched to one of your plans')
            ->assertSee('No paid-through date');
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run");
        $import->refresh();
        $this->assertSame([2, 1, 6], [$import->tally('added_packs'), $import->tally('added_memberships'), $import->tally('skipped')]);
        $this->assertSame('No member with elsewhere@example.com. Import your members first', collect($import->report['skipped'])->firstWhere('name', 'Else')['reason']);

        $this->upload($studio, 'plans', "Plan,Remaining\n10 Class Pack,5\n");
        $this->get('/'.$studio->slug.'/admin/import/'.Import::latest('id')->value('id'))->assertSee('Choose the column with each member’s email.');

        $priya = User::where('email', 'priya@example.com')->sole();
        $packs = Entitlement::where('user_id', $priya->id)->orderBy('id')->get();
        $this->assertSame([6, 3], $packs->pluck('credits_remaining')->all());
        $this->assertSame([10, 10], $packs->pluck('credits_total')->all());
        $this->assertSame('2027-03-31 23:59:59', $studio->local($packs[0]->expires_at)->format('Y-m-d H:i:s'), 'the end of the day, studio time');
        $this->assertTrue($packs[1]->expires_at->between(now()->addDays(89), now()->addDays(91)), 'no expiry given: the plan’s validity');
        $membership = Entitlement::where('user_id', User::where('email', 'sam@example.com')->value('id'))->sole();
        $this->assertSame(['subscription', 'active', null, $import->id], [$membership->kind, $membership->status, $membership->stripe_subscription_id, $membership->import_id]);
        $this->assertSame('2026-12-31', $studio->local($membership->current_period_end)->toDateString());
        $this->assertSame(3, DomainEvent::where('type', 'entitlement.granted')->where('payload->source', 'import')->count());
        $this->assertSame(0, Order::count(), 'nothing was sold');

        // Again: nothing twice.
        $again = $this->import($studio, 'plans', $csv);
        $this->assertSame(0, $again->tally('added_packs'));

        // Undo keeps a pack that's been used.
        $booking = app(Bookings::class)->book($this->classSession($this->program($studio, [], [])), $this->attendee($studio, $priya), $priya->id, preferred: $packs[0]);
        $this->assertSame($packs[0]->id, $booking->entitlement_id);
        $this->post("/{$studio->slug}/admin/import/{$import->id}/undo")->assertSessionHas('success');
        $this->assertSame([$packs[0]->id], Entitlement::pluck('id')->all());
    }

    /* ------------------------------------------------------------------ Invitations */

    public function test_members_are_invited_when_staff_choose(): void
    {
        $studio = $this->studio(['name' => 'Skyline']);
        $admin = $this->adminOf($studio);
        $olga = User::factory()->create(['email' => 'olga@example.com']);
        $this->userIn($studio, 'member', ['email' => 'already@example.com']);
        $this->import($studio, 'people', self::CLIENTS);
        [, $unlimited] = $this->membersAndPlans($studio);
        $this->import($studio, 'plans', "Email,Plan,Paid through\npriya@example.com,Unlimited,12/31/2026\n");
        $this->assertSame(1, Entitlement::where('plan_id', $unlimited->id)->count());

        $this->get("/{$studio->slug}/admin/import")->assertOk()->assertSee('5</strong> not invited yet', false)->assertSee('Send 5 invitations');
        $this->post("/{$studio->slug}/admin/import/invitations")->assertSessionHas('success', 'Sent 5 invitations.');
        $this->post("/{$studio->slug}/admin/import/invitations")->assertSessionHas('success', 'There was nobody to invite.');

        $invitations = Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'invitation');
        $this->assertCount(5, $invitations);
        $this->assertFalse($invitations->contains(fn ($m) => $m->hasTo('already@example.com')), 'members from before the import aren’t invited');
        $toPriya = $invitations->first(fn ($m) => $m->hasTo('priya@example.com'));
        $this->assertSame('Skyline has a new booking system', $toPriya->headline);
        $this->assertSame('Choose your password', $toPriya->actionLabel);
        $this->assertSame('Leo and Maya', $toPriya->details['Family']);
        $this->assertSame('Until Dec 31, 2026', $toPriya->details['Unlimited']);
        $this->assertStringContainsString('carries over', $toPriya->after[0]);
        $toOlga = $invitations->first(fn ($m) => $m->hasTo('olga@example.com'));
        $this->assertSame(['Sign in', route('studio.login', $studio)], [$toOlga->actionLabel, $toOlga->actionUrl]);

        // Priya chooses a password from the link.
        Auth::logout();
        $this->get($toPriya->actionUrl)->assertOk()->assertSee('Choose a password for <strong>priya@example.com</strong>', false);
        $this->post($toPriya->actionUrl, ['password' => 'new-password', 'password_confirmation' => 'new-password'])
            ->assertRedirect("/{$studio->slug}/account");
        $priya = User::where('email', 'priya@example.com')->sole();
        $this->assertAuthenticatedAs($priya);
        $this->assertTrue(Hash::check('new-password', $priya->password));
        $this->assertSame(config('loft.legal.terms_version'), $priya->terms_version, 'choosing a password agrees to the terms');
        $this->assertNotNull(StudioUser::where('studio_id', $studio->id)->where('user_id', $priya->id)->value('invitation_accepted_at'));
        Auth::logout();
        $this->get($toPriya->actionUrl)->assertRedirect("/{$studio->slug}/login")->assertSessionHas('warning');
        $this->get($toPriya->actionUrl.'x')->assertRedirect("/{$studio->slug}/login");
        $this->assertFalse(app(MemberInvitations::class)->needsPassword($priya));

        // A reminder for those who haven't come in, three days on.
        $this->actingAs($admin);
        $this->get("/{$studio->slug}/admin/import")->assertDontSee('Remind ');
        $this->travel(4)->days();
        $this->get("/{$studio->slug}/admin/import")->assertSee('Remind 4 who haven’t come in');
        $this->post("/{$studio->slug}/admin/import/invitations", ['which' => 'remind'])->assertSessionHas('success', 'Sent a reminder to 4 members.');
        $this->assertCount(9, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'invitation'));

        // Links last 30 days.
        $toSam = $invitations->first(fn ($m) => $m->hasTo('sam@example.com'));
        $this->travel(30)->days();
        $this->get($toSam->actionUrl)->assertRedirect("/{$studio->slug}/login")->assertSessionHas('warning', 'That invitation link has expired. Choose “Forgot your password?” below to get a new one.');
        $this->assertNotNull($olga->fresh());
    }

    /* ------------------------------------------------------------------ Carrying memberships on */

    public function test_imported_memberships_carry_on_in_loft_from_when_they_end(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_S', 'stripe_charges_enabled' => true, 'timezone' => 'UTC']);
        $this->adminOf($studio);
        [, $unlimited] = $this->membersAndPlans($studio);
        $ends = now()->addDays(20)->toDateString();
        $this->import($studio, 'plans', "Email,Plan,Paid through\npriya@example.com,Unlimited,{$ends}\nsam@example.com,Unlimited,{$ends}\n");
        $priya = User::where('email', 'priya@example.com')->sole();
        $imported = Entitlement::where('user_id', $priya->id)->sole();
        $this->post("/{$studio->slug}/admin/import/invitations"); // both invited
        StudioUser::where('user_id', User::where('email', 'sam@example.com')->value('id'))->update(['invited_at' => null]); // Sam hasn't heard yet

        // A week before it ends, Priya is asked to carry it on; once.
        $this->travel(10)->days();
        $this->artisan('loft:housekeeping');
        $this->assertCount(0, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'renewal_needed'));
        $this->travel(4)->days();
        $this->artisan('loft:housekeeping');
        $this->artisan('loft:housekeeping');
        $renewal = Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'renewal_needed')->sole();
        $this->assertTrue($renewal->hasTo($priya->email));
        $this->assertSame('Keep your Unlimited going', $renewal->headline);
        $this->assertStringEndsWith("/{$studio->slug}/plans#plan-{$unlimited->id}", $renewal->actionUrl);

        $this->actingAs($priya)->get("/{$studio->slug}/plans")->assertOk()->assertSee('Keep it going')->assertSee('your card is first charged on');
        $this->post("/{$studio->slug}/checkout/{$unlimited->id}")->assertRedirect('https://checkout.stripe.com/c/cs_new');
        $params = $this->checkouts[0];
        $this->assertSame($imported->expires_at->getTimestamp(), $params['subscription_data']['trial_end']);
        $this->assertSame($imported->id, $params['subscription_data']['metadata']['continues_entitlement_id']);

        // Stripe confirms: the Loft membership takes over and the imported one ends.
        $meta = $params['metadata'];
        $this->objects['checkout/sessions/cs_new'] = ['id' => 'cs_new', 'mode' => 'subscription', 'status' => 'complete', 'payment_status' => 'no_payment_required',
            'subscription' => 'sub_new', 'customer' => 'cus_member', 'metadata' => $meta];
        $this->objects['subscriptions/sub_new'] = ['id' => 'sub_new', 'customer' => 'cus_member', 'status' => 'trialing', 'current_period_end' => $imported->expires_at->getTimestamp(), 'metadata' => $meta];
        $this->objects['invoices/in_trial'] = ['id' => 'in_trial', 'subscription' => 'sub_new', 'total' => 0, 'billing_reason' => 'subscription_create', 'lines' => ['data' => []]];
        $this->postWebhook(['id' => 'evt_cs', 'type' => 'checkout.session.completed', 'account' => 'acct_S', 'data' => ['object' => ['id' => 'cs_new']]])->assertOk();
        $this->postWebhook(['id' => 'evt_in', 'type' => 'invoice.paid', 'account' => 'acct_S', 'data' => ['object' => ['id' => 'in_trial']]])->assertOk();

        $this->assertSame('expired', $imported->refresh()->status);
        $this->assertSame('continued', DomainEvent::where('type', 'entitlement.ended')->sole()->payload['reason']);
        $loft = Entitlement::where('stripe_subscription_id', 'sub_new')->sole();
        $this->assertSame([$priya->id, 'active'], [$loft->user_id, $loft->status]);
        $this->assertSame(0, Order::count(), 'the $0 opening invoice isn’t a sale');
        $this->artisan('loft:housekeeping');
        $this->assertCount(1, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'renewal_needed'), 'Sam isn’t asked before being invited');
    }

    public function test_members_already_paying_in_loft_arent_reminded(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_S', 'stripe_charges_enabled' => true]);
        $this->adminOf($studio);
        [, $unlimited] = $this->membersAndPlans($studio);
        $priya = User::where('email', 'priya@example.com')->sole();
        $this->import($studio, 'plans', "Email,Plan,Paid through\npriya@example.com,Unlimited,".now()->addDays(5)->format('n/j/Y')."\n");
        $this->post("/{$studio->slug}/admin/import/invitations");
        Entitlement::create(['studio_id' => $studio->id, 'user_id' => $priya->id, 'plan_id' => $unlimited->id, 'kind' => 'subscription', 'status' => 'active', 'stripe_subscription_id' => 'sub_x']);

        $this->artisan('loft:housekeeping');
        $this->assertCount(0, Mail::queued(StudioMessage::class, fn ($m) => $m->kind === 'renewal_needed'));
    }

    public function test_a_membership_ending_within_two_days_is_charged_when_carried_on(): void
    {
        $studio = $this->studio(['stripe_account_id' => 'acct_S', 'stripe_charges_enabled' => true]);
        $this->adminOf($studio);
        [, $unlimited] = $this->membersAndPlans($studio);
        Entitlement::create(['studio_id' => $studio->id, 'user_id' => User::where('email', 'priya@example.com')->value('id'), 'plan_id' => $unlimited->id,
            'kind' => 'subscription', 'status' => 'active', 'expires_at' => now()->addDay(), 'import_id' => Import::value('id')]);

        $this->actingAs(User::where('email', 'priya@example.com')->sole())->post("/{$studio->slug}/checkout/{$unlimited->id}");
        $this->assertArrayNotHasKey('trial_end', $this->checkouts[0]['subscription_data']);
        $this->assertArrayHasKey('continues_entitlement_id', $this->checkouts[0]['metadata']);
    }

    public function test_imports_can_be_run_again_after_failing(): void
    {
        $studio = $this->studio();
        $this->adminOf($studio);
        $this->upload($studio, 'people', "First Name,Last Name,Email\nA,B,a@example.com\n");
        $import = Import::sole();
        $import->update(['status' => 'failed', 'error' => 'Database went away.']);
        $this->get("/{$studio->slug}/admin/import/{$import->id}")->assertOk()->assertSee('Importing stopped part-way: Database went away.');
        $this->post("/{$studio->slug}/admin/import/{$import->id}/run");
        $this->assertSame('done', $import->refresh()->status);

        $broken = app(Imports::class)->start($studio, null, 'people', 'x.csv', "First Name,Last Name,Email\nA,B,b@example.com\n");
        $broken->update(['status' => 'queued', 'mapping' => ['first_name' => 7, 'email' => 2]]);
        app(Imports::class)->run($broken);
        $this->assertSame('done', $broken->refresh()->status, 'a column that isn’t there reads as blank');
    }
}
