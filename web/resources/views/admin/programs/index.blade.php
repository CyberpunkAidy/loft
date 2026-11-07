@extends('layouts.admin')

@section('title', 'Programs')

@section('content')
<div class="page-head">
    <div>
        <h1>Programs</h1>
        <p>Weekly classes and one-off events. Saving a program creates its sessions for the season.</p>
    </div>
    <a class="btn" href="{{ route('studio.admin.programs.create') }}">Add a program</a>
</div>
@if ($programs->isEmpty())
    <div class="card empty">
        <p>No programs yet.</p>
        @unless ($studio->seasons()->exists())<p class="small">Tip: <a href="{{ route('studio.admin.seasons.index') }}">add a season</a> first so weekly classes know which dates to run.</p>@endunless
    </div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Program</th><th>When</th><th>Capacity</th><th>Plan tags</th><th>Upcoming</th><th></th></tr></thead>
            <tbody>
            @foreach ($programs as $p)
                <tr>
                    <td>
                        <a href="{{ route('studio.admin.programs.edit', ['program' => $p]) }}"><strong>{{ $p->title }}</strong></a>
                        @unless ($p->is_published)<span class="badge badge-muted">Hidden</span>@endunless
                        @if ($p->isEvent())<span class="badge badge-info">Event</span>@endif
                        <div class="muted small">{{ $p->categoriesOf('age_group')->merge($p->categoriesOf('level'))->pluck('name')->join(', ') }}</div>
                    </td>
                    <td class="small">{{ $p->scheduleLabel() }}@if ($p->season)<div class="muted">{{ $p->season->name }}</div>@endif</td>
                    <td>{{ $p->capacity }}</td>
                    <td class="small">{{ $p->categoriesOf('plan_tag')->pluck('name')->join(', ') ?: '—' }}</td>
                    <td>{{ $p->upcoming_count }}</td>
                    <td class="right"><a href="{{ route('studio.admin.sessions.index', ['program' => $p->id]) }}">Sessions</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
