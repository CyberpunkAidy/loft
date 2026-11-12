@extends('layouts.admin')

@section('title', 'Check-in')

@section('content')
<div class="page-head">
    <h1>Check-in</h1>
    <form method="get" class="row">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.checkin', ['date' => $date->copy()->subDay()->format('Y-m-d')]) }}" aria-label="Previous day">‹</a>
        <input class="input" type="date" name="date" value="{{ $date->format('Y-m-d') }}" onchange="this.form.submit()" aria-label="Date" style="width:auto">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.checkin', ['date' => $date->copy()->addDay()->format('Y-m-d')]) }}" aria-label="Next day">›</a>
        <noscript><button class="btn btn-small">Go</button></noscript>
    </form>
</div>

@if ($sessions->isEmpty())
    <div class="card empty">No classes on {{ $date->format('l, F j') }}.</div>
@else
    <div class="session-tabs">
        @foreach ($sessions as $s)
            <a href="{{ route('studio.admin.checkin', ['date' => $date->format('Y-m-d'), 'session' => $s->id]) }}" @class(['active' => $selected && $selected->id === $s->id, 'cancelled' => $s->isCancelled()])>
                {{ $studio->local($s->starts_at)->format('g:i A') }} {{ $s->program->title }} ({{ $s->bookedCount() }}/{{ $s->capacity }})
            </a>
        @endforeach
    </div>

    @if ($selected)
        <h2>{{ $selected->program->title }} <span class="muted small">{{ $studio->local($selected->starts_at)->format('g:i') }} – {{ $studio->local($selected->ends_at)->format('g:i A') }} · {{ $selected->bookedCount() }}/{{ $selected->capacity }} booked</span></h2>
        @if ($selected->isCancelled())<div class="alert alert-warning">This class was cancelled.</div>@endif
        @if ($roster->isEmpty())
            <div class="card empty">Nobody booked.</div>
        @else
            @include('admin._photo-summary')
            <div class="list">
                @foreach ($roster as $b)
                    <div class="card item roster-row status-{{ $b->status }}">
                        <div>
                            <strong>{{ $b->attendee->name }}</strong>
                            @if ($b->attendee->age() !== null)<span class="muted"> · {{ $b->attendee->age() }}</span>@endif
                            @if ($b->apparatusLabel())<span class="badge badge-info">{{ $b->apparatusLabel() }}</span>@endif
                            @if ($b->online_spot)<span class="badge badge-info">Online</span>@endif
                            @unless ($waiverOk[$b->id])<span class="badge badge-full">No waiver</span>@endunless
                            @if (isset($overrides[$b->id]))<span class="badge badge-warn" title="Let in without a signed waiver">Waiver override</span>@endif
                            @include('admin._person-flags', ['person' => $b->attendee])
                            <div class="muted small">{{ collect([$b->user->name, $b->user->phone])->filter()->join(' · ') }}</div>
                            @if ($b->attendee->medical_notes)<div class="medical">⚠ {{ $b->attendee->medical_notes }}</div>@endif
                        </div>
                        <div class="item-actions">
                            @if ($b->status === 'waitlisted')
                                <span class="badge badge-warn">Waitlist #{{ $b->position }}</span>
                            @elseif ($b->status === 'late_cancelled')
                                <span class="badge badge-muted">Late cancel</span>
                            @else
                                @if ($waiverOk[$b->id] || $b->status === 'attended')
                                    <form method="post" action="{{ route('studio.admin.checkin.mark', ['booking' => $b]) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="status" value="{{ $b->status === 'attended' ? 'booked' : 'attended' }}">
                                        <button @class(['btn', 'btn-small', 'btn-secondary' => $b->status !== 'attended']) aria-pressed="{{ $b->status === 'attended' ? 'true' : 'false' }}">Here</button>
                                    </form>
                                @else
                                    {{-- No signed waiver: staff can still let them in, with a reason that's kept. --}}
                                    <form method="post" action="{{ route('studio.admin.checkin.mark', ['booking' => $b]) }}" class="row" data-confirm="Check {{ $b->attendee->first_name }} in without a signed waiver?">
                                        @csrf
                                        <input type="hidden" name="status" value="attended">
                                        <input class="input" name="override_reason" required maxlength="190" placeholder="Reason, e.g. signed on paper" aria-label="Reason to check in without a waiver" style="width:13rem;min-height:2.15rem">
                                        <button class="btn btn-small btn-secondary">Check in anyway</button>
                                    </form>
                                @endif
                                <form method="post" action="{{ route('studio.admin.checkin.mark', ['booking' => $b]) }}" class="inline">
                                    @csrf
                                    <input type="hidden" name="status" value="{{ $b->status === 'no_show' ? 'booked' : 'no_show' }}">
                                    <button @class(['btn', 'btn-small', 'btn-secondary' => $b->status !== 'no_show']) aria-pressed="{{ $b->status === 'no_show' ? 'true' : 'false' }}">No-show</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
@endif
@endsection
