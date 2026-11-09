@extends('layouts.studio')

@section('title', 'Book '.$session->program->title)

@section('content')
@php
    $start = $studio->local($session->starts_at);
    $end = $studio->local($session->ends_at);
    $here = route('studio.book', ['session' => $session], false);
    $bookable = $attendees->filter(fn ($a) => ! $states[$a->id]['booking'] && ! $states[$a->id]['age_error']);
@endphp
<div class="narrow" style="margin: 0 auto">
    <p class="small"><a href="{{ route('studio.schedule') }}">‹ Schedule</a></p>
    <div class="card mb">
        <h1 style="margin-bottom:.25rem">{{ $session->program->title }}</h1>
        <p class="muted" style="margin:0">{{ $start->format('l, F j') }} · {{ $start->format('g:i') }} – {{ $end->format('g:i A') }}@if ($session->delivery !== 'in_person') · {{ $session->deliveryLabel() }}@endif</p>
        @if ($session->isOnline())
            <p class="small" style="margin:.5rem 0 0">This class is online. The link to join appears under <strong>My account</strong> {{ \App\Models\ClassSession::JOIN_OPENS_MINUTES }} minutes before it starts.</p>
        @endif
        <p class="small" style="margin:.5rem 0 0">
            @if ($session->isFull() && $session->waitlistIsFull())
                <span class="badge badge-full">Full</span>
            @elseif ($session->isFull())
                <span class="badge badge-full">Full</span> You’ll join the waitlist{{ $session->waitlistCount() ? ' ('.$session->waitlistCount().' ahead of you)' : '' }} and we’ll email you if a spot opens.
            @else
                <span class="badge">{{ $session->spotsLeft() }} {{ $session->spotsLeft() === 1 ? 'spot' : 'spots' }} left</span>
            @endif
        </p>
    </div>

    @if ($closed)
        <div class="alert alert-warning">{{ $closed }}</div>
    @elseif ($attendees->isEmpty())
        <div class="card empty">
            <p>Who’s coming? Add yourself or a family member first.</p>
            <a class="btn" href="{{ route('studio.attendees.create', ['return' => $here]) }}">Add a family member</a>
        </div>
    @else
        <form method="post" action="{{ route('studio.book', ['session' => $session]) }}" class="form">
            @csrf
            <h2>Who’s coming?</h2>
            <div class="list" role="radiogroup">
                @foreach ($attendees as $a)
                    @php $st = $states[$a->id]; $disabled = $st['booking'] || $st['age_error']; @endphp
                    <label @class(['pick', 'disabled' => $disabled])>
                        <input type="radio" name="attendee_id" value="{{ $a->id }}" @disabled($disabled) @checked(! $disabled && $bookable->count() === 1)>
                        <span class="grow">
                            <strong>{{ $a->name }}</strong>
                            <span class="muted small" style="display:block">
                                @if ($st['booking'])
                                    Already {{ $st['booking']->status === 'waitlisted' ? 'on the waitlist' : 'booked' }}
                                @elseif ($st['age_error'])
                                    {{ $st['age_error'] }}
                                @elseif ($st['needs_waiver'])
                                    We’ll ask you to sign the waiver first
                                @endif
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('attendee_id')<small class="error-text">Choose who’s coming.</small>@enderror

            @if ($session->isHybrid())
                @php $onlineLeft = $session->onlineSpotsLeft(); $studioClosed = $session->isFull() && $session->waitlistIsFull(); @endphp
                <h2>Where</h2>
                <div class="list" role="radiogroup">
                    <label @class(['pick', 'disabled' => $studioClosed])>
                        <input type="radio" name="online" value="0" @checked(! old('online', $online)) @disabled($studioClosed)>
                        <span class="grow"><strong>In the studio</strong><span class="muted small" style="display:block">{{ $studioClosed ? 'Full' : ($session->isFull() ? 'Full. You’ll join the waitlist.' : $session->spotsLeft().' '.($session->spotsLeft() === 1 ? 'spot' : 'spots').' left') }}</span></span>
                    </label>
                    <label @class(['pick', 'disabled' => $onlineLeft === 0])>
                        <input type="radio" name="online" value="1" @checked(old('online', $online)) @disabled($onlineLeft === 0)>
                        <span class="grow"><strong>Online</strong><span class="muted small" style="display:block">{{ $onlineLeft === 0 ? 'Full' : ($onlineLeft === null ? 'Join from home' : $onlineLeft.' '.($onlineLeft === 1 ? 'spot' : 'spots').' left') }}. The link appears {{ \App\Models\ClassSession::JOIN_OPENS_MINUTES }} minutes before class.</span></span>
                    </label>
                </div>
            @endif

            @if ($slots->count() > 1)
                @php $pick = (string) old('apparatus_id', $chosen ?? ''); @endphp
                <h2>Apparatus</h2>
                <div class="list" role="radiogroup">
                    <label class="pick">
                        <input type="radio" name="apparatus_id" value="" @checked($pick === '')>
                        <span class="grow"><strong>No preference</strong><span class="muted small" style="display:block">We’ll pick the one with the most room.</span></span>
                    </label>
                    @foreach ($slots as $slot)
                        @php $blocked = $slot->isFull() && $session->waitlistIsFull(); @endphp
                        <label @class(['pick', 'disabled' => $blocked])>
                            <input type="radio" name="apparatus_id" value="{{ $slot->apparatus->id }}" @checked($pick === (string) $slot->apparatus->id) @disabled($blocked)>
                            <span class="grow">
                                <strong>{{ $slot->apparatus->name }}</strong>
                                <span class="muted small" style="display:block">
                                    @if (! $slot->isFull())
                                        {{ $slot->left() }} {{ $slot->left() === 1 ? 'spot' : 'spots' }} left
                                    @elseif ($blocked)
                                        Full
                                    @else
                                        Full. You’ll join the waitlist for one.
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>
            @elseif ($slots->count() === 1)
                <p class="small muted" style="margin:0">On {{ $slots->first()->apparatus->name }}.</p>
            @endif
            <div class="actions">
                <button class="btn" @disabled($bookable->isEmpty())>{{ $session->isFull() && ! $session->isHybrid() ? 'Join waitlist' : 'Book' }}</button>
                <a class="link small" href="{{ route('studio.attendees.create', ['return' => $here]) }}">+ Add someone else</a>
            </div>
        </form>
    @endif
</div>
@endsection
