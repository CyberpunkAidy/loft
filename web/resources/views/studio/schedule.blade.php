@extends('layouts.studio')

@section('title', 'Schedule')

@section('content')
@php
    $link = fn (array $change) => route('studio.schedule', array_filter(array_merge(['group' => $group, 'level' => $level, 'week' => $page], $change)));
@endphp
<div class="page-head">
    <div>
        <h1>Schedule</h1>
        <p>{{ $from->format('M j') }} – {{ $to->format('M j, Y') }}</p>
    </div>
    <a class="btn btn-secondary btn-small" href="{{ route('studio.plans') }}">Memberships &amp; passes</a>
</div>

@if ($groups->isNotEmpty() || $levels->isNotEmpty())
    <div class="filters" aria-label="Filter classes">
        <a href="{{ $link(['group' => null, 'week' => null]) }}" @class(['chip', 'active' => $group === ''])>All ages</a>
        @foreach ($groups as $g)
            <a href="{{ $link(['group' => $g->slug, 'week' => null]) }}" @class(['chip', 'active' => $group === $g->slug])>{{ $g->name }}</a>
        @endforeach
        @if ($levels->isNotEmpty())
            <span class="sep" aria-hidden="true"></span>
            <a href="{{ $link(['level' => null, 'week' => null]) }}" @class(['chip', 'active' => $level === ''])>Any level</a>
            @foreach ($levels as $l)
                <a href="{{ $link(['level' => $l->slug, 'week' => null]) }}" @class(['chip', 'active' => $level === $l->slug])>{{ $l->name }}</a>
            @endforeach
        @endif
    </div>
@endif

@if ($openSeries->isNotEmpty())
    <section class="mb">
        <h2 class="day">Series open for sign-up</h2>
        <p class="small muted" style="margin-top:0">Sign up once for the whole run, and you’re booked into every class.</p>
        <div class="sessions">
            @foreach ($openSeries as $s)
                @include('studio._series-card', $s)
            @endforeach
        </div>
    </section>
@endif

<div class="pager">
    @if ($page > 0)
        <a class="btn btn-secondary btn-small" href="{{ $link(['week' => $page - 1]) }}">‹ Earlier</a>
    @else
        <span class="btn btn-secondary btn-small" aria-disabled="true">‹ Earlier</span>
    @endif
    <a class="btn btn-secondary btn-small" href="{{ $link(['week' => $page + 1]) }}">Later ›</a>
</div>

@forelse ($days as $day => $sessions)
    <h2 class="day">{{ \Illuminate\Support\Carbon::parse($day)->format('l, F j') }}</h2>
    <div class="sessions">
        @foreach ($sessions as $s)
            @include('studio._session-card', ['s' => $s, 'action' => $actions[$s->id]])
        @endforeach
    </div>
@empty
    <div class="card empty">No classes in this range{{ $group || $level ? ' for these filters' : '' }}.</div>
@endforelse
@endsection
