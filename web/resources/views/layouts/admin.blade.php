@extends('layouts.base')

@section('body')
@php
    $me = auth()->user();
    $isAdmin = $me->hasRoleIn($studio, 'admin');
    $nav = fn (string $route, string $label, string $match = null) => ['url' => route($route), 'label' => $label, 'active' => request()->routeIs($match ?? $route)];
@endphp
<div class="admin">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="{{ route('studio.admin.dashboard') }}"><span class="brand-mark" aria-hidden="true">{{ mb_strtoupper(mb_substr($studio->name, 0, 1)) }}</span> {{ $studio->name }}</a>
            <button type="button" class="menu-toggle" data-menu-toggle aria-expanded="false">Menu</button>
        </div>
        <nav aria-label="Studio admin">
            @php
                $groups = $isAdmin ? [
                    '' => [$nav('studio.admin.dashboard', 'Dashboard'), $nav('studio.admin.checkin', 'Check-in')],
                    'Schedule' => [$nav('studio.admin.sessions.index', 'Sessions', 'studio.admin.sessions.*'), $nav('studio.admin.programs.index', 'Programs', 'studio.admin.programs.*'), $nav('studio.admin.seasons.index', 'Seasons', 'studio.admin.seasons.*')],
                    'Business' => [$nav('studio.admin.members.index', 'Members', 'studio.admin.members.*'), $nav('studio.admin.plans.index', 'Plans & pricing', 'studio.admin.plans.*'), $nav('studio.admin.payments', 'Payments', 'studio.admin.payments*')],
                    'Studio' => [$nav('studio.admin.waivers.index', 'Waivers', 'studio.admin.waivers.*'), $nav('studio.admin.staff.index', 'Staff', 'studio.admin.staff.*'), $nav('studio.admin.settings', 'Settings')],
                ] : ['' => [$nav('studio.admin.checkin', 'Check-in')]];
            @endphp
            @foreach ($groups as $label => $items)
                @if ($label)<div class="nav-label">{{ $label }}</div>@endif
                @foreach ($items as $item)
                    <a href="{{ $item['url'] }}" @class(['active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            @endforeach
        </nav>
        <div class="sidebar-foot stack">
            <div><a href="{{ route('studio.schedule') }}">View studio site ↗</a></div>
            <div>{{ $me->name }}</div>
            <div class="row">
                <a href="{{ route('studios') }}">All studios</a>
                <form method="post" action="{{ route('logout') }}" class="inline">@csrf<button class="link">Sign out</button></form>
            </div>
        </div>
    </aside>
    <main class="admin-main">
        @include('partials.flash')
        @yield('content')
    </main>
</div>
@endsection
