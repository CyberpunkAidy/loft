@extends('layouts.studio')

@section('title', 'Waivers')

@section('content')
@include('studio.account._tabs')

@if ($waivers->isEmpty())
    <div class="card empty">No waiver is required right now.</div>
@elseif ($attendees->isEmpty())
    <div class="card empty"><a href="{{ route('studio.attendees.create') }}">Add a family member</a> first.</div>
@else
    <div class="list">
        @foreach ($attendees as $a)
            <div class="card">
                <h3 style="margin-bottom:.4rem">{{ $a->name }}</h3>
                <div class="list" style="gap:.5rem">
                    @foreach ($waivers as $w)
                        @php $st = $statuses[$a->id][$w->id]; @endphp
                        <div class="item">
                            <div>
                                <strong>{{ $w->title }}</strong>
                                <div class="muted small">
                                    @unless ($w->appliesToEveryClass())Only for {{ $w->programs->pluck('title')->join(', ', ' and ') ?: 'some classes' }} · @endunless
                                    @if ($st['signed'])
                                        Signed {{ $studio->local($st['signed_at'])->format('M j, Y') }}@if ($st['expires_at']) · valid until {{ $studio->local($st['expires_at'])->format('M j, Y') }}@endif
                                    @elseif ($st['expired'])
                                        Expired; please sign again
                                    @else
                                        Not signed
                                    @endif
                                </div>
                            </div>
                            @if ($st['signed'])
                                <span class="badge">Signed</span>
                            @else
                                <a class="btn btn-small" href="{{ route('studio.waiver', ['attendee' => $a, 'waiver' => $w->id]) }}">Sign now</a>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
@endif
@endsection
