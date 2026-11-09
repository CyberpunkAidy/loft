@php
    $start = $studio->local($s->starts_at);
    $end = $studio->local($s->ends_at);
    $program = $s->program;
    $labels = $program->categoriesOf('age_group')->merge($program->categoriesOf('level'));
    $spots = $s->spotsLeft();
    $myBookings = $mine[$s->id] ?? [];
@endphp
<article @class(['card', 'session', 'session-event' => $program->isEvent()])>
    <div class="session-time">
        {{ $start->format('g:i') }} – {{ $end->format('g:i A') }}
        @if ($s->instructor)<span class="muted"> · {{ $s->instructor->first_name }}</span>@endif
        @if (! $s->isOnline() && $s->relationLoaded('room') && $s->room)<span class="muted"> · {{ $s->room->name }}</span>@endif
    </div>
    <h3><a href="{{ route('studio.program', ['program' => $program]) }}">{{ $program->title }}</a></h3>
    @if ($labels->isNotEmpty() || $program->isEvent() || $program->ageLabel() || $s->delivery !== 'in_person')
        <div class="tags">
            @if ($s->isOnline())<span class="badge badge-info">Online</span>@elseif ($s->isHybrid())<span class="badge badge-info">In studio or online</span>@endif
            @if ($program->isEvent())<span class="badge badge-info">Event</span>@endif
            @foreach ($labels as $label)<span class="tag">{{ $label->name }}</span>@endforeach
            @if ($program->ageLabel())<span class="tag">{{ $program->ageLabel() }}</span>@endif
        </div>
    @endif
    @if ($s->price_cents !== null && ! $program->members_only)
        <div class="muted small">Drop-in {{ \App\Support\Money::format($s->price_cents, $studio->currency) }}</div>
    @endif
    @if ($myBookings)
        <div class="mine">
            @foreach ($myBookings as $b)
                <span class="badge badge-mine">{{ $b->attendee->first_name }}: {{ $b->status === 'waitlisted' ? 'waitlist #'.$b->position : 'booked' }}</span>
            @endforeach
        </div>
    @endif
    <div class="session-foot">
        @if ($s->isFull() && $s->isHybrid() && $s->onlineSpotsLeft() !== 0)
            <span class="badge badge-low">Studio full · online open</span>
        @elseif ($s->isFull())
            <span class="badge badge-full">Full{{ $s->waitlistCount() ? ' · '.$s->waitlistCount().' waiting' : '' }}</span>
        @else
            <span @class(['badge', 'badge-low' => $spots <= 2])>{{ $spots }} {{ $spots === 1 ? 'spot' : 'spots' }} left</span>
        @endif
        @if ($action['url'])
            <a @class(['btn', 'btn-small', 'btn-secondary' => $action['secondary']]) href="{{ $action['url'] }}">{{ $action['label'] }}</a>
        @else
            <span class="btn btn-small btn-secondary" aria-disabled="true">{{ $action['label'] }}</span>
        @endif
    </div>
</article>
