@extends('layouts.base')

@section('body')
@php
    $me = auth()->user();
    $isMember = $me && $me->hasRoleIn($studio);
    $isStaff = $me && $me->hasRoleIn($studio, 'instructor');
@endphp
<header class="site-header">
    <div class="container">
        <a class="brand" href="{{ route('studio.schedule') }}"><span class="brand-mark" aria-hidden="true">{{ $studio->initial() }}</span> {{ $studio->name }}</a>
        <nav class="nav" aria-label="Studio">
            <a href="{{ route('studio.schedule') }}" @class(['active' => request()->routeIs('studio.schedule', 'studio.program', 'studio.book')])>Schedule</a>
            <a href="{{ route('studio.plans') }}" @class(['active' => request()->routeIs('studio.plans')])>Plans</a>
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
        @yield('content')
    </div>
</main>
<footer class="site-footer">
    <div class="container">
        <span><strong>{{ $studio->name }}</strong>@if ($studio->contact_email) · <a href="mailto:{{ $studio->contact_email }}">{{ $studio->contact_email }}</a>@endif</span>
        <a class="powered" href="{{ route('home') }}">Powered by <x-logo /></a>
    </div>
</footer>
@endsection
