<?php

namespace App\Services\Demo;

use App\Models\Automation;
use App\Models\Booking;
use App\Models\Category;
use App\Models\ClassSession;
use App\Models\Program;
use App\Models\Studio;
use App\Models\User;
use App\Services\Bookings;
use App\Services\ChurnScores;
use App\Services\Entitlements;
use App\Services\MemberSnapshots;
use App\Services\OfflinePayments;
use App\Services\Orders;
use App\Services\SessionGenerator;
use App\Services\Waivers;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

/**
 * Skyline Aerial Arts, the sample studio: rooms and apparatus, classes, plans, a waiver, products,
 * automations and a few members (what `php artisan db:seed` builds). With $busy, a lived-in month
 * on top: more classes (pole too), about 60 members on plans, a week of bookings, six weeks of
 * attendance, this month's sales, and churn scores, which is what a demo studio gets (decision 44).
 */
class SampleStudio
{
    /** The studio's own details; the caller adds its slug (and, for a demo, its expiry). */
    public const ATTRIBUTES = [
        'name' => 'Skyline Aerial Arts',
        'timezone' => 'America/Denver',
        'currency' => 'usd',
        'accent_color' => '#0f766e',
        'email_from_name' => 'Skyline Aerial Arts',
    ];

    public function __construct(
        private readonly SessionGenerator $generator,
        private readonly Entitlements $entitlements,
        private readonly Bookings $bookings,
        private readonly Waivers $waivers,
    ) {}

