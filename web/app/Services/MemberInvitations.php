<?php

namespace App\Services;

use App\Mail\StudioMessage;
use App\Models\Attendee;
use App\Models\Entitlement;
use App\Models\Studio;
use App\Models\StudioUser;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Telling imported members about Loft (decision 37). Imports are silent: nobody hears anything
 * until staff press Send invitations. Each invitation is studio-branded, lists the family and
 * plans that came across, and links to a page where the member chooses a password: a signed
 * link that lasts 30 days and stops working once used (it carries a fingerprint of the current
 * password). Members who already had a Loft account just sign in.
 *
 * Imported memberships run until their paid-through date with no card behind them, so a week
 * before that the member is asked to start the membership in Loft, where their card is first
 * charged when the old one ends. Only members who have been invited are asked.
 */
class MemberInvitations
{
    public const LINK_DAYS = 30;

    /** Invited members who haven't accepted can be reminded this many days after the last email. */
    public const REMIND_AFTER_DAYS = 3;

    public function __construct(private readonly Messages $messages) {}

    /** @return Builder<StudioUser> imported members not invited yet */
    public function uninvited(Studio $studio): Builder
    {
        return StudioUser::where('studio_id', $studio->id)->where('role', 'member')->whereNotNull('import_id')->whereNull('invited_at');
    }

    /** @return Builder<StudioUser> invited, and not in yet */
    public function waiting(Studio $studio): Builder
    {
        return StudioUser::where('studio_id', $studio->id)->whereNotNull('invited_at')->whereNull('invitation_accepted_at');
    }

    /** @return Builder<StudioUser> waiting, and last emailed long enough ago to remind */
    public function remindable(Studio $studio): Builder
    {
        return $this->waiting($studio)->where('invited_at', '<', now()->subDays(self::REMIND_AFTER_DAYS));
    }

    /**
     * Email each of these members their invitation.
     *
     * @param  Collection<int, StudioUser>  $memberships
     */
    public function send(Studio $studio, Collection $memberships): int
    {
        $sent = 0;
        foreach ($memberships as $membership) {
            $user = $membership->user;
            if (! $user) {
                continue;
            }
            $this->messages->email($user, $this->invitation($studio, $user), "invitation:studio:{$studio->id}:user:{$user->id}:".now()->format('YmdHi'));
            $membership->update(['invited_at' => now()]);
            $sent++;
        }

        return $sent;
    }

    /** An account the import created, whose owner hasn't chosen a password yet. */
    public function needsPassword(User $user): bool
    {
        return $user->import_id !== null
            && ! StudioUser::where('user_id', $user->id)->whereNotNull('invitation_accepted_at')->exists();
    }

    public function invitation(Studio $studio, User $user): StudioMessage
    {
        $newAccount = $this->needsPassword($user);
        $family = Attendee::where('studio_id', $studio->id)->where('user_id', $user->id)->where('status', 'active')
            ->where('is_self', false)->orderBy('first_name')->pluck('first_name');
        $plans = Entitlement::where('studio_id', $studio->id)->where('user_id', $user->id)->where('status', 'active')
            ->with('plan')->orderBy('expires_at')->get();

        $details = [];
        if ($family->isNotEmpty()) {
            $details['Family'] = $family->join(', ', ' and ');
        }
        foreach ($plans as $i => $plan) {
            $until = $plan->expires_at ? 'until '.$studio->local($plan->expires_at)->format('M j, Y') : null;
            $label = $plan->plan?->name ?? 'Plan';
            $details[isset($details[$label]) ? "{$label} ({$i})" : $label] = $plan->isCredits()
                ? trim($plan->credits_remaining.' '.($plan->credits_remaining === 1 ? 'class' : 'classes').' left'.($until ? " · {$until}" : ''))
                : ucfirst((string) $until);
        }

        $after = [];
        if ($plans->contains(fn (Entitlement $e) => $e->import_id && ! $e->isCredits() && ! $e->stripe_subscription_id)) {
            $after[] = 'Your membership carries over until the date above. Before then, choose it under Plans to keep it going: your card is only charged when the current one ends.';
        }

        return new StudioMessage(
            $studio,
            $studio->name.' has a new booking system',
            [
                "{$studio->name} now takes bookings and payments on Loft.",
                $newAccount
                    ? 'Your account is ready. Choose a password to book classes, manage your family and see your plans.'
                    : "Sign in with your Loft account ({$user->email}) to book classes, manage your family and see your plans.",
            ],
            $details,
            $after,
            $newAccount ? $this->welcomeUrl($studio, $user) : route('studio.login', $studio),
            $newAccount ? 'Choose your password' : 'Sign in',
            'invitation',
        );
    }

