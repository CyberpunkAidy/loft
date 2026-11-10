@extends('layouts.base')

@section('body')
@php
    $me = auth()->user();
    $isMember = $me && $me->hasRoleIn($studio);
    $isStaff = $me && $me->hasRoleIn($studio, 'instructor');
@endphp
<header class="site-header">
    <div class="container">
        <a class="brand" href="{{ route('studio.schedule') }}"><x-studio-brand :studio="$studio" /></a>
        <nav class="nav" aria-label="Studio">
            <a href="{{ route('studio.schedule') }}" @class(['active' => request()->routeIs('studio.schedule', 'studio.program', 'studio.book')])>Schedule</a>
            <a href="{{ route('studio.plans') }}" @class(['active' => request()->routeIs('studio.plans')])>Plans</a>
            @if ($studio->videos()->inLibrary()->exists())
                <a href="{{ route('studio.videos') }}" @class(['active' => request()->routeIs('studio.videos*')])>Videos</a>
            @endif
            @if ($studio->products()->where('active', true)->exists())
                <a href="{{ route('studio.shop') }}" @class(['active' => request()->routeIs('studio.shop*')])>Shop</a>
            @endif
            @if ($isMember)
                <a href="{{ route('studio.account') }}" @class(['active' => request()->routeIs('studio.account*', 'studio.attendees.*', 'studio.waiver')])>My account</a>
            @endif
            @if ($isStaff)
                <a href="{{ route('studio.admin.dashboard') }}">Studio admin</a>
            @endif
            @if ($me)
                <form method="post" action="{{ route('logout') }}" class="inline">@csrf<input type="hidden" name="return" value="{{ route('studio.schedule', [], false) }}"><button class="link">Sign out</button></form>
                @unless ($isMember)
                    <a class="btn btn-small" href="{{ route('studio.join') }}">Join</a>
                @endunless
            @else
                <a href="{{ route('studio.login') }}" @class(['active' => request()->routeIs('studio.login')])>Sign in</a>
                <a class="btn btn-small" href="{{ route('studio.join') }}">Join</a>
            @endif
        </nav>
    </div>
</header>
<main class="site-main">
    <div class="container">
        @include('partials.flash')
        @if ($studio->isPaused())
            <div class="alert alert-info" role="status">{{ $studio->name }} isn’t taking online bookings or payments right now. Please contact the studio.</div>
        @endif
        @yield('content')
    </div>
</main>
<footer class="site-footer">
    <div class="container">
        <span><strong>{{ $studio->name }}</strong>@if ($studio->contact_email) · <a href="mailto:{{ $studio->contact_email }}">{{ $studio->contact_email }}</a>@endif</span>
        <span class="legal-links"><a href="{{ route('terms') }}">Terms</a><a href="{{ route('privacy') }}">Privacy</a><a class="powered" href="{{ route('home') }}">Powered by <x-logo /></a></span>
    </div>
</footer>
@endsection
