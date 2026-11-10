@extends('layouts.admin')

@section('title', 'Members')

@section('content')
<div class="page-head">
    <div>
        <h1>Members</h1>
        <p>@if ($atRisk){{ $members->total() }} at risk of leaving.@else{{ $members->total() }} {{ $members->total() === 1 ? 'account' : 'accounts' }}. New members join at <code>{{ route('studio.join') }}</code>.@endif</p>
    </div>
    <form method="get" class="row">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.imports.index') }}">Import</a>
        @if ($atRisk)<input type="hidden" name="risk" value="at_risk">@endif
        <input class="input" type="search" name="q" value="{{ $q }}" placeholder="Name or email" aria-label="Search members" style="width:16rem">
        <button class="btn btn-secondary btn-small">Search</button>
    </form>
</div>
<div class="filters">
    <a href="{{ route('studio.admin.members.index', array_filter(['q' => $q])) }}" @class(['chip', 'active' => ! $atRisk])>Everyone</a>
    <a href="{{ route('studio.admin.members.index', array_filter(['q' => $q, 'risk' => 'at_risk'])) }}" @class(['chip', 'active' => $atRisk])>At risk of leaving</a>
    @if ($atRisk && $day)<span class="small muted">As of {{ \Illuminate\Support\Carbon::parse($day)->format('M j') }}, riskiest first. Scored each night from their activity.</span>@endif
</div>
@if ($members->isEmpty())
    <div class="card empty">{{ match (true) {
        $atRisk && ! $day => 'Risk is scored each night from 2 AM. Check back tomorrow.',
        $atRisk => 'Nobody is at risk right now.',
        $q !== '' => 'No members match “'.$q.'”.',
        default => 'No members yet.',
    } }}</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Member</th><th>Email</th><th>Phone</th><th>Family</th><th>Plans</th><th>Risk</th><th>Joined</th></tr></thead>
            <tbody>
            @foreach ($members as $m)
                <tr>
                    <td><a href="{{ route('studio.admin.members.show', ['member' => $m]) }}"><strong>{{ $m->name }}</strong></a>@if ($m->pivot->role !== 'member') <span class="badge badge-info">{{ \App\Models\StudioUser::ROLES[$m->pivot->role] }}</span>@endif</td>
                    <td class="small">{{ $m->email }}</td>
                    <td class="small nowrap">{{ $m->phone }}</td>
                    <td>{{ $attendeeCounts[$m->id] ?? 0 }}</td>
                    <td>{{ $planCounts[$m->id] ?? 0 }}</td>
                    <td>@if (($r = $risk->get($m->id)) && $r->level !== 'low')<span class="badge {{ $r->badgeClass() }}" title="{{ implode('; ', array_slice($r->concerns(), 0, 3)) }}">{{ $r->levelLabel() }}</span>@else<span class="muted">—</span>@endif</td>
                    <td class="small nowrap">{{ $studio->local($m->pivot->created_at)->format('M j, Y') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $members->links() }}
@endif
@endsection