    /** A signed link to choose a password, which stops working once the password changes. */
    public function welcomeUrl(Studio $studio, User $user): string
    {
        return URL::temporarySignedRoute('studio.welcome', now()->addDays(self::LINK_DAYS), [
            'studio' => $studio,
            'member' => $user->id,
            'v' => self::fingerprint($user),
        ]);
    }

    public static function fingerprint(User $user): string
    {
        return substr(hash('sha256', (string) $user->password), 0, 16);
    }

    /** They chose a password from the invitation. */
    public function accepted(Studio $studio, User $user): void
    {
        StudioUser::where('studio_id', $studio->id)->where('user_id', $user->id)->whereNull('invitation_accepted_at')
            ->update(['invitation_accepted_at' => now()]);
    }

    /* ------------------------------------------------------------------ Imported memberships */

    /**
     * Hourly: a week before an imported membership runs out, ask the member to start it in Loft.
     * Once per membership; not before they've been invited; not once they've started it.
     */
    public function remindRenewals(): int
    {
        $sent = 0;
        Entitlement::query()
            ->whereNotNull('import_id')
            ->where('kind', 'subscription')
            ->where('status', 'active')
            ->whereNull('stripe_subscription_id')
            ->whereBetween('expires_at', [now(), now()->addDays(7)])
            ->with(['studio', 'user', 'plan'])
            ->each(function (Entitlement $entitlement) use (&$sent) {
                $studio = $entitlement->studio;
                $plan = $entitlement->plan;
                $membership = StudioUser::where('studio_id', $studio->id)->where('user_id', $entitlement->user_id)->first();
                if (! $membership || ! $plan || ($membership->import_id && ! $membership->invited_at)) {
                    return;
                }
                if (! $plan->active || ! $plan->isStripeReady() || ! $studio->stripeReady() || $studio->isPaused()) {
                    return; // nothing they could do about it online
                }
                $continued = Entitlement::where('user_id', $entitlement->user_id)->where('plan_id', $plan->id)
                    ->whereNotNull('stripe_subscription_id')->whereIn('status', ['active', 'past_due'])->exists();
                if ($continued) {
                    return;
                }
                if ($this->messages->email($entitlement->user, $this->renewal($entitlement), "renewal:entitlement:{$entitlement->id}")) {
                    $sent++;
                }
            });

        return $sent;
    }

    public function renewal(Entitlement $entitlement): StudioMessage
    {
        $studio = $entitlement->studio;
        $plan = $entitlement->plan;
        $date = $studio->local($entitlement->expires_at)->format('F j');
        $later = $entitlement->expires_at->gt(now()->addHours(49));

        return new StudioMessage(
            $studio,
            "Keep your {$plan->name} going",
            [
                "Your {$plan->name} at {$studio->name} runs until {$date}.",
                $later
                    ? "To keep it going, choose it under Plans before then. Your card is first charged on {$date}, when this one ends."
                    : 'To keep it going, choose it under Plans. Your card is charged when you do.',
            ],
            [],
            [],
            route('studio.plans', $studio).'#plan-'.$plan->id,
            'Keep my membership',
            'renewal_needed',
        );
    }

    /** The imported membership a purchase of $plan would continue, if it runs long enough to start billing when it ends. */
    public function continuation(Studio $studio, int $userId, int $planId): ?Entitlement
    {
        return Entitlement::where('studio_id', $studio->id)->where('user_id', $userId)->where('plan_id', $planId)
            ->whereNotNull('import_id')->whereNull('stripe_subscription_id')->where('kind', 'subscription')->where('status', 'active')
            ->where('expires_at', '>', now())->orderByDesc('expires_at')->first();
    }
}
