<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Program;
use App\Models\Studio;
use App\Models\User;
use App\Services\Bookings;
use App\Services\Entitlements;
use App\Services\SessionGenerator;
use App\Services\Waivers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;

/**
 * A demo studio to click around in: `php artisan migrate:fresh --seed`, then sign in at /login.
 *   owner@example.com / password      studio owner (admin)
 *   coach@example.com / password      instructor (check-in only)
 *   parent@example.com / password     member with two kids and a membership
 *   adult@example.com / password      member with a class pack
 */
class DatabaseSeeder extends Seeder
{
    public function run(SessionGenerator $generator, Entitlements $entitlements, Bookings $bookings, Waivers $waivers): void
    {
        Event::fake(); // no welcome/booking emails for demo data

        $studio = Studio::create([
            'name' => 'Skyline Aerial Arts',
            'slug' => 'skyline',
            'timezone' => 'America/Denver',
            'currency' => 'usd',
            'accent_color' => '#0f766e',
            'contact_email' => 'hello@skyline.example',
            'email_from_name' => 'Skyline Aerial Arts',
        ]);
        Category::seedDefaults($studio);

        $user = fn (string $first, string $last, string $email, string $role, ?string $phone = null) => tap(
            User::create(['first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => $phone, 'password' => 'password']),
            fn (User $u) => $studio->studioUsers()->create(['user_id' => $u->id, 'role' => $role]),
        );
        $owner = $user('Olivia', 'Owens', 'owner@example.com', 'owner', '303-555-0100');
        $coach = $user('Cam', 'Rivera', 'coach@example.com', 'instructor', '303-555-0101');
        $parent = $user('Priya', 'Shah', 'parent@example.com', 'member', '303-555-0142');
        $adult = $user('Alex', 'Kim', 'adult@example.com', 'member', '303-555-0177');

        $season = $studio->seasons()->create([
            'name' => now()->year.'–'.(now()->year + 1).' Season',
            'starts_on' => now()->subWeeks(6)->toDateString(),
            'ends_on' => now()->addMonths(7)->toDateString(),
            'is_current' => true,
        ]);

        // Rooms, and the apparatus rigged in the main one.
        $main = $studio->rooms()->create(['name' => 'Main studio', 'max_capacity' => 16]);
        $flex = $studio->rooms()->create(['name' => 'Flex room', 'max_capacity' => 20]);
        $silks = $main->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Silks', 'quantity' => 8, 'max_per_item' => 1]);
        $lyra = $main->apparatus()->create(['studio_id' => $studio->id, 'name' => 'Lyra', 'quantity' => 4, 'max_per_item' => 2]);
        $use = fn (int $quantity, int $perItem) => ['quantity' => $quantity, 'max_per_item' => $perItem];

        $cat = fn (string $type, string $slug) => $studio->categories()->where('type', $type)->where('slug', $slug)->value('id');
        $program = function (array $attrs, array $categories, array $apparatus = []) use ($studio, $season, $coach, $generator) {
            $p = $studio->programs()->create($attrs + [
                'type' => 'class', 'season_id' => $season->id, 'duration_min' => 60, 'capacity' => 10,
                'instructor_id' => $coach->id, 'is_published' => true,
            ]);
            $p->categories()->sync(array_filter($categories));
            $p->apparatus()->sync($apparatus);
            $generator->sync($p->fresh());

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

        // A family with a membership, booked into this week's kids class.
        $maya = $studio->attendees()->create(['user_id' => $parent->id, 'first_name' => 'Maya', 'last_name' => 'Shah', 'dob' => now()->subYears(9)->toDateString(), 'medical_notes' => 'Mild asthma — inhaler in bag.', 'emergency_contact' => 'Dev Shah 303-555-0143']);
        $leo = $studio->attendees()->create(['user_id' => $parent->id, 'first_name' => 'Leo', 'last_name' => 'Shah', 'dob' => now()->subYears(7)->toDateString(), 'emergency_contact' => 'Dev Shah 303-555-0143']);
        $alex = $studio->attendees()->create(['user_id' => $adult->id, 'first_name' => 'Alex', 'last_name' => 'Kim', 'dob' => now()->subYears(29)->toDateString(), 'is_self' => true]);
        foreach ([[$maya, $parent, 'Priya Shah'], [$leo, $parent, 'Priya Shah'], [$alex, $adult, 'Alex Kim']] as [$attendee, $signer, $name]) {
            $waivers->sign($studio, $attendee, $waiver, $signer, $name, null, '127.0.0.1', 'seed');
        }

        $plans = $studio->plans()->get()->keyBy('kind');
        $membership = $entitlements->grantFromPlan($plans['subscription'], $parent->id);
        $pack = $entitlements->grantFromPlan($plans['pack'], $adult->id);

        $next = fn (Program $p) => $p->sessions()->where('starts_at', '>', now())->orderBy('starts_at')->first();
        $bookings->book($next($kids), $maya, $parent->id, 'web', $membership);
        $bookings->book($next($kids), $leo, $parent->id, 'web', $membership);
        $bookings->book($next($adultSilks), $alex, $adult->id, 'web', $pack);

        // Fill the small adult class so the next booking waitlists.
        $walkIn = $user('Jordan', 'Lee', 'jordan@example.com', 'member');
        $jordan = $studio->attendees()->create(['user_id' => $walkIn->id, 'first_name' => 'Jordan', 'last_name' => 'Lee', 'is_self' => true]);
        $waivers->sign($studio, $jordan, $waiver, $walkIn, 'Jordan Lee', null, '127.0.0.1', 'seed');
        $bookings->book($next($adultSilks), $jordan, $walkIn->id, 'admin', null, skipEntitlement: true);

        $this->command?->info("Demo studio: /{$studio->slug} — sign in as owner@example.com / password");
    }
}
