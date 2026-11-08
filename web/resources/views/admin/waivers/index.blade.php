@extends('layouts.admin')

@section('title', 'Waivers')

@section('content')
@php $days = (int) $studio->setting('waiver_validity_days'); @endphp
<div class="page-head">
    <div>
        <h1>Waivers</h1>
        <p>Attendees sign every waiver in force before booking: the ones for every class, plus any attached to the class they book. By default a signature lasts {{ $days ? $days.' days' : 'until a new version' }}.</p>
    </div>
    <div class="actions">
        @if ($waivers->sum('signatures_count') > 0)
            <a class="btn btn-secondary" href="{{ route('studio.admin.waivers.download') }}">Download signed waivers</a>
        @endif
        <a class="btn" href="{{ route('studio.admin.waivers.create') }}">New waiver</a>
    </div>
</div>
@if ($waivers->isEmpty())
    <div class="card empty">No waiver yet, so bookings don’t require one. Most studios should add one.</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Waiver</th><th>Applies to</th><th>Version</th><th>Signatures</th><th>Updated</th><th></th></tr></thead>
            <tbody>
            @foreach ($waivers as $w)
                <tr @class(['is-cancelled' => ! $w->is_active])>
                    <td><a href="{{ route('studio.admin.waivers.edit', ['waiver' => $w]) }}"><strong>{{ $w->title }}</strong></a>
                        @if ($w->is_active) <span class="badge">In force</span>@else <span class="badge badge-muted">Off</span>@endif</td>
                    <td class="small">{{ $w->appliesToEveryClass() ? 'Every class' : ($w->programs->pluck('title')->join(', ') ?: 'No classes chosen') }}@if ($w->validity_days)<div class="muted">Signatures last {{ $w->validity_days }} days</div>@endif</td>
                    <td>{{ $w->version }}</td>
                    <td>{{ $w->signatures_count }}</td>
                    <td class="small">{{ $studio->local($w->updated_at)->format('M j, Y') }}</td>
                    <td class="right">
                        <form method="post" action="{{ route('studio.admin.waivers.activate', ['waiver' => $w]) }}" class="inline">@csrf<button class="btn btn-secondary btn-small">{{ $w->is_active ? 'Turn off' : 'Turn on' }}</button></form>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <p class="small muted mt">Signed waivers are kept when a member deletes their account. The download has every signature, the exact text of each version and the drawn signatures. Take a copy before closing your studio.</p>
@endif
@endsection
