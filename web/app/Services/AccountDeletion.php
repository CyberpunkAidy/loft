<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Deletes a user's account at every studio. The schema cascades from users, so their memberships,
 * family members, passes, bookings and signed waivers go with it; classes they taught stay, without
 * an instructor. Payment history stays in each studio's Stripe account.
 *
 * A studio the user is the only owner of can't be left without one: they either hand it to one of
 * its staff, or close it, which deletes the studio and everything in it.
 */
class AccountDeletion
{
    public const CLOSE = 'close';

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly Bookings $bookings,
    ) {}

    /**
     * Studios the user is the only owner of.
     *
     * @return Collection<int, Studio>
     */
    public function soleOwnerOf(User $user): Collection
    {
        return Studio::query()
            ->whereHas('studioUsers', fn ($q) => $q->where('user_id', $user->id)->where('role', 'owner'))
            ->whereDoesntHave('studioUsers', fn ($q) => $q->where('user_id', '!=', $user->id)->where('role', 'owner'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Staff who can take a studio over: its admins, then its instructors.
     *
     * @return Collection<int, StudioUser>
     */
    public function successorsFor(Studio $studio, User $user): Collection
    {
        return $studio->studioUsers()->with('user')
            ->whereIn('role', ['admin', 'instructor'])
            ->where('user_id', '!=', $user->id)
            ->get()
            ->sortBy(fn (StudioUser $s) => [-StudioUser::rank($s->role), $s->user->name])
            ->values();
    }

    /**
     * @param  array<int|string, int|string>  $studios  for each studio the user is the only owner of:
     *                                                  the user id of its new owner, or self::CLOSE
     *
     * @throws InvalidArgumentException when a studio they're the only owner of has no valid choice
     * @throws StripeException when a renewing membership can't be cancelled; nothing is deleted then
     */
    public function delete(User $user, array $studios = []): void
    {
        [$successors, $closing] = $this->resolveStudios($user, $studios);

        // Stop billing before anything else: nobody may be charged for an account or studio that's gone.
        $this->cancelSubscriptions(Entitlement::where('user_id', $user->id));
        foreach ($closing as $studio) {
            $this->cancelSubscriptions(Entitlement::where('studio_id', $studio->id));
        }

        // Seats they hold in upcoming classes, at studios that stay open, go to the waitlist.
        $sessions = ClassSession::query()
            ->whereIn('id', Booking::where('user_id', $user->id)->where('status', 'booked')->select('class_session_id'))
            ->whereNotIn('studio_id', $closing->pluck('id'))
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->get();
        $signatureFiles = WaiverSignature::query()
            ->whereNotNull('signature_path')
            ->where(fn ($q) => $q->where('signed_by_user_id', $user->id)
                ->orWhereIn('attendee_id', Attendee::where('user_id', $user->id)->select('id')))
            ->pluck('signature_path');

        DB::transaction(function () use ($user, $successors, $closing) {
            foreach ($successors as $studioId => $userId) {
                StudioUser::where('studio_id', $studioId)->where('user_id', $userId)->update(['role' => 'owner']);
            }
            $closing->each->delete(); // cascades: every studio-owned row
            DB::table((string) config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table'))->where('user_id', $user->id)->delete(); // signed in elsewhere
            }
            $user->delete();
        });

        $disk = Storage::disk('local');
        $disk->delete($signatureFiles->all());
        $closing->each(fn (Studio $studio) => $disk->deleteDirectory("signatures/{$studio->id}"));
        foreach ($sessions as $session) {
            rescue(fn () => $this->bookings->promoteWaitlist($session)); // the account is gone either way
        }

        Log::info('Account deleted', [
            'user_id' => $user->id,
            'studios_handed_over' => array_keys($successors),
            'studios_closed' => $closing->pluck('id')->all(),
        ]);
    }

    /**
     * @param  array<int|string, int|string>  $choices
     * @return array{0: array<int, int>, 1: Collection<int, Studio>} new owner per studio id, and studios to close
     */
    private function resolveStudios(User $user, array $choices): array
    {
        $successors = [];
        $closing = collect();
        foreach ($this->soleOwnerOf($user) as $studio) {
            $choice = (string) ($choices[$studio->id] ?? '');
            if ($choice === self::CLOSE) {
                $closing->push($studio);

                continue;
            }
            $successor = $this->successorsFor($studio, $user)->firstWhere('user_id', (int) $choice);
            if ($choice === '' || ! $successor) {
                throw new InvalidArgumentException("Choose who takes over {$studio->name}, or close it.");
            }
            $successors[$studio->id] = $successor->user_id;
        }

        return [$successors, $closing];
    }

    /** @param  Builder<Entitlement>  $entitlements */
    private function cancelSubscriptions(Builder $entitlements): void
    {
        $live = $entitlements->with('studio')
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('status', ['active', 'past_due'])
            ->get();

        foreach ($live as $entitlement) {
            if (! $entitlement->studio->stripe_account_id) {
                continue; // the studio disconnected Stripe, so there's no account to reach it through
            }
            $this->stripe->cancelSubscription($entitlement->studio, $entitlement->stripe_subscription_id);
            $entitlement->update(['status' => 'cancelled', 'expires_at' => now()]);
        }
    }
}
