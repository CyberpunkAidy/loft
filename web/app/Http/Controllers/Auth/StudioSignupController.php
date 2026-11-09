<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Studio;
use App\Models\User;
use App\Services\StudioBilling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** Start a studio: creates the owner's account (unless signed in) and the studio. */
class StudioSignupController extends Controller
{
    public const CURRENCIES = ['usd' => 'USD', 'cad' => 'CAD', 'gbp' => 'GBP', 'eur' => 'EUR', 'aud' => 'AUD', 'nzd' => 'NZD'];

    public function create(StudioBilling $billing): View
    {
        return view('auth.signup', ['currencies' => self::CURRENCIES, 'trialDays' => $billing->trialDays(), 'price' => $billing->priceLabel()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rules = [
            'studio_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'unique:studios,slug', Rule::notIn(config('loft.reserved_slugs'))],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::in(array_keys(self::CURRENCIES))],
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
        ]);

        $studio = DB::transaction(function () use ($request, $data) {
            $user = $request->user() ?? User::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => strtolower($data['email']),
                'password' => $data['password'],
            ]);
            $studio = Studio::create([
                'name' => $data['studio_name'],
                'slug' => $data['slug'],
                'timezone' => $data['timezone'],
                'currency' => $data['currency'],
                'contact_email' => $user->email,
                'email_from_name' => $data['studio_name'],
                'trial_ends_at' => now()->addDays(app(StudioBilling::class)->trialDays()),
            ]);
            $studio->studioUsers()->create(['user_id' => $user->id, 'role' => 'owner']);
            Category::seedDefaults($studio);

            return [$studio, $user];
        });
        [$studio, $user] = $studio;

        if (! $request->user()) {
            Auth::login($user, true);
            $request->session()->regenerate();
        }

        return redirect()->route('studio.admin.dashboard', ['studio' => $studio])
            ->with('success', "Welcome to {$studio->name}! Work through the checklist below to start taking bookings.");
    }
}
