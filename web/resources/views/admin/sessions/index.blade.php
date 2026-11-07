@extends('layouts.admin')

@section('title', 'Sessions')

@section('content')
<div class="page-head">
    <div>
        <h1>Sessions</h1>
        <p>{{ $from->format('M j') }} – {{ $to->copy()->subDay()->format('M j, Y') }}</p>
    </div>
    <form method="get" class="row">
        <input class="input" type="date" name="from" value="{{ $from->format('Y-m-d') }}" aria-label="From" style="width:auto">
        <select class="input" name="program" aria-label="Program" style="width:auto">
            <option value="0">All programs</option>
            @foreach ($programs as $p)<option value="{{ $p->id }}" @selected($programId === $p->id)>{{ $p->title }}</option>@endforeach
        </select>
        <button class="btn btn-secondary btn-small">Show</button>
    </form>
</div>
<div class="pager">
    <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.sessions.index', array_filter(['from' => $from->copy()->subDays(14)->format('Y-m-d'), 'program' => $programId])) }}">‹ Earlier</a>
    <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.sessions.index', array_filter(['from' => $to->format('Y-m-d'), 'program' => $programId])) }}">Later ›</a>
</div>
@if ($sessions->isEmpty())
    <div class="card empty">No sessions in these two weeks. Sessions come from <a href="{{ route('studio.admin.programs.index') }}">programs</a>.</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>When</th><th>Program</th><th>Booked</th><th>Waitlist</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @foreach ($sessions as $s)
                <tr @class(['is-cancelled' => $s->isCancelled()])>
                    <td class="nowrap">{{ $studio->local($s->starts_at)->format('D M j · g:i A') }}</td>
                    <td>{{ $s->program->title }}</td>
                    <td>{{ $s->bookedCount() }} / {{ $s->capacity }}</td>
                    <td>{{ $s->waitlistCount() ?: '—' }}</td>
                    <td>{{ ucfirst($s->status) }}</td>
                    <td class="right"><a class="btn btn-secondary btn-small" href="{{ route('studio.admin.sessions.show', ['session' => $s]) }}">Roster</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
