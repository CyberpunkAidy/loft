@extends('layouts.studio')

@section('title', 'Choose a plan')

@section('content')
<p class="small"><a href="{{ route('studio.book', ['session' => $session]) }}">‹ Back</a></p>
<div class="page-head">
    <div>
        <h1>Choose a plan</h1>
        <p>{{ $attendee->first_name }} needs a membership or class credits for {{ $session->program->title }}. After checkout, the booking completes automatically.</p>
    </div>
</div>
@if ($plans->isEmpty())
    <div class="card empty">No plans cover this class yet. Please contact the studio{{ $studio->contact_email ? ' at '.$studio->contact_email : '' }}.</div>
@else
    <div class="plans">
        @foreach ($plans as $plan)
            @include('studio._plan-card', ['plan' => $plan, 'session' => $session, 'attendee' => $attendee])
        @endforeach
    </div>
@endif
@endsection
