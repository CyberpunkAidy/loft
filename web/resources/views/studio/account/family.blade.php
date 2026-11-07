@extends('layouts.studio')

@section('title', 'Family')

@section('content')
@include('studio.account._tabs')

@if ($attendees->isEmpty())
    <div class="card empty">
        <p>Add everyone who’ll be taking classes — yourself, your children, or both.</p>
        <a class="btn" href="{{ route('studio.attendees.create') }}">Add a family member</a>
    </div>
@else
    <div class="list">
        @foreach ($attendees as $a)
            @php $w = $waiverStatus[$a->id]; @endphp
            <div class="card item">
                <div>
                    <strong>{{ $a->name }}</strong>@if ($a->is_self) <span class="muted">(you)</span>@endif
                    <div class="muted small">
                        {{ collect([
                            $a->age() !== null ? 'Age '.$a->age() : null,
                            $w['required'] ? ($w['signed'] ? 'Waiver signed' : 'Waiver needed') : null,
                        ])->filter()->join(' · ') }}
                    </div>
                </div>
                <div class="item-actions">
                    @if ($w['required'] && ! $w['signed'])
                        <a class="btn btn-small" href="{{ route('studio.waiver', ['attendee' => $a]) }}">Sign waiver</a>
                    @endif
                    <a class="btn btn-small btn-secondary" href="{{ route('studio.attendees.edit', ['attendee' => $a]) }}">Edit</a>
                </div>
            </div>
        @endforeach
    </div>
    <a class="btn btn-secondary mt" href="{{ route('studio.attendees.create') }}">+ Add a family member</a>
@endif
@endsection
