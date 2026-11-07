@extends('layouts.admin')

@section('title', 'Waivers')

@section('content')
<div class="page-head">
    <div>
        <h1>Waivers</h1>
        <p>Every attendee needs a signature on the active waiver before booking online. Signatures last {{ (int) $studio->setting('waiver_validity_days') ?: 'until a new version' }}{{ (int) $studio->setting('waiver_validity_days') ? ' days' : '' }}.</p>
    </div>
    <a class="btn" href="{{ route('studio.admin.waivers.create') }}">New waiver</a>
</div>
@if ($waivers->isEmpty())
    <div class="card empty">No waiver yet, so bookings don’t require one. Most studios should add one.</div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Waiver</th><th>Version</th><th>Signatures</th><th>Updated</th><th></th></tr></thead>
            <tbody>
            @foreach ($waivers as $w)
                <tr>
                    <td><a href="{{ route('studio.admin.waivers.edit', ['waiver' => $w]) }}"><strong>{{ $w->title }}</strong></a>@if ($w->is_active) <span class="badge">Active</span>@endif</td>
                    <td>{{ $w->version }}</td>
                    <td>{{ $w->signatures_count }}</td>
                    <td class="small">{{ $studio->local($w->updated_at)->format('M j, Y') }}</td>
                    <td class="right">
                        @unless ($w->is_active)
                            <form method="post" action="{{ route('studio.admin.waivers.activate', ['waiver' => $w]) }}" class="inline">@csrf<button class="btn btn-secondary btn-small">Make active</button></form>
                        @endunless
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
