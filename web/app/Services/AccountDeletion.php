<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\Booking;
use App\Models\ClassSession;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Services\Stripe\StripeClient;
use App\Services\Stripe\StripeException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Deletes a user's account at every studio. The schema cascades from users, so their memberships,
 * family members, passes, bookings and signed waivers go with it; classes they taught stay, without
 * an instructor. Payment history stays in each studio's Stripe account.
 */
class AccountDeletion
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly Bookings $bookings,
    ) {}

    /**
     * Studios the user is the only owner of. Deleting the account would leave them without one,
     * so they have to make someone else an owner first.
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
     * @throws StripeException when a renewing membership can't be cancelled; the account is kept then
     */
    public function delete(User $user): void
    {
        if ($this->soleOwnerOf($user)->isNotEmpty()) {
            throw new RuntimeException('The only owner of a studio can’t delete their account.');
        }

        // Stop billing before anything else: a deleted account must not be charged again.
        $this->cancelSubscriptions($user);

        // Seats they hold in upcoming classes go to the waitlist once they're gone.
        $sessions = ClassSession::query()
            ->whereIn('id', Booking::where('user_id', $user->id)->where('status', 'booked')->select('class_session_id'))
            ->where('status', 'scheduled')
            ->where('starts_at', '>', now())
            ->get();
        $signatureFiles = WaiverSignature::query()
            ->whereNotNull('signature_path')
            ->where(fn ($q) => $q->where('signed_by_user_id', $user->id)
                ->orWhereIn('attendee_id', Attendee::where('user_id', $user->id)->select('id')))
            ->pluck('signature_path');

        DB::transaction(function () use ($user) {
            DB::table((string) config('auth.passwords.users.table'))->where('email', $user->email)->delete();
            if (config('session.driver') === 'database') {
                DB::table((string) config('session.table'))->where('user_id', $user->id)->delete(); // signed in elsewhere
            }
            $user->delete();
        });

        Storage::disk('local')->delete($signatureFiles->all());
        foreach ($sessions as $session) {
            rescue(fn () => $this->bookings->promoteWaitlist($session)); // the account is gone either way
        }

        Log::info('Account deleted', ['user_id' => $user->id]);
    }

    private function cancelSubscriptions(User $user): void
    {
        $entitlements = Entitlement::with('studio')
            ->where('user_id', $user->id)
            ->whereNotNull('stripe_subscription_id')
            ->whereIn('status', ['active', 'past_due'])
            ->get();

        foreach ($entitlements as $entitlement) {
            if (! $entitlement->studio->stripe_account_id) {
                continue; // the studio disconnected Stripe, so there's no account to reach it through
            }
            $this->stripe->cancelSubscription($entitlement->studio, $entitlement->stripe_subscription_id);
            $entitlement->update(['status' => 'cancelled', 'expires_at' => now()]);
        }
    }
}
