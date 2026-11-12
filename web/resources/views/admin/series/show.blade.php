@extends('layouts.admin')

@section('title', $series->name)

@section('content')
<p class="small"><a href="{{ route('studio.admin.series.index') }}">‹ Series</a></p>
<div class="page-head">
    <div>
        <h1>{{ $series->name }}</h1>
        <p>{{ $series->program->title }} · {{ $series->datesLabel() }} · {{ $quote['total'] }} {{ $quote['total'] === 1 ? 'class' : 'classes' }} · {{ $series->priceLabel() }}</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.series', ['series' => $series]) }}">View on site ↗</a>
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.series.edit', ['series' => $series]) }}">Edit</a>
    </div>
</div>

<div class="tags mb">
    @if ($quote['open'])<span class="badge">Sign-ups open</span>@else<span class="badge badge-muted">{{ $quote['closed'] }}</span>@endif
    @if ($quote['left'])<span class="badge badge-muted">{{ $quote['spots_left'] }} {{ $quote['spots_left'] === 1 ? 'spot' : 'spots' }} left</span>@endif
    @if ($quote['left'] && $quote['left'] < $quote['total'])<span class="badge badge-muted">Joining now: {{ $quote['left'] }} classes for {{ \App\Support\Money::format($quote['price_cents'], $studio->currency) }}</span>@endif
    <span class="badge badge-muted">Join until class {{ $series->join_until_class }}</span>
    <span class="badge badge-muted">{{ $series->makeups ? 'Make-ups on' : 'No make-ups' }}</span>
    <span class="badge badge-muted">{{ $series->allow_dropins ? 'Drop-ins allowed' : 'No drop-ins' }}</span>
</div>

<div class="split">
    <section class="stack">
        <div>
            <h2>Signed up</h2>
            @if ($enrollments->isEmpty())
                <div class="card empty">Nobody yet.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <thead><tr><th>Who</th><th>Paid</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($enrollments as $e)
                            <tr @class(['is-cancelled' => $e->status === 'withdrawn'])>
                                <td>
                                    @if ($e->user)<a href="{{ route('studio.admin.members.show', ['member' => $e->user_id]) }}"><strong>{{ $e->attendee->name }}</strong></a>@else<strong>{{ $e->attendee->name }}</strong>@endif
                                    @include('admin._person-flags', ['person' => $e->attendee])
                                    @if ($e->user && ! $e->attendee->is_self)<div class="muted small">{{ $e->user->name }}’s account</div>@endif
                                </td>
                                <td class="small">{{ $e->price_cents ? \App\Support\Money::format($e->price_cents, $studio->currency) : 'Free' }}<div class="muted">{{ $e->source === 'staff' ? 'Added by staff' : 'Online' }}{{ $e->classes ? ' · '.$e->classes.' classes' : '' }}</div></td>
                                <td style="white-space:nowrap">{{ $e->statusLabel() }}@if ($e->enrolled_at)<div class="muted small">{{ $studio->local($e->enrolled_at)->format('M j') }}</div>@endif</td>
                                <td class="right" style="white-space:nowrap">
                                    @if ($e->status === 'active')
                                        <form method="post" action="{{ route('studio.admin.series.withdraw', ['series' => $series, 'enrollment' => $e]) }}" class="inline" data-confirm="Take {{ $e->attendee->first_name }} out of {{ $series->name }}? Their classes still to come are cancelled. Refund them separately if you need to.">
                                            @csrf
                                            <button class="link link-danger small">Take out</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        <div>
            <h2>Classes</h2>
            @if ($classes->isEmpty())
                <div class="card empty">No classes on these dates. Check the program’s day and season, then edit the series.</div>
            @else
                <div class="card card-flush table-wrap">
                    <table class="table">
                        <thead><tr><th>#</th><th>Date</th><th>Booked</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($classes as $i => $c)
                            <tr @class(['is-cancelled' => $c->isCancelled()])>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $studio->local($c->starts_at)->format('D M j · g:i A') }}@if ($c->isCancelled()) <span class="badge badge-muted">Cancelled</span>@endif</td>
                                <td>{{ $c->bookedCount() }} / {{ $c->capacity }}@if ($c->waitlistCount()) <span class="muted small">+{{ $c->waitlistCount() }} waiting</span>@endif</td>
                                <td class="right"><a href="{{ route('studio.admin.sessions.show', ['session' => $c]) }}">Roster</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </section>

    <aside class="stack">
        <div class="card">
            <h2 style="margin-top:0">Add someone</h2>
            <p class="small muted" style="margin-top:0">Paid at the desk, or free. They’re booked into every class still to come and emailed.</p>
            <form method="get" action="{{ route('studio.admin.series.show', ['series' => $series]) }}" class="row" style="margin-bottom:.8rem">
                <input class="input" name="q" value="{{ $q }}" placeholder="Search by name" aria-label="Search people">
                <button class="btn btn-secondary btn-small">Search</button>
            </form>
            @if ($q !== '' && $candidates->isEmpty())
                <p class="small muted">Nobody found. People need an account at the studio first.</p>
            @elseif ($candidates->isNotEmpty())
                <form method="post" action="{{ route('studio.admin.series.add', ['series' => $series]) }}" class="form">
                    @csrf
                    <x-select name="attendee_id" label="Who" :options="$candidates->mapWithKeys(fn ($a) => [$a->id => $a->name.($a->user ? ' ('.$a->user->name.')' : '')])->all()" />
                    <div class="grid-2">
                        <x-select name="method" label="Paid by" :options="$methods" value="cash" />
                        <x-field name="amount" label="Amount" type="number" min="0" step="0.01" :value="number_format($quote['price_cents'] / 100, 2, '.', '')" />
                    </div>
                    <x-field name="note" label="Note (optional)" maxlength="190" placeholder="Receipt #, or why it’s free" />
                    <button class="btn">Add to series</button>
                </form>
            @endif
        </div>
    </aside>
</div>
@endsection
