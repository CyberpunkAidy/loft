@extends('layouts.platform')

@section('title', 'Start your studio')

@section('content')
<div class="narrow" style="margin: 0 auto">
    <div class="page-head">
        <div>
            <h1>Start your studio</h1>
            <p>Free for {{ $trialDays }} days, no card needed. Then {{ $price }} a month for your studio. You’ll connect Stripe when you’re ready to take payments.</p>
        </div>
    </div>
    <form method="post" action="{{ route('signup') }}" class="form">
        @csrf
        @guest
            <fieldset class="fieldset form">
                <legend>You</legend>
                <div class="grid-2">
                    <x-field name="first_name" label="First name" autocomplete="given-name" required />
                    <x-field name="last_name" label="Last name" autocomplete="family-name" required />
                </div>
                <x-field name="email" label="Email" type="email" autocomplete="email" required />
                <x-field name="password" label="Password" type="password" autocomplete="new-password" minlength="8" required hint="At least 8 characters." />
                <p class="small muted" style="margin:0">Already have an account? <a href="{{ route('login') }}">Sign in first</a>.</p>
            </fieldset>
        @endguest
        <fieldset class="fieldset form">
            <legend>Your studio</legend>
            <x-field name="studio_name" label="Studio name" required data-slug-source placeholder="Skyline Aerial Arts" />
            <label class="field @error('slug') has-error @enderror" for="f-slug">
                <span>Web address</span>
                <span class="prefix"><span>{{ preg_replace('#^https?://#', '', url('/')) }}/</span><input id="f-slug" name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?" minlength="3" maxlength="60" data-slug-target autocapitalize="off" spellcheck="false" placeholder="skyline"></span>
                <small class="hint">Lowercase letters, numbers and dashes. This is the link you’ll share with families.</small>
                @error('slug')<small class="error">{{ $message }}</small>@enderror
            </label>
            <div class="grid-2">
                <x-select name="timezone" label="Time zone" :options="\App\Support\Timezones::options()" value="America/Denver" hint="Every class time uses this." />
                <x-select name="currency" label="Currency" :options="$currencies" value="usd" />
            </div>
        </fieldset>
        <button class="btn btn-block">Create studio</button>
    </form>
</div>
@endsection
