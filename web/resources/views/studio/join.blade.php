@extends('layouts.studio')

@section('title', 'Join')

@section('content')
<div class="card auth-card auth">
    @auth
        <h1>Join {{ $studio->name }}</h1>
        <p>You’re signed in as <strong>{{ auth()->user()->name }}</strong> ({{ auth()->user()->email }}). Join to book classes, add your family and buy plans.</p>
        <form method="post" action="{{ route('studio.join') }}" class="form">
            @csrf
            <x-checkbox name="marketing_email" :label="'Email me news and offers from '.$studio->name" hint="Optional. Emails about your bookings and payments come either way." />
            <button class="btn btn-block">Join {{ $studio->name }}</button>
        </form>
    @else
        <h1>Create your account</h1>
        <p class="muted">Book classes for yourself or your family at {{ $studio->name }}.</p>
        <form method="post" action="{{ route('studio.join') }}" class="form">
            @csrf
            <div class="grid-2">
                <x-field name="first_name" label="First name" autocomplete="given-name" required />
                <x-field name="last_name" label="Last name" autocomplete="family-name" required />
            </div>
            <x-field name="email" label="Email" type="email" autocomplete="email" required />
            <x-field name="phone" label="Mobile phone" type="tel" autocomplete="tel" />
            <x-field name="password" label="Password" type="password" autocomplete="new-password" minlength="8" required hint="At least 8 characters." />
            <x-checkbox name="is_student" label="I’ll be taking classes myself" hint="Leave unticked if you’re only booking for children." />
            <x-checkbox name="marketing_email" :label="'Email me news and offers from '.$studio->name" hint="Optional. Emails about your bookings and payments come either way." />
            <div class="hp" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
            <p class="small muted" style="margin:0">By creating an account, you agree to Loft’s <a href="{{ route('terms') }}" target="_blank">Terms of Service</a> and <a href="{{ route('privacy') }}" target="_blank">Privacy Policy</a>. {{ $studio->name }}’s own policies apply to your bookings and purchases.</p>
            <button class="btn btn-block">Create account</button>
        </form>
        <hr>
        <p class="small">Already have an account? <a href="{{ route('studio.login') }}">Sign in</a></p>
    @endauth
</div>
@endsection
