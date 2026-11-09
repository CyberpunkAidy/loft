@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="page-head">
    <div>
        <h1>{{ $studio->name }}</h1>
        <p>{{ $studio->localNow()->format('l, F j') }}</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.checkin') }}">Open check-in</a>
        <a class="btn btn-small" href="{{ route('studio.admin.programs.create') }}">Add a class</a>
    </div>
</div>

@if ($webhookErrors)
    <div class="alert alert-error">{{ $webhookErrors }} Stripe {{ $webhookErrors === 1 ? 'event' : 'events' }} failed to process. Stripe will retry; if this persists, check the server logs.</div>
@endif

<div class="stats">
    <div class="stat"><strong>{{ $stats['members'] }}</strong><span>Members</span></div>
    <a class="stat" href="{{ route('studio.admin.sales.index') }}"><strong>{{ \App\Support\Money::format($stats['sales_month'], $studio->currency) }}</strong><span>Sales this month</span></a>
    @if ($stats['to_pick_up'])<a class="stat" href="{{ route('studio.admin.sales.index', ['pickup' => 1]) }}"><strong>{{ $stats['to_pick_up'] }}</strong><span>Orders to pick up</span></a>@endif
    <div class="stat"><strong>{{ $stats['memberships'] }}</strong><span>Active memberships</span></div>
    <div @class(['stat', 'warn' => $stats['past_due'] > 0])><strong>{{ $stats['past_due'] }}</strong><span>Past due</span></div>
    <div class="stat"><strong>{{ $stats['bookings_week'] }}</strong><span>Bookings, last 7 days</span></div>
    <div class="stat"><strong>{{ $stats['waitlisted'] }}</strong><span>On waitlists</span></div>
</div>

<div class="split">
    <section>
        <h2>Today</h2>
        @if ($todaySessions->isEmpty())
            <div class="card empty">No classes today.</div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>Time</th><th>Class</th><th>Booked</th><th>Waitlist</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($todaySessions as $s)
                        <tr @class(['is-cancelled' => $s->isCancelled()])>
                            <td class="nowrap">{{ $studio->local($s->starts_at)->format('g:i A') }}</td>
                            <td>{{ $s->program->title }}@if ($s->isCancelled()) <span class="badge badge-muted">Cancelled</span>@endif</td>
                            <td>{{ $s->bookedCount() }} / {{ $s->capacity }}</td>
                            <td>{{ $s->waitlistCount() }}</td>
                            <td class="right"><a href="{{ route('studio.admin.sessions.show', ['session' => $s]) }}">Roster</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <aside class="card">
        <h2>Setup</h2>
        <ul class="checklist">
            @foreach ($checklist as $step)
                <li @class(['done' => $step['done']])><span class="dot" aria-hidden="true">{{ $step['done'] ? '✓' : '' }}</span><a href="{{ $step['url'] }}">{{ $step['label'] }}</a><span class="sr-only">{{ $step['done'] ? '(done)' : '(to do)' }}</span></li>
            @endforeach
        </ul>
        <hr>
        <p class="small" style="margin:0">Share your schedule:<br><code>{{ route('studio.schedule') }}</code></p>
    </aside>
</div>
@endsection
