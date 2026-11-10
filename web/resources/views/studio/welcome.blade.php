@extends('layouts.studio')

@section('title', 'Welcome')

@section('content')
<div class="card auth-card auth">
    <h1>Welcome to {{ $studio->name }}</h1>
    <p class="muted">{{ $studio->name }} now takes bookings and payments here. Choose a password for <strong>{{ $member->email }}</strong> to book classes, manage your family and see your plans.</p>
    <form method="post" action="{{ request()->fullUrl() }}" class="form">
        @csrf
        <x-field name="password" label="Password" type="password" autocomplete="new-password" minlength="8" required hint="At least 8 characters." />
        <x-field name="password_confirmation" label="Password again" type="password" autocomplete="new-password" minlength="8" required />
        <button class="btn btn-block">Choose password</button>
    </form>
    <hr>
    <p class="small" style="margin:0">It’s one login for every studio on {{ config('app.name') }}.</p>
</div>
@endsection
