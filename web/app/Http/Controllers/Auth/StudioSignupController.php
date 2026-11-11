<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\ReferralCode;
use App\Models\Studio;
use App\Models\User;
use App\Services\Demo\DemoStudios;
use App\Services\Referrals;
use App\Services\StudioBilling;
use App\Support\SignupSource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * Start a studio: creates the owner's account (unless signed in) and the studio. Records how the
 * owner heard about Loft and where the visit began, and takes a referral code (decision 43).
 */
class StudioSignupController extends Controller
{
    public const CURRENCIES = ['usd' => 'USD', 'cad' => 'CAD', 'gbp' => 'GBP', 'eur' => 'EUR', 'aud' => 'AUD', 'nzd' => 'NZD'];

    public function create(Request $request, StudioBilling $billing, Referrals $referrals): View|RedirectResponse
    {
        if ($request->user()?->isDemo()) {
            return $this->leaveDemo($request); // a real studio needs a real account
        }
        $invite = $referrals->fromCookie($request);
        if ($invite && $referrals->refusal($invite, $request->user())) {
            $invite = null; // their own studio's link
        }

        return view('auth.signup', [
            'currencies' => self::CURRENCIES,
            'trialDays' => $billing->trialDays(),
            'bonusDays' => $referrals->bonusDays(),
            'price' => $billing->priceLabel(),
            'invite' => $invite,
            'heardFrom' => SignupSource::HEARD_FROM,
        ]);
    }

    public function store(Request $request, Referrals $referrals, DemoStudios $demos): RedirectResponse
    {
        if ($request->user()?->isDemo()) {
            return $this->leaveDemo($request);
        }
        /** @var ReferralCode|null $code */
        $code = null;
        $rules = [
            'studio_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:studios,slug', Rule::notIn(config('loft.reserved_slugs'))],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(array_keys(self::CURRENCIES))],
            'heard_from' => ['required', Rule::in(array_keys(SignupSource::HEARD_FROM))],
            'heard_from_detail' => ['nullable', 'string', 'max:200'],
            'referral_code' => ['nullable', 'string', 'max:200', function (string $attribute, mixed $value, \Closure $fail) use (&$code, $referrals, $request) {
                if (! ($code = $referrals->find(is_string($value) ? $value : null))) {
                    $fail('We don’t recognise that code. Check it, or leave it blank.');
                } elseif ($why = $referrals->refusal($code, $request->user())) {
                    $code = null;
                    $fail($why);
                }
            }],
            'terms' => ['accepted'],
        ];
        if (! $request->user()) {
            $rules += [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name' => ['required', 'string', 'max:100'],
                'email' => ['required', 'email', 'max:190', 'unique:users,email'],
                'password' => ['required', Password::min(8)],
            ];
        }
        $data = $request->validate($rules, [
            'slug.regex' => 'Use lowercase letters, numbers and dashes.',
            'slug.unique' => 'That address is taken. Try another.',
            'slug.not_in' => 'That address is reserved. Try another.',
            'email.unique' => 'That email already has an account. Sign in first, then start your studio.',
            'terms.accepted' => 'Agree to the Terms of Service and Privacy Policy to start your studio.',
            'heard_from.required' => 'Choose how you heard about Loft. It helps us find more studios like yours.',
            'heard_from.in' => 'Choose how you heard about Loft. It helps us find more studios like yours.',
        ]);
        $trialDays = app(StudioBilling::class)->trialDays() + ($code ? $referrals->bonusDays() : 0);

        $studio = DB::transaction(function () use ($request, $data, $code, $trialDays, $demos) {
            $user = $request->user() ?? User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ] + User::termsAccepted());
            $studio = Studio::create([
                'name' => $data['studio_name'],
                'slug' => $data['slug'],
                'timezone' => $data['timezone'],
                'currency' => $data['currency'],
                'contact_email' => $user->email,
                'email_from_name' => $data['studio_name'],
                'trial_ends_at' => now()->addDays($trialDays),
                'terms_accepted_at' => now(),
                'terms_version' => config('loft.legal.terms_version'),
                'referral_code_id' => $code?->id,
                'heard_from' => $data['heard_from'],
                'heard_from_detail' => trim((string) ($data['heard_from_detail'] ?? '')) ?: null,
                // Whether this browser tried the demo first (decision 44).
                'signup_source' => ($demos->runFromCookie($request) ? ['tried_demo' => true] : []) + (SignupSource::recorded($request) ?? []) ?: null,
            ]);
            $studio->studioUsers()->create(['user_id' => $user->id, 'role' => 'owner']);
            Category::seedDefaults($studio);

            return [$studio, $user];
        });
        [$studio, $user] = $studio;
        $demos->runFromCookie($request)?->update(['studio_id' => $studio->id, 'converted_at' => now()]);

        if (! $request->user()) {
            Auth::login($user, true);
            $request->session()->regenerate();
        }

        return redirect()->route('studio.admin.dashboard', ['studio' => $studio])
            ->with('success', "Welcome to {$studio->name}! Work through the checklist below to start taking bookings.")
            ->withoutCookie(Referrals::COOKIE);
    }

    /** Out of a demo account, back to an empty signup form. */
    private function leaveDemo(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('signup');
    }
}
