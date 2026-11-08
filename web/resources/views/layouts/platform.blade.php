@extends('layouts.base')

@section('body-class', 'platform')

@section('body')
<header class="site-header">
    <div class="container">
        <a class="brand" href="{{ route('home') }}"><x-logo /></a>
        <nav class="nav" aria-label="Main">
            @auth
                <a href="{{ route('studios') }}" @class(['active' => request()->routeIs('studios')])>My studios</a>
                <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="link">Sign out</button></form>
            @else
                <a href="{{ route('login') }}" @class(['active' => request()->routeIs('login')])>Sign in</a>
                <a class="btn btn-small" href="{{ route('signup') }}">Start your studio</a>
            @endauth
        </nav>
    </div>
</header>
<main class="site-main">
    <div class="container">
        @include('partials.flash')
        @yield('content')
    </div>
</main>
<footer class="site-footer">
    <div class="container">
        <a class="powered" href="{{ route('home') }}"><x-logo /></a>
        <span>Scheduling, memberships, waivers and billing for studios.</span>
        <span>© {{ date('Y') }} {{ config('app.name') }}</span>
    </div>
</footer>
@endsection
