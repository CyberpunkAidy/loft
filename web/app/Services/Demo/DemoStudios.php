<?php

namespace App\Services\Demo;

use App\Jobs\RefillDemoPool;
use App\Models\DemoRun;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * "Try the demo" (decision 44): each visitor gets a private copy of the sample studio, signed in as
 * its owner, with a sample member to switch to. A demo is comped (no Loft billing), never emails
 * (Messages), can't take payments or upload video (NotInDemo), and is deleted with every account
 * made in it after config('loft.demo.hours').
 *
 * Building one takes seconds, so a few spares are built ahead (demo_claimed_at null): starting a
 * demo claims one and queues another. A spare nobody takes is replaced after spare_hours, so its
 * week of bookings stays current. The browser that started a demo keeps a cookie naming it, the
 * only way to switch between its owner and member.
 */
class DemoStudios
{
    public const COOKIE = 'loft_demo';

    public function __construct(private readonly SampleStudio $sample) {}

    public function hours(): int
    {
        return max(1, (int) config('loft.demo.hours'));
    }

    /** Whether another demo can start now: there's a cap on how many exist at once. */
    public function hasRoom(): bool
    {
        return Studio::whereNotNull('demo_claimed_at')->whereNotNull('demo_expires_at')->count() < (int) config('loft.demo.max_live');
    }

    /** Whether spares are built ahead (on the worker) rather than in the visitor's request. */
    public function pooled(): bool
    {
        return (int) config('loft.demo.pool') > 0;
    }

    /** Whether a spare is ready to be taken now. */
    public function spareReady(): bool
    {
        return Studio::whereNotNull('demo_expires_at')->whereNull('demo_claimed_at')->where('demo_expires_at', '>', now()->addHour())->exists();
    }

    /** Asks the worker to build spares, unless that's already queued (the job is unique). */
    public function requestSpares(): void
    {
        if ($this->pooled()) {
            RefillDemoPool::dispatch();
        }
    }

    /**
     * A visitor's demo, its day starting now: a spare if one is ready. With spares built ahead, a
     * visitor never waits on a build in their own request (it can take half a minute on a small
     * database), so this returns null and the visitor waits on /demo/wait instead. Without a pool
     * (tests, local), it's built here.
     *
     * @return array{studio: Studio, owner: User, member: User, run: DemoRun}|null
     */
    public function start(): ?array
    {
        $studio = $this->claimSpare() ?? ($this->pooled() ? null : $this->build(claimed: true));
        if (! $studio) {
            $this->requestSpares();

            return null;
        }
        $expires = now()->addHours($this->hours());
        DB::transaction(function () use ($studio, $expires) {
            $studio->update(['demo_expires_at' => $expires, 'demo_claimed_at' => $studio->demo_claimed_at ?? now()]);
            User::where('demo_studio_id', $studio->id)->update(['demo_expires_at' => $expires]);
        });
        $this->requestSpares(); // replace the one just taken
        $token = Str::after($studio->slug, 'demo-');

        return [
            'studio' => $studio,
            'owner' => User::where('email', "owner@{$token}.example")->sole(),
            'member' => User::where('email', "parent@{$token}.example")->sole(),
            'run' => DemoRun::create(),
        ];
    }

    /** Builds spares until config('loft.demo.pool') are ready, or $max of them. */
    public function refill(int $max = PHP_INT_MAX): int
    {
        $built = 0;
        while ($built < $max && Studio::whereNotNull('demo_expires_at')->whereNull('demo_claimed_at')->count() < (int) config('loft.demo.pool')) {
            $this->build(claimed: false);
            $built++;
        }

        return $built;
    }

    /** One copy of the sample studio, in one transaction: a spare, or claimed straight away. */
    public function build(bool $claimed): Studio
    {
        return DB::transaction(function () use ($claimed) {
            $token = $this->token();
            $studio = Studio::create(SampleStudio::ATTRIBUTES + [
                'slug' => "demo-{$token}",
                'contact_email' => "hello@{$token}.example",
                'comped_at' => now(),
                'comp_note' => 'Demo',
                'demo_expires_at' => now()->addHours($claimed ? $this->hours() : (int) config('loft.demo.spare_hours')),
                'demo_claimed_at' => $claimed ? now() : null,
            ]);
            // Made-up addresses on a reserved domain (RFC 2606): nothing could ever be delivered to them.
            // Eight weeks of sessions: plenty for a day's look, and far fewer rows to write than a season.
            $this->sample->build($studio, fn (string $key) => "{$key}@{$token}.example", User::madeIn($studio), busy: true, weeksAhead: 8);

            return $studio;
        });
    }

