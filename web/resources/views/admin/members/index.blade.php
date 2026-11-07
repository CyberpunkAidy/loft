@extends('layouts.admin')

@section('title', 'Members')

@section('content')
<div class="page-head">
    <div>
        <h1>Members</h1>
        <p>{{ $members->total() }} {{ $members->total() === 1 ? 'account' : 'accounts' }}. New members join at <code>{{ route('studio.join') }}</code>.</p>
    </div>
    <form method="get" class="row">
        <input class="input" type="search" name="q" value="{{ $q }}" placeholder="Name or email" aria-label="Search members" style="width:16rem">
        <button class="btn btn-secondary btn-small">Search</button>
    </form>
</div>
@if ($members->isEmpty())
    <div class="card empty">{{ $q ? 'No members match “'.$q.'”.' : 'No members yet.' }}</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Member</th><th>Email</th><th>Phone</th><th>Family</th><th>Plans</th><th>Joined</th></tr></thead>
            <tbody>
            @foreach ($members as $m)
                <tr>
                    <td><a href="{{ route('studio.admin.members.show', ['member' => $m]) }}"><strong>{{ $m->name }}</strong></a>@if ($m->pivot->role !== 'member') <span class="badge badge-info">{{ \App\Models\StudioUser::ROLES[$m->pivot->role] }}</span>@endif</td>
                    <td class="small">{{ $m->email }}</td>
                    <td class="small nowrap">{{ $m->phone }}</td>
                    <td>{{ $attendeeCounts[$m->id] ?? 0 }}</td>
                    <td>{{ $planCounts[$m->id] ?? 0 }}</td>
                    <td class="small nowrap">{{ $studio->local($m->pivot->created_at)->format('M j, Y') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    {{ $members->links() }}
@endif
@endsection
