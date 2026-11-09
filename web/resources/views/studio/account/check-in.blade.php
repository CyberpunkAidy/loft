@extends('layouts.studio')

@section('title', 'Check-in codes')

@push('head')<meta http-equiv="refresh" content="{{ $refresh }}">@endpush

@section('content')
@include('studio.account._tabs')
<p>Show a code at the check-in kiosk when you arrive for class. Codes change every {{ \App\Services\CheckInCodes::STEP }} seconds, so a screenshot won’t work; keep this page open, or use the app.</p>
@if ($codes->isEmpty())
    <div class="card empty">Add the people taking classes under <a href="{{ route('studio.account.family') }}">Family</a> first.</div>
@else
    <div class="codes">
        @foreach ($codes as $c)
            <div class="card code-card">
                <h3 style="margin:0">{{ $c['attendee']->name }}</h3>
                {!! $c['svg'] !!}
                <p class="small muted" style="margin:0">Check-in code for {{ $c['attendee']->first_name }}</p>
            </div>
        @endforeach
    </div>
@endif
@endsection
