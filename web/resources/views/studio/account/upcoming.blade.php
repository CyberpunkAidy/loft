@extends('layouts.studio')

@section('title', 'My account')

@section('content')
@include('studio.account._tabs')

@if ($bookings->isEmpty())
    <div class="card empty">
        <p>No upcoming classes.</p>
        <a class="btn" href="{{ route('studio.schedule') }}">Browse the schedule</a>
    </div>
@else
    <div class="list">
        @foreach ($bookings as $b)
            @php
                $start = $studio->local($b->classSession->starts_at);
                $end = $studio->local($b->classSession->ends_at);
                $isLate = $late[$b->id];
                $hours = (int) $studio->setting('late_cancel_hours');
                $confirm = $isLate
                    ? "This is within {$hours} hours of class, so a class credit won’t be returned. Cancel anyway?"
                    : "Cancel {$b->attendee->first_name}’s spot in {$b->classSession->program->title}?";
            @endphp
            <div class="card item">
                <div>
                    <strong>{{ $b->classSession->program->title }}</strong>
                    <div class="muted small">{{ $start->format('D, M j') }} · {{ $start->format('g:i') }} – {{ $end->format('g:i A') }} · {{ $b->attendee->name }}</div>
                    @if ($b->classSession->isCancelled())<div class="error-text small">The studio cancelled this class.</div>@endif
                </div>
                <div class="item-actions">
                    @if ($b->status === 'waitlisted')
                        <span class="badge badge-warn">Waitlist #{{ $b->position }}</span>
                    @else
                        <span class="badge badge-mine">Booked</span>
                    @endif
                    @if ($b->canCancel())
                        <form method="post" action="{{ route('studio.bookings.cancel', ['booking' => $b]) }}" data-confirm="{{ $confirm }}" class="inline">
                            @csrf
                            <button class="link">{{ $b->status === 'waitlisted' ? 'Leave waitlist' : 'Cancel' }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    <p class="small muted mt">Cancel at least {{ (int) $studio->setting('late_cancel_hours') }} hours before class to get your class credit back.</p>
@endif
@endsection
