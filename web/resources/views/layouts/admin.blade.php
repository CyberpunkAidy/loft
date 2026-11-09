@extends('layouts.base')

@section('body')
@php
    $me = auth()->user();
    $isAdmin = $me->hasRoleIn($studio, 'admin');
    $nav = fn (string $route, string $label, string $icon, ?string $match = null) => ['url' => route($route), 'label' => $label, 'icon' => $icon, 'active' => request()->routeIs($match ?? $route)];
    $groups = $isAdmin ? [
        '' => [$nav('studio.admin.dashboard', 'Dashboard', 'layout-dashboard'), $nav('studio.admin.checkin', 'Check-in', 'clipboard-check')],
        'Schedule' => [
            $nav('studio.admin.sessions.index', 'Sessions', 'calendar-days', 'studio.admin.sessions.*'),
            $nav('studio.admin.programs.index', 'Programs', 'layers', 'studio.admin.programs.*'),
            $nav('studio.admin.seasons.index', 'Seasons', 'calendar-range', 'studio.admin.seasons.*'),
            $nav('studio.admin.rooms.index', 'Rooms', 'door-open', 'studio.admin.rooms.*'),
            $nav('studio.admin.videos.index', 'Videos', 'clapperboard', 'studio.admin.videos.*'),
        ],
        'Business' => [
            $nav('studio.admin.members.index', 'Members', 'users', 'studio.admin.members.*'),
            $nav('studio.admin.automations.index', 'Automations', 'zap', 'studio.admin.automations.*'),
            $nav('studio.admin.plans.index', 'Plans & pricing', 'tag', 'studio.admin.plans.*'),
            $nav('studio.admin.pos', 'Point of sale', 'store', 'studio.admin.pos*'),
            $nav('studio.admin.products.index', 'Products', 'shopping-bag', 'studio.admin.products.*'),
            $nav('studio.admin.sales.index', 'Sales', 'receipt', 'studio.admin.sales.*'),
            $nav('studio.admin.payments', 'Payments', 'credit-card', 'studio.admin.payments*'),
        ],
        'Studio' => [
            $nav('studio.admin.waivers.index', 'Waivers', 'file-pen-line', 'studio.admin.waivers.*'),
            $nav('studio.admin.access.index', 'Check-in devices', 'scan-line', 'studio.admin.access.*'),
            $nav('studio.admin.staff.index', 'Staff', 'id-card', 'studio.admin.staff.*'),
            $nav('studio.admin.settings', 'Settings', 'settings'),
            $nav('studio.admin.subscription', 'Subscription', 'sparkles', 'studio.admin.subscription*'),
        ],
    ] : ['' => [$nav('studio.admin.checkin', 'Check-in', 'clipboard-check')]];
    $billing = $studio->billing();
    if ($billing->paused()) {
        $groups = ['' => [$nav('studio.admin.subscription', 'Subscription', 'sparkles', 'studio.admin.subscription*')]]; // the one page left
    }
@endphp
<div class="admin">
    <aside class="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="{{ route('studio.admin.dashboard') }}"><span class="brand-mark" aria-hidden="true">{{ $studio->initial() }}</span> {{ $studio->name }}</a>
            <button type="button" class="menu-toggle" data-menu-toggle aria-expanded="false"><x-icon name="menu" /> Menu</button>
        </div>
        <nav aria-label="Studio admin">
            @foreach ($groups as $label => $items)
                @if ($label)<div class="nav-label">{{ $label }}</div>@endif
                @foreach ($items as $item)
                    <a href="{{ $item['url'] }}" @class(['active' => $item['active']]) @if ($item['active']) aria-current="page" @endif><x-icon :name="$item['icon']" />{{ $item['label'] }}</a>
                @endforeach
            @endforeach
        </nav>
        <div class="sidebar-foot">
            <a href="{{ route('studio.schedule') }}"><x-icon name="external-link" />View studio site</a>
            <a href="{{ route('studios') }}"><x-icon name="building-2" />All studios</a>
            <form method="post" action="{{ route('logout') }}">@csrf<button class="link"><x-icon name="log-out" />Sign out</button></form>
            <div class="me">
                <span class="avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($me->first_name, 0, 1).mb_substr($me->last_name, 0, 1)) }}</span>
                <span><strong>{{ $me->name }}</strong><small>{{ $me->email }}</small></span>
            </div>
            <a class="powered" href="{{ route('home') }}">Powered by <x-logo /></a>
        </div>
    </aside>
    <main class="admin-main">
        @include('partials.flash')
        @unless (request()->routeIs('studio.admin.subscription*'))
            @include('admin._subscription-banner', ['billing' => $billing])
        @endunless
        @yield('content')
    </main>
</div>
@endsection
