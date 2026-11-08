@extends($studio ? 'layouts.studio' : 'layouts.platform')

@section('title', 'Sign in')

@section('content')
<div class="card auth-card auth">
    <h1>Sign in</h1>
    <p class="muted">{{ $studio ? 'Your classes, family, waivers and billing at '.$studio->name.'.' : 'One account for every studio you belong to.' }}</p>
    <form method="post" action="{{ route('login') }}" class="form">
        @csrf
        @if ($studio)<input type="hidden" name="studio" value="{{ $studio->slug }}">@endif
        <x-field name="email" label="Email" type="email" autocomplete="email" required autofocus />
        <x-field name="password" label="Password" type="password" autocomplete="current-password" required />
        <label class="check"><input type="checkbox" name="remember" value="1" checked> <span>Keep me signed in</span></label>
        <button class="btn btn-block">Sign in</button>
    </form>
    <hr>
    <div class="row between small">
        <a href="{{ route('password.request') }}">Forgot your password?</a>
        @if ($studio)
            <span>New here? <a href="{{ route('studio.join') }}">Create an account</a></span>
        @else
            <span>New studio? <a href="{{ route('signup') }}">Start here</a></span>
        @endif
    </div>
</div>
@endsection