    /**
     * @param  callable(string): string  $email  each sample person's address, from a key: owner, coach, parent, adult, jordan, or first.last
     * @param  array<string, mixed>  $userAttributes  set on every account made, over the defaults (a demo's User::madeIn(), or the seeder's password)
     * @param  int  $weeksAhead  how far the season (and so every class's sessions) runs: a demo needs a few weeks, not months
     * @return array{owner: User, coach: User, parent: User, adult: User}
     */
    public function build(Studio $studio, callable $email, array $userAttributes = [], bool $busy = false, int $weeksAhead = 30): array
    {
        Category::seedDefaults($studio);
        // One slow hash for every sample account, not one each: nobody signs in to them with a password.
        $userAttributes = ['password' => Hash::make($userAttributes['password'] ?? Str::random(40))] + $userAttributes;

        $user = fn (string $first, string $last, string $key, string $role, ?string $phone = null) => tap(
            User::create($userAttributes + ['first_name' => $first, 'last_name' => $last, 'email' => $email($key), 'phone' => $phone]),
            fn (User $u) => $studio->studioUsers()->create(['user_id' => $u->id, 'role' => $role]),
        );
        $owner = $user('Olivia', 'Owens', 'owner', 'owner', '303-555-0100');
        $coach = $user('Cam', 'Rivera', 'coach', 'instructor', '303-555-0101');
        $parent = $user('Priya', 'Shah', 'parent', 'member', '303-555-0142');
        $adult = $user('Alex', 'Kim', 'adult', 'member', '303-555-0177');

        $season = $studio->seasons()->create([
            'name' => now()->year.'–'.(now()->year + 1).' Season',
            'starts_on' => now()->subWeeks(6)->toDateString(),
            'ends_on' => now()->addWeeks($weeksAhead)->toDateString(), // every class's sessions run to here
            'is_current' => true,
        ]);

        // Rooms, and the apparatus rigged in the main one.
        $main = $studio->rooms()->create(['name' => 'Main studio', 'max_capacity' => 16]);
        $flex = $studio->rooms()->create(['name' => 'Flex room', 'max_capacity' => 20]);
        $silks = $main->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Silks', 'quantity' => 8, 'max_per_item' => 1]);
        $lyra = $main->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Lyra', 'quantity' => 4, 'max_per_item' => 2]);
        $use = fn (int $quantity, int $perItem) => ['quantity' => $quantity, 'max_per_item' => $perItem];

        $cat = fn (string $type, string $slug) => $studio->categories()->where('type', $type)->where('slug', $slug)->value('id');
        $program = function (array $attrs, array $categories, array $apparatus = []) use ($studio, $season, $coach) {
            $p = $studio->programs()->create($attrs + [
                'type' => 'class', 'season_id' => $season->id, 'duration_min' => 60, 'capacity' => 10,
                'instructor_id' => $coach->id, 'is_published' => true,
            ]);
            $p->categories()->sync(array_filter($categories));
            $p->apparatus()->sync($apparatus);
            $this->generator->sync($p->fresh());

            return $p;
        };

        $kids = $program([
            'title' => 'Kids Aerial Silks', 'weekday' => 2, 'start_time' => '16:30', 'capacity' => 8, 'min_age' => 6, 'max_age' => 11, 'room_id' => $main->id,
            'excerpt' => 'Climbs, wraps and drops for young aerialists.', 'dropin_price_cents' => 2500,
            'description' => "A playful introduction to aerial silks. We build grip strength, body awareness and confidence with climbs, foot locks and our first drops.\n\nWear fitted clothes that cover the backs of the knees.",
        ], [$cat('age_group', 'kids'), $cat('level', 'recreational'), $cat('plan_tag', 'recreational')], [$silks->id => $use(8, 1)]);
        $program([
            'title' => 'Teen Lyra', 'weekday' => 3, 'start_time' => '17:30', 'capacity' => 8, 'min_age' => 12, 'max_age' => 17, 'room_id' => $main->id,
            'excerpt' => 'Spins, poses and sequences on the aerial hoop.', 'dropin_price_cents' => 2500,
        ], [$cat('age_group', 'teens'), $cat('level', 'recreational'), $cat('plan_tag', 'recreational')], [$lyra->id => $use(4, 2)]);
        $adultSilks = $program([
            'title' => 'Adult Silks Basics', 'weekday' => 1, 'start_time' => '18:30', 'duration_min' => 75, 'capacity' => 2, 'min_age' => 16,
            'room_id' => $main->id, 'waitlist_capacity' => 3,
            'excerpt' => 'No experience needed.', 'dropin_price_cents' => 2800,
        ], [$cat('age_group', 'adults'), $cat('level', 'technique'), $cat('plan_tag', 'recreational')], [$silks->id => $use(2, 1)]);
        $program([
            'title' => 'Competitive Team', 'weekday' => 4, 'start_time' => '16:00', 'duration_min' => 120, 'capacity' => 12, 'members_only' => true, 'room_id' => $main->id,
            'excerpt' => 'By invitation. Competition choreography and conditioning.',
        ], [$cat('age_group', 'teens'), $cat('level', 'competitive'), $cat('plan_tag', 'competitive')]);
        $program([
            'title' => 'Open Practice', 'weekday' => 6, 'start_time' => '10:00', 'duration_min' => 90, 'capacity' => 12, 'min_age' => 16, 'room_id' => $main->id,
            'excerpt' => 'Supervised practice time for students with instructor sign-off.', 'dropin_price_cents' => 1500,
        ], [$cat('age_group', 'adults'), $cat('level', 'open-gym'), $cat('plan_tag', 'recreational')], [$silks->id => $use(8, 1), $lyra->id => $use(4, 1)]);
        $program([
            'title' => 'Flexibility Intensive Workshop', 'type' => 'event', 'season_id' => null, 'event_date' => now()->addDays(10)->toDateString(),
            'start_time' => '13:00', 'duration_min' => 180, 'capacity' => 16, 'dropin_price_cents' => 6000, 'room_id' => $flex->id,
            'excerpt' => 'Three hours of active flexibility for splits and backbends.',
        ], [$cat('age_group', 'adults'), $cat('age_group', 'teens'), $cat('plan_tag', 'events')]);

        $studio->plans()->createMany([
            ['name' => 'Monthly Recreational', 'kind' => 'subscription', 'price_cents' => 9000, 'currency' => 'usd', 'billing_interval' => 'month', 'program_scope' => ['recreational'], 'sort' => 1, 'description' => 'Unlimited recreational classes.'],
            ['name' => 'Twice a Week', 'kind' => 'subscription', 'price_cents' => 7000, 'currency' => 'usd', 'billing_interval' => 'month', 'weekly_limit' => 2, 'program_scope' => ['recreational'], 'sort' => 2],
            ['name' => 'Competitive Team Tuition', 'kind' => 'season', 'price_cents' => 11500, 'currency' => 'usd', 'billing_interval' => 'month', 'season_id' => $season->id, 'registration_fee_cents' => 5000, 'program_scope' => ['competitive'], 'sort' => 3],
            ['name' => '5-Class Pack', 'kind' => 'pack', 'price_cents' => 11000, 'currency' => 'usd', 'credits' => 5, 'credit_validity_days' => 90, 'program_scope' => ['recreational', 'events'], 'sort' => 4],
            ['name' => 'Drop-in', 'kind' => 'dropin', 'price_cents' => 2800, 'currency' => 'usd', 'credits' => 1, 'program_scope' => ['recreational', 'events'], 'sort' => 5],
        ]);

        $waiver = $studio->waivers()->create([
            'title' => 'Release of Liability and Assumption of Risk',
            'version' => 1,
            'is_active' => true,
            'body' => "Aerial arts involve physical exertion at height and carry an inherent risk of injury, including falls, strains and sprains.\n\n"
                ."I confirm the participant is in good health and will tell the instructor about any injury or condition before class.\n\n"
                ."I agree to follow all instructions from Skyline Aerial Arts staff and to use equipment only under supervision.\n\n"
                .'I release Skyline Aerial Arts, its owners and staff from liability for injury arising from ordinary participation, except where caused by gross negligence.',
        ]);

        // Merchandise for the point of sale, with a sales tax rate.
        $studio->update(['settings' => ['sales_tax_milli' => 7250] + ($studio->settings ?? [])]);
        $tee = $studio->products()->create(['name' => 'Studio Tee', 'description' => 'Soft cotton, Skyline logo.', 'price_cents' => 2500]);
        foreach (['Small' => 6, 'Medium' => 2, 'Large' => 0] as $size => $stock) {
            $tee->variants()->create(['studio_id' => $studio->id, 'name' => $size, 'stock' => $stock, 'sort' => $tee->variants()->count()]);
        }
        $grip = $studio->products()->create(['name' => 'Grip Aid', 'price_cents' => 1200]);
        $grip->variants()->create(['studio_id' => $studio->id, 'name' => 'One size']);

        // Automations: the first-class follow-up is on; birthday and win-back are ready to turn on.
        foreach (array_keys(Automation::TRIGGERS) as $trigger) {
            $studio->automations()->create(['trigger' => $trigger, 'active' => $trigger === 'first_class'] + Automation::defaults($trigger));
        }

        // A family with a membership, booked into this week's kids class.
        $maya = $studio->attendees()->create(['user_id' => $parent->id, 'first_name' => 'Maya', 'last_name' => 'Shah', 'dob' => now()->subYears(9)->toDateString(), 'medical_notes' => 'Mild asthma — inhaler in bag.', 'emergency_contact' => 'Dev Shah 303-555-0143']);
        $leo = $studio->attendees()->create(['user_id' => $parent->id, 'first_name' => 'Leo', 'last_name' => 'Shah', 'dob' => now()->subYears(7)->toDateString(), 'emergency_contact' => 'Dev Shah 303-555-0143']);
        $alex = $studio->attendees()->create(['user_id' => $adult->id, 'first_name' => 'Alex', 'last_name' => 'Kim', 'dob' => now()->subYears(29)->toDateString(), 'is_self' => true]);
        foreach ([[$maya, $parent, 'Priya Shah'], [$leo, $parent, 'Priya Shah'], [$alex, $adult, 'Alex Kim']] as [$attendee, $signer, $name]) {
            $this->waivers->sign($studio, $attendee, $waiver, $signer, $name, null, '127.0.0.1', 'sample');
        }

        $plans = $studio->plans()->get()->keyBy('kind');
        $membership = $this->entitlements->grantFromPlan($plans['subscription'], $parent->id);
        // Bought at the front desk, so the Sales page has a sale.
        $pack = app(OfflinePayments::class)->record($plans['pack'], $adult, $owner, ['method' => 'card', 'paid_on' => $studio->localNow()->toDateString()])->entitlement;
        // Ordered from the online shop, paid, waiting at the front desk.
        $gripAid = $grip->variants()->sole();
        $orders = app(Orders::class);
        $orders->markPaid($orders->createPending($studio, ['user_id' => $adult->id, 'channel' => 'online', 'method' => 'stripe', 'currency' => $studio->currency,
            'subtotal_cents' => 1200, 'tax_cents' => 87, 'total_cents' => 1287, 'fulfillment_status' => 'to_pick_up'], [[
                'kind' => 'product', 'product_variant_id' => $gripAid->id, 'description' => $gripAid->label(), 'quantity' => 1, 'unit_price_cents' => 1200, 'total_cents' => 1200, 'tax_cents' => 87,
            ]]), []);

        $next = fn (Program $p) => $p->sessions()->where('starts_at', '>', now())->orderBy('starts_at')->first();
        $this->bookings->book($next($kids), $maya, $parent->id, 'web', $membership);
        $this->bookings->book($next($kids), $leo, $parent->id, 'web', $membership);
        $this->bookings->book($next($adultSilks), $alex, $adult->id, 'web', $pack);

        // Fill the small adult class so the next booking waitlists.
        $walkIn = $user('Jordan', 'Lee', 'jordan', 'member');
        $jordan = $studio->attendees()->create(['user_id' => $walkIn->id, 'first_name' => 'Jordan', 'last_name' => 'Lee', 'is_self' => true]);
        $this->waivers->sign($studio, $jordan, $waiver, $walkIn, 'Jordan Lee', null, '127.0.0.1', 'sample');
        $this->bookings->book($next($adultSilks), $jordan, $walkIn->id, 'admin', null, skipEntitlement: true);

        if ($busy) {
            $this->busy($studio, $email, $userAttributes, $owner, $coach, $season, $main, $flex, $silks, $lyra, $waiver);
        }

        // Tonight's snapshot and risk scores, so the members list and dashboard show churn risk now.
        app(MemberSnapshots::class)->take($studio, $studio->localNow()->toDateString());
        app(ChurnScores::class)->scoreLatest($studio);

        return ['owner' => $owner, 'coach' => $coach, 'parent' => $parent, 'adult' => $adult];
    }