    /** The cookie that lets this browser come back to its demo and switch between owner and member. */
    public function cookie(array $demo): Cookie
    {
        $minutes = max(1, (int) ceil(now()->diffInMinutes($demo['studio']->demo_expires_at)));

        return cookie(self::COOKIE, implode(':', [$demo['studio']->id, $demo['owner']->id, $demo['member']->id, $demo['run']->id]), $minutes);
    }

    /**
     * The demo this browser started, while it lasts.
     *
     * @return array{studio: Studio, owner: User, member: User, run: ?DemoRun}|null
     */
    public function fromCookie(Request $request): ?array
    {
        $parts = explode(':', (string) $request->cookie(self::COOKIE));
        if (count($parts) !== 4 || ! collect($parts)->every(fn ($p) => ctype_digit($p))) {
            return null;
        }
        [$studioId, $ownerId, $memberId, $runId] = array_map('intval', $parts);
        $studio = Studio::whereKey($studioId)->whereNotNull('demo_claimed_at')->where('demo_expires_at', '>', now())->first();
        $people = $studio ? User::whereIn('id', [$ownerId, $memberId])->where('demo_studio_id', $studio->id)->get()->keyBy('id') : collect();
        if (! $studio || ! $people->has($ownerId) || ! $people->has($memberId) || ! $studio->studioUsers()->where('user_id', $ownerId)->where('role', 'owner')->exists()) {
            return null;
        }

        return ['studio' => $studio, 'owner' => $people[$ownerId], 'member' => $people[$memberId], 'run' => DemoRun::find($runId)];
    }

    /** The demo run a later real signup came from, if this browser tried the demo (even an expired one). */
    public function runFromCookie(Request $request): ?DemoRun
    {
        $parts = explode(':', (string) $request->cookie(self::COOKIE));

        return count($parts) === 4 && ctype_digit($parts[3]) ? DemoRun::whereKey((int) $parts[3])->whereNull('converted_at')->first() : null;
    }

    /**
     * Deletes demo studios whose time is up (spares too), with everything in them, and every
     * account made in a demo (their sessions, app sign-ins and reset links too). Hourly.
     */
    public function cleanup(?Carbon $now = null): int
    {
        $now ??= now();
        $studios = Studio::whereNotNull('demo_expires_at')->where('demo_expires_at', '<=', $now)->get();
        foreach ($studios as $studio) {
            DB::transaction(fn () => $studio->delete()); // cascades: every studio-owned row
            Storage::disk('local')->deleteDirectory("signatures/{$studio->id}");
        }

        User::whereNotNull('demo_expires_at')->where('demo_expires_at', '<=', $now)->select(['id', 'email'])
            ->chunkById(200, function ($users) {
                $ids = $users->pluck('id');
                DB::table((string) config('auth.passwords.users.table'))->whereIn('email', $users->pluck('email'))->delete();
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table'))->whereIn('user_id', $ids)->delete();
                }
                PersonalAccessToken::where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
                try {
                    User::whereIn('id', $ids)->delete();
                } catch (Throwable) {
                    // One of them is still tied to something outside its demo; delete the rest.
                    $users->each(fn (User $u) => rescue(fn () => User::whereKey($u->id)->delete(),
                        fn ($e) => Log::warning('A demo account could not be deleted', ['user_id' => $u->id, 'error' => $e->getMessage()])));
                }
            });

        return $studios->count();
    }

    /** Takes a ready spare, if any. The update only succeeds for one taker. */
    private function claimSpare(): ?Studio
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $id = Studio::whereNotNull('demo_expires_at')->whereNull('demo_claimed_at')->where('demo_expires_at', '>', now()->addHour())->orderBy('id')->value('id');
            if (! $id) {
                return null;
            }
            if (Studio::whereKey($id)->whereNull('demo_claimed_at')->update(['demo_claimed_at' => now()]) === 1) {
                return Studio::find($id);
            }
        }

        return null;
    }

    /** Six lowercase letters and digits, not already a demo's address. */
    private function token(): string
    {
        do {
            $token = strtolower(Str::random(6));
        } while (! ctype_alnum($token) || Studio::where('slug', "demo-{$token}")->exists());

        return $token;
    }
}
