@php
    $tabs = [
        'studio.account' => 'Upcoming',
        'studio.account.family' => 'Family',
        'studio.account.billing' => 'Plans & billing',
        'studio.account.waivers' => 'Waivers',
        'studio.account.check-in' => 'Check-in',
        'studio.account.history' => 'History',
        'studio.account.profile' => 'Profile',
    ];
@endphp
<div class="page-head">
    <div>
        <h1>Hi {{ auth()->user()->first_name }}</h1>
        <p>Your classes, family and billing at {{ $studio->name }}.</p>
    </div>
    <a class="btn btn-small" href="{{ route('studio.schedule') }}">Book a class</a>
</div>
<nav class="tabs" aria-label="Account">
    @foreach ($tabs as $route => $label)
        <a href="{{ route($route) }}" @class(['tab', 'active' => request()->routeIs($route)]) @if (request()->routeIs($route)) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>
