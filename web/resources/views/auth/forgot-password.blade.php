@extends('layouts.platform')

@section('title', 'Reset your password')

@section('content')
<div class="narrow card" style="margin: 0 auto">
    <h1>Reset your password</h1>
    <p class="muted">Enter your email and we’ll send a link to choose a new password.</p>
    <form method="post" action="{{ route('password.email') }}" class="form">
        @csrf
        <x-field name="email" label="Email" type="email" autocomplete="email" required autofocus />
        <button class="btn btn-block">Email me a reset link</button>
    </form>
    <hr>
    <p class="small"><a href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
