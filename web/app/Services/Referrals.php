<?php

namespace App\Services;

use App\Mail\LoftNotice;
use App\Models\ReferralCode;
use App\Models\Studio;
use App\Models\User;
use App\Services\Stripe\StripeClient;
use App\Support\BillingState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Referral codes (decision 43). Every studio has a code it can share as loftstudio.app/r/{code};
 * partner codes are made with `loft:partner-code`. A studio that joins with a code gets
 * bonusDays() more free days. When it first pays Loft, the studio whose code it was gets a month
 * off its Loft bill (a credit on its Stripe customer), or bonusDays() more trial if it hasn't
 * subscribed. Comped studios and partners get nothing automatic; partners are rewarded by hand.
 * With bonusDays() at 0, rewards are off: codes are still recorded, for the report.
 */
class Referrals
{
    /** Carries the code from a referral link to the signup form, for this many days. */
    public const COOKIE = 'loft_ref';

    public const COOKIE_DAYS = 30;

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly StudioBilling $billing,
        private readonly Messages $messages,
    ) {}

    public function bonusDays(): int
    {
        return max(0, (int) config('loft.referrals.bonus_days'));
    }

    /** The studio's own code, made the first time it's needed: SKYLINE for skyline-aerial. */
    public function codeFor(Studio $studio): ReferralCode
    {
        if ($code = $studio->ownReferralCode) {
            return $code;
        }
        $base = self::baseCode($studio->slug);
        $candidates = [$base, ...array_map(fn ($n) => $base.$n, range(2, 99))];
        $taken = ReferralCode::whereIn('code', $candidates)->pluck('code')->all();
        $code = collect($candidates)->first(fn ($c) => ! in_array($c, $taken, true)) ?? $base.strtoupper(Str::random(4));

        return $studio->setRelation('ownReferralCode', ReferralCode::createOrFirst(['studio_id' => $studio->id], ['code' => $code]))->ownReferralCode;
    }

    /** A readable code from a studio's address: its first word, or the first few joined up to 6+ letters. */
    public static function baseCode(string $slug): string
    {
        $parts = array_values(array_filter(explode('-', strtoupper($slug))));
        $code = array_shift($parts) ?? '';
        while (strlen($code) < 6 && $parts) {
            $code .= array_shift($parts);
        }

        return substr(preg_replace('/[^A-Z0-9]/', '', $code), 0, 12) ?: 'LOFT';
    }

    /** What someone typed or pasted, as a code: "skyline", " Skyline ", or the whole link. */
    public static function normalize(?string $input): string
    {
        $input = trim((string) $input);
        if (str_contains($input, '/')) {
            $input = Str::afterLast(rtrim($input, '/'), '/');
        }

        return strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $input), 0, 20));
    }

    /** A code that can be used now, or null. */
    public function find(?string $input): ?ReferralCode
    {
        $code = self::normalize($input);

        return $code === '' ? null : ReferralCode::with('studio')->where('code', $code)->whereNull('disabled_at')->first();
    }

    /** The code from a referral link this browser followed, if it's still good. */
    public function fromCookie(Request $request): ?ReferralCode
    {
        $value = $request->cookie(self::COOKIE);

        return is_string($value) ? $this->find($value) : null;
    }

    /** Why $user can't start a studio with $code, or null if they can. */
    public function refusal(ReferralCode $code, ?User $user): ?string
    {
        // Owners and admins of the referring studio; its members and instructors opening their own studio are real referrals.
        if ($user && $code->studio && $user->hasRoleIn($code->studio, 'admin')) {
            return 'That’s your own studio’s code. Codes are for studios you introduce to Loft.';
        }

        return null;
    }

    /** One month of what the studio pays Loft: its locked price, or a twelfth of a yearly one. */
    public function monthCents(Studio $studio): int
    {
        $amount = $studio->billing_amount_cents ?: (int) config('loft.billing.monthly_cents');

        return $studio->billing_interval === 'year' ? (int) round($amount / 12) : $amount;
    }

    /**
     * The referrer's reward, once, when $referred first pays Loft (an invoice for more than nothing).
     * A retried webhook finds it done; a Stripe error rolls it back so the retry tries again.
     */
    public function reward(Studio $referred): void
    {
        if (! $referred->referral_code_id || $referred->referral_rewarded_at) {
            return;
        }
        $done = DB::transaction(function () use ($referred) {
            $studio = Studio::whereKey($referred->id)->lockForUpdate()->first();
            $code = $studio?->referralCode;
            if (! $studio || $studio->referral_rewarded_at || ! $code) {
                return null;
            }
            $referrer = $code->studio;
            [$reward, $cents] = match (true) {
                $referrer === null => ['partner', null],
                $this->bonusDays() === 0, // referral rewards are off
                $this->billing->state($referrer)->status === BillingState::COMPED => ['none', null],
                (bool) $referrer->billing_customer_id => ['credit', $this->monthCents($referrer)],
                default => ['trial', null],
            };
            if ($reward === 'credit') {
                $this->stripe->creditBillingCustomer($referrer->billing_customer_id, $cents, (string) config('loft.billing.currency'),
                    "A free month: {$studio->name} joined Loft with your referral code", 'loft-referral-'.$studio->id);
            } elseif ($reward === 'trial') {
                $referrer->update(['trial_ends_at' => $this->billing->trialEndsAt($referrer)->copy()->max(now())->addDays($this->bonusDays())]);
            }
            $studio->update(['referral_rewarded_at' => now(), 'referral_reward' => $reward, 'referral_reward_cents' => $cents]);

            return in_array($reward, ['credit', 'trial'], true) ? [$referrer, $studio] : null;
        });

        if ($done) {
            [$referrer, $studio] = $done;
            foreach ($this->billing->owners($referrer) as $owner) {
                $this->messages->email($owner, $this->notice($referrer, $studio), "referral_reward:studio:{$studio->id}:user:{$owner->id}");
            }
        }
    }

    /** The thank-you email to the referring studio's owners. */
    public function notice(Studio $referrer, Studio $referred): LoftNotice
    {
        $credit = $referred->referral_reward === 'credit';
        $lines = [
            "{$referred->name} joined Loft with {$referrer->name}’s referral code and has made its first payment. Thank you for spreading the word.",
            $credit
                ? 'We’ve taken '.$this->billing->label((int) $referred->referral_reward_cents).' off '.$referrer->name.'’s next Loft bill. It comes off automatically; there’s nothing to do.'
                : 'We’ve added '.$this->bonusDays().' days to '.$referrer->name.'’s free trial. It now ends on '.$referrer->local($this->billing->trialEndsAt($referrer))->format('F j').'.',
            'Every studio that joins with your code gets a month free too.',
        ];

        return new LoftNotice($referrer, $credit ? 'You’ve earned a free month of Loft' : 'You’ve earned '.$this->bonusDays().' more free days of Loft',
            $lines, [], [], route('studio.admin.subscription', $referrer).'#refer', 'See your referrals', 'referral_reward');
    }
}
