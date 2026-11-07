@extends('layouts.studio')

@section('title', 'Waivers')

@section('content')
@include('studio.account._tabs')

@if (! $waiver)
    <div class="card empty">No waiver is required right now.</div>
@else
    <p>Current waiver: <strong>{{ $waiver->title }}</strong> <span class="muted">(version {{ $waiver->version }})</span></p>
    @forelse ($attendees as $a)
        @php $w = $waiverStatus[$a->id]; @endphp
        <div class="card item">
            <div>
                <strong>{{ $a->name }}</strong>
                <div class="muted small">
                    @if ($w['signed'])
                        Signed {{ $studio->local($w['signed_at'])->format('M j, Y') }}@if ($w['expires_at']) · valid until {{ $studio->local($w['expires_at'])->format('M j, Y') }}@endif
                    @elseif ($w['signed_at'])
                        Expired — please sign again
                    @else
                        Not signed
                    @endif
                </div>
            </div>
            @if ($w['signed'])
                <span class="badge">Signed</span>
            @else
                <a class="btn btn-small" href="{{ route('studio.waiver', ['attendee' => $a]) }}">Sign now</a>
            @endif
        </div>
    @empty
        <div class="card empty"><a href="{{ route('studio.attendees.create') }}">Add a family member</a> first.</div>
    @endforelse
@endif
@endsection
