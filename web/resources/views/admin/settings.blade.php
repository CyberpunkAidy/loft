@extends('layouts.admin')

@section('title', 'Settings')

@section('content')
<h1>Settings</h1>
<form method="post" action="{{ route('studio.admin.settings') }}" class="form" style="max-width: 760px">
    @csrf @method('put')
    <fieldset class="fieldset form">
        <legend>Studio</legend>
        <x-field name="name" label="Studio name" :value="$studio->name" required />
        <div class="grid-2">
            <x-field name="accent_color" label="Brand color" type="color" :value="$studio->accent()" />
            <x-select name="currency" label="Currency" :options="$currencies" :value="$studio->currency" :disabled="$currencyLocked" :hint="$currencyLocked ? 'Locked: plans already have Stripe prices.' : null" />
        </div>
        <x-select name="timezone" label="Time zone" :options="\App\Support\Timezones::options()" :value="$studio->timezone" hint="Changing it doesn’t move existing sessions; re-save programs to regenerate them." />
        <p class="small muted" style="margin:0">Your schedule link: <code>{{ route('studio.schedule') }}</code></p>
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Email</legend>
        <div class="grid-2">
            <x-field name="email_from_name" label="From name" :value="$studio->email_from_name" :placeholder="$studio->name" />
            <x-field name="contact_email" label="Replies go to" type="email" :value="$studio->contact_email" />
        </div>
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Booking policy</legend>
        <div class="grid-2">
            <x-field name="booking_opens_days_before" label="Booking opens (days before class)" type="number" min="0" :value="$settings['booking_opens_days_before']" hint="0 = any time." />
            <x-field name="booking_closes_minutes_before" label="Booking closes (minutes before class)" type="number" min="0" :value="$settings['booking_closes_minutes_before']" hint="0 = until class starts." />
            <x-field name="late_cancel_hours" label="Late-cancel window (hours)" type="number" min="0" :value="$settings['late_cancel_hours']" />
            <x-field name="reminder_hours_before" label="Reminder email (hours before)" type="number" min="0" :value="$settings['reminder_hours_before']" hint="0 = no reminders." />
            <x-field name="credit_validity_days" label="Default credit expiry (days)" type="number" min="0" :value="$settings['credit_validity_days']" hint="0 = never." />
            <x-field name="waiver_validity_days" label="Waiver valid for (days)" type="number" min="0" :value="$settings['waiver_validity_days']" hint="0 = until a new version." />
            <x-field name="minor_age" label="Minors are under" type="number" min="1" max="25" :value="$settings['minor_age']" />
            <x-field name="schedule_weeks" label="Weeks shown on the schedule" type="number" min="1" max="4" :value="$settings['schedule_weeks']" />
        </div>
        <x-checkbox name="late_cancel_keeps_credit" label="Return the credit even on late cancels" :checked="$settings['late_cancel_keeps_credit']" />
        <x-checkbox name="no_show_consumes_credit" label="No-shows use their credit" :checked="$settings['no_show_consumes_credit']" hint="Untick to hand the credit back when you mark a no-show." />
        <x-checkbox name="waitlist_auto_promote" label="Move the next person off the waitlist automatically when a spot opens" :checked="$settings['waitlist_auto_promote']" />
    </fieldset>

    <fieldset class="fieldset form">
        <legend>Merchandise</legend>
        <x-field name="sales_tax_percent" label="Sales tax on products (%)" type="number" min="0" max="30" step="0.001" :value="rtrim(rtrim(number_format(($settings['sales_tax_milli'] ?? 0) / 1000, 3, '.', ''), '0'), '.')" hint="Added at the point of sale to products marked taxable. Classes and plans aren’t taxed. Check the rate for your area; 0 for none." />
    </fieldset>
    <div class="actions"><button class="btn">Save settings</button></div>
</form>
@endsection
