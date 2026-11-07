@extends('layouts.base')

@section('body')
<header class="site-header">
    <div class="container">
        <a class="brand" href="{{ route('home') }}"><span class="brand-mark" aria-hidden="true">{{ mb_substr(config('app.name'), 0, 1) }}</span> {{ config('app.name') }}</a>
        <nav class="nav" aria-label="Main">
            @auth
                <a href="{{ route('studios') }}">My studios</a>
                <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="link">Sign out</button></form>
            @else
                <a href="{{ route('login') }}">Sign in</a>
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
    <div class="container row between">
        <span>© {{ date('Y') }} {{ config('app.name') }}</span>
        <span>Scheduling, memberships, waivers and billing for studios.</span>
    </div>
</footer>
@endsection