    /** A lived-in month: more classes, about 60 members on plans, bookings, attendance and sales. */
    private function busy(Studio $studio, callable $email, array $userAttributes, User $owner, User $coach, $season, $main, $flex, $silks, $lyra, $waiver): void
    {
        $random = new Randomizer(new Mt19937(7));
        $int = fn (int $min, int $max) => $random->getInt($min, $max);

        $poles = $flex->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Poles', 'quantity' => 6, 'max_per_item' => 2]);
        $flex->update(['max_capacity' => 16]);
        $okafor = User::create($userAttributes + ['first_name' => 'Maya', 'last_name' => 'Okafor', 'email' => $email('maya.okafor')]);
        $studio->studioUsers()->create(['user_id' => $okafor->id, 'role' => 'instructor']);

        $cat = fn (string $type, string $slug) => $studio->categories()->where('type', $type)->where('slug', $slug)->value('id');
        $program = function (array $attrs, array $apparatus = [], string $age = 'adults') use ($studio, $season, $coach, $cat) {
            $p = $studio->programs()->create($attrs + [
                'type' => 'class', 'season_id' => $season->id, 'duration_min' => 60, 'capacity' => 10, 'min_age' => 16,
                'instructor_id' => $coach->id, 'is_published' => true, 'dropin_price_cents' => 2800,
            ]);
            $p->categories()->sync(array_filter([$cat('age_group', $age), $cat('level', 'recreational'), $cat('plan_tag', 'recreational')]));
            $p->apparatus()->sync($apparatus);
            $this->generator->sync($p->fresh());

            return $p;
        };
        $use = fn (int $q, int $per) => ['quantity' => $q, 'max_per_item' => $per];
        $program(['title' => 'Pole Fundamentals', 'weekday' => 1, 'start_time' => '19:45', 'capacity' => 12, 'room_id' => $flex->id, 'instructor_id' => $okafor->id, 'excerpt' => 'Spins, climbs and floorwork from scratch.'], [$poles->id => $use(6, 2)]);
        $program(['title' => 'Aerial Hoop Flow', 'weekday' => 2, 'start_time' => '18:00', 'capacity' => 8, 'room_id' => $main->id, 'excerpt' => 'Linking shapes into flowing sequences.'], [$lyra->id => $use(4, 2)]);
        $program(['title' => 'Pole Spin & Climb', 'weekday' => 2, 'start_time' => '19:30', 'capacity' => 6, 'room_id' => $flex->id, 'instructor_id' => $okafor->id, 'excerpt' => 'Spinning pole technique, one per pole.'], [$poles->id => $use(6, 1)]);
        $program(['title' => 'Silks Level 2', 'weekday' => 3, 'start_time' => '19:00', 'duration_min' => 75, 'capacity' => 8, 'room_id' => $main->id, 'excerpt' => 'Drops, sequences and inversions in the air.'], [$silks->id => $use(8, 1)]);
        $program(['title' => 'Aerial Conditioning', 'weekday' => 4, 'start_time' => '18:30', 'capacity' => 16, 'room_id' => $flex->id, 'instructor_id' => $okafor->id, 'excerpt' => 'Strength and mobility for aerialists.']);
        $program(['title' => 'Pole Choreo', 'weekday' => 5, 'start_time' => '18:00', 'capacity' => 12, 'room_id' => $flex->id, 'instructor_id' => $okafor->id, 'excerpt' => 'Learn a routine over four weeks.'], [$poles->id => $use(6, 2)]);
        $program(['title' => 'Kids Circus Sampler', 'weekday' => 6, 'start_time' => '11:45', 'capacity' => 10, 'min_age' => 6, 'max_age' => 11, 'room_id' => $main->id, 'dropin_price_cents' => 2500, 'excerpt' => 'Silks, lyra and tumbling games.'], [$silks->id => $use(6, 1), $lyra->id => $use(4, 1)], 'kids');
        $program(['title' => 'Stretch & Flexibility', 'weekday' => 0, 'start_time' => '10:00', 'capacity' => 16, 'min_age' => 14, 'room_id' => $flex->id, 'excerpt' => 'Splits, backbends and shoulder mobility.']);

        // The small adult class at a regular size.
        $studio->programs()->where('title', 'Adult Silks Basics')->each(function (Program $p) {
            $p->update(['capacity' => 8]);
            $p->apparatus()->updateExistingPivot($p->apparatus()->first()->id, ['quantity' => 8]);
            ClassSession::where('program_id', $p->id)->update(['capacity' => 8]);
        });

        $firsts = ['Ava', 'Sofia', 'Chloe', 'Zoe', 'Hannah', 'Grace', 'Lily', 'Nora', 'Leah', 'Ruby', 'Isla', 'Mia', 'Elena', 'Camila', 'Aaliyah', 'Naomi', 'Jade', 'Iris', 'Tessa', 'Julia', 'Priya', 'Mei', 'Ana', 'Fatima', 'Rhea', 'Kira', 'Sasha', 'Dana', 'Erin', 'Lena', 'Marcus', 'Theo', 'Sam', 'Jamie', 'Riley', 'Morgan', 'Quinn', 'Avery', 'Noah', 'Ethan', 'Luis', 'Omar', 'Kai', 'Ben', 'Tomás', 'Bianca', 'Yara', 'Paige', 'Harper', 'Willa', 'Eden', 'Skye', 'Maren', 'Talia', 'Ines', 'Freya', 'Alma', 'Celeste', 'Hazel', 'June'];
        $lasts = ['Nguyen', 'Garcia', 'Patel', 'Johnson', 'Martinez', 'Chen', 'Brooks', 'Rivera', 'Kowalski', 'Hughes', 'Okafor', 'Silva', 'Tanaka', 'Novak', 'Fischer', 'Bennett', 'Reyes', 'Cohen', 'Morales', 'Ward', 'Ali', 'Larsen', 'Moreau', 'Flores', 'Park', 'Hayes', 'Romero', 'Walsh', 'Kim', 'Bauer'];
        $kidsNames = ['Ella', 'Max', 'Lucy', 'Finn', 'Rosie', 'Milo', 'Ivy', 'Owen', 'Clara', 'Jude', 'Poppy', 'Ezra'];
        $plans = $studio->plans()->get()->keyBy('name');

        $members = [];
        foreach ($firsts as $i => $first) {
            $last = $lasts[$i % count($lasts)];
            $u = User::create($userAttributes + ['first_name' => $first, 'last_name' => $last, 'email' => $email(strtolower(Str::ascii($first.'.'.$last))),
                'phone' => '303-555-'.str_pad((string) (200 + $i), 4, '0', STR_PAD_LEFT)]);
            $studio->studioUsers()->create(['user_id' => $u->id, 'role' => 'member'])->forceFill(['created_at' => now()->subDays($int(50, 400))])->save();
            $self = $studio->attendees()->create(['user_id' => $u->id, 'first_name' => $first, 'last_name' => $last, 'dob' => now()->subYears($int(19, 48))->subDays($int(0, 300))->toDateString(),
                'is_self' => true, 'emergency_contact' => $firsts[($i + 17) % count($firsts)].' '.$last.' 303-555-0'.$int(100, 999)]);
            $this->waivers->sign($studio, $self, $waiver, $u, "$first $last", null, '127.0.0.1', 'sample');
            $kids = [];
            if ($i % 6 === 0) {
                $kid = $studio->attendees()->create(['user_id' => $u->id, 'first_name' => $kidsNames[intdiv($i, 6) % count($kidsNames)], 'last_name' => $last,
                    'dob' => now()->subYears($int(6, 11))->toDateString(), 'emergency_contact' => "$first $last 303-555-0".$int(100, 999)]);
                $this->waivers->sign($studio, $kid, $waiver, $u, "$first $last", null, '127.0.0.1', 'sample');
                $kids[] = $kid;
            }
            $r = $i % 10;
            $plan = $r < 5 ? $plans['Monthly Recreational'] : ($r < 7 ? $plans['Twice a Week'] : ($r < 9 ? $plans['5-Class Pack'] : null));
            $members[] = ['u' => $u, 'self' => $self, 'kids' => $kids, 'plan' => $plan, 'ent' => $plan ? $this->entitlements->grantFromPlan($plan, $u->id) : null];
        }
        $lapsed = ['Tessa', 'Marcus', 'Kira', 'Willa', 'Omar', 'Bianca'];
        $shuffled = function () use ($random, $members) {
            return $random->shuffleArray($members);
        };

        // The coming week: most classes three-quarters full, some full with a waitlist.
        $sessions = ClassSession::where('studio_id', $studio->id)->where('starts_at', '>', now())->where('starts_at', '<', now()->addDays(8))
            ->where('status', '!=', 'cancelled')->with('program.apparatus')->orderBy('starts_at')->get();
        foreach ($sessions as $n => $s) {
            $kidsClass = $s->program->max_age && $s->program->max_age < 16;
            $target = $kidsClass ? $int(4, 7) : (int) round($s->capacity * [0.6, 0.75, 1.0, 0.85, 1.0, 0.7][$n % 6]) + ($n % 3 === 0 ? 2 : 0);
            $made = 0;
            foreach ($shuffled() as $m) {
                if ($made >= $target) {
                    break;
                }
                $attendee = $kidsClass ? ($m['kids'][0] ?? null) : $m['self'];
                if (! $attendee || in_array($m['u']->first_name, $lapsed, true)) {
                    continue;
                }
                try {
                    $this->bookings->book($s, $attendee, $m['u']->id, 'web', $m['ent'], skipEntitlement: $m['ent'] === null, apparatusId: $s->program->apparatus->first()?->id);
                    $made++;
                } catch (Throwable) {
                    // over a weekly limit, or the waitlist is full: someone else
                }
            }
        }

        // Six weeks of past classes and attendance, so activity and risk scores have history.
        foreach ($studio->programs()->where('type', 'class')->get() as $p) {
            $first = $p->sessions()->orderBy('starts_at')->first();
            foreach (range(1, 6) as $w) {
                $copy = $first->replicate();
                $copy->starts_at = $first->starts_at->copy()->subWeeks($w);
                $copy->ends_at = $first->ends_at->copy()->subWeeks($w);
                $copy->save();
            }
        }
        $past = ClassSession::where('studio_id', $studio->id)->where('starts_at', '<', now())->where('starts_at', '>', now()->subWeeks(6))->with('program')->get();
        $rows = [];
        foreach ($past as $s) {
            $kidsClass = $s->program->max_age && $s->program->max_age < 16;
            foreach (array_slice($shuffled(), 0, min($s->capacity, $int(4, 9))) as $m) {
                $attendee = $kidsClass ? ($m['kids'][0] ?? null) : $m['self'];
                // A handful of members stopped coming nearly four weeks ago.
                if (! $attendee || (in_array($m['u']->first_name, $lapsed, true) && $s->starts_at > now()->subDays(26))) {
                    continue;
                }
                $rows[] = ['studio_id' => $studio->id, 'class_session_id' => $s->id, 'attendee_id' => $attendee->id, 'user_id' => $m['u']->id, 'entitlement_id' => $m['ent']?->id,
                    'status' => $int(1, 12) === 1 ? 'no_show' : 'attended', 'source' => 'web', 'booked_at' => $s->starts_at->copy()->subDays(2), 'attended_at' => $s->starts_at,
                    'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            Booking::insert($chunk);
        }

        // This month's sales: membership renewals, packs, drop-ins and merchandise.
        $orders = app(Orders::class);
        $monthStart = $studio->localNow()->startOfMonth();
        $daysIn = max(1, (int) $monthStart->diffInDays($studio->localNow()));
        $sale = function (?User $u, string $channel, string $method, array $items) use ($orders, $studio, $monthStart, $daysIn, $int) {
            $subtotal = array_sum(array_column($items, 'total_cents'));
            $tax = array_sum(array_column($items, 'tax_cents'));
            $order = $orders->createPending($studio, ['user_id' => $u?->id, 'channel' => $channel, 'method' => $method, 'currency' => $studio->currency,
                'subtotal_cents' => $subtotal, 'tax_cents' => $tax, 'total_cents' => $subtotal + $tax], $items);
            $orders->markPaid($order, []);
            $order->forceFill(['paid_at' => $monthStart->copy()->addDays($int(0, $daysIn))->setTime($int(9, 20), $int(0, 59))->utc()->min(now())])->save();
        };
        foreach ($members as $m) {
            if ($m['plan']) {
                $sale($m['u'], 'online', 'stripe', [['kind' => 'plan', 'plan_id' => $m['plan']->id, 'description' => $m['plan']->name, 'quantity' => 1,
                    'unit_price_cents' => $m['plan']->price_cents, 'total_cents' => $m['plan']->price_cents, 'tax_cents' => 0]]);
            }
        }
        $dropin = $plans['Drop-in'];
        foreach (array_slice($members, 0, 14) as $m) {
            $sale($m['u'], 'online', 'stripe', [['kind' => 'plan', 'plan_id' => $dropin->id, 'description' => 'Drop-in', 'quantity' => 1, 'unit_price_cents' => 2800, 'total_cents' => 2800, 'tax_cents' => 0]]);
        }
        $tee = $studio->products()->where('name', 'Studio Tee')->first()->variants()->first();
        $grip = $studio->products()->where('name', 'Grip Aid')->first()->variants()->first();
        foreach (range(1, 9) as $k) {
            $variant = $k % 2 ? $tee : $grip;
            $price = $variant->product->price_cents;
            $sale($members[$k * 3]['u'], 'in_person', 'card', [['kind' => 'product', 'product_variant_id' => $variant->id, 'description' => $variant->label(), 'quantity' => 1,
                'unit_price_cents' => $price, 'total_cents' => $price, 'tax_cents' => (int) round($price * 0.0725)]]);
        }

        // A finished import, as a studio that moved over would have.
        $studio->imports()->create(['user_id' => $owner->id, 'kind' => 'people', 'status' => 'done', 'file_name' => 'clients.csv', 'csv' => '', 'row_count' => 60,
            'finished_at' => now()->subDays(40), 'started_at' => now()->subDays(40)]);
    }
}
