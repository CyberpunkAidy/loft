<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Auth\StudioSignupController;
use App\Http\Controllers\Controller;
use App\Models\Studio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Studio details and booking policy (the plugin's Settings page, minus Stripe keys). */
class SettingsController extends Controller
{
    public function edit(Studio $studio): View
    {
        return view('admin.settings', [
            'settings' => $studio->allSettings(),
            'currencies' => StudioSignupController::CURRENCIES,
            'currencyLocked' => $studio->plans()->whereNotNull('stripe_price_id')->exists(),
        ]);
    }

    public function update(Request $request, Studio $studio): RedirectResponse
    {
        $currencyLocked = $studio->plans()->whereNotNull('stripe_price_id')->exists();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => [$currencyLocked ? 'nullable' : 'required', Rule::in(array_keys(StudioSignupController::CURRENCIES))],
            'contact_email' => ['nullable', 'email', 'max:190'],
            'email_from_name' => ['nullable', 'string', 'max:120'],
            'late_cancel_hours' => ['required', 'integer', 'between:0,168'],
            'booking_opens_days_before' => ['required', 'integer', 'between:0,365'],
            'booking_closes_minutes_before' => ['required', 'integer', 'between:0,1440'],
            'credit_validity_days' => ['required', 'integer', 'between:0,3650'],
            'waiver_validity_days' => ['required', 'integer', 'between:0,3650'],
            'reminder_hours_before' => ['required', 'integer', 'between:0,168'],
            'minor_age' => ['required', 'integer', 'between:1,25'],
            'schedule_weeks' => ['required', 'integer', 'between:1,4'],
            'sales_tax_percent' => ['nullable', 'numeric', 'between:0,30', 'decimal:0,3'],
        ]);

        $settings = [];
        foreach (Studio::DEFAULT_SETTINGS as $key => $default) {
            $settings[$key] = is_bool($default) ? $request->boolean($key) : (int) ($data[$key] ?? $default);
        }
        $settings['sales_tax_milli'] = (int) round(1000 * (float) ($data['sales_tax_percent'] ?? 0));

        $studio->update([
            'name' => $data['name'],
            'accent_color' => strtolower($data['accent_color']),
            'timezone' => $data['timezone'],
            'currency' => $currencyLocked ? $studio->currency : $data['currency'],
            'contact_email' => $data['contact_email'] ?? null,
            'email_from_name' => $data['email_from_name'] ?? null,
            'settings' => $settings,
        ]);

        return back()->with('success', 'Settings saved.');
    }
}
