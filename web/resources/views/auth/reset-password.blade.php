@extends('layouts.platform')

@section('title', 'Choose a new password')

@section('content')
<div class="narrow card" style="margin: 0 auto">
    <h1>Choose a new password</h1>
    <form method="post" action="{{ route('password.update') }}" class="form">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field name="email" label="Email" type="email" :value="$email" autocomplete="email" required />
        <x-field name="password" label="New password" type="password" autocomplete="new-password" minlength="8" required hint="At least 8 characters." />
        <x-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" required />
        <button class="btn btn-block">Save password</button>
    </form>
</div>
@endsection
