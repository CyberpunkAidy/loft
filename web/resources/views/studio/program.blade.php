@extends('layouts.studio')

@section('title', $program->title)

@section('content')
<p class="small"><a href="{{ route('studio.schedule') }}">‹ Schedule</a></p>
<div class="split">
    <div>
        <h1>{{ $program->title }}</h1>
        <p class="muted">{{ $program->scheduleLabel() }}@if ($program->instructor) · with {{ $program->instructor->name }}@endif</p>
        <div class="tags mb">
            @foreach ($program->categories->whereIn('type', ['age_group', 'level']) as $c)<span class="tag">{{ $c->name }}</span>@endforeach
            @if ($program->ageLabel())<span class="tag">{{ $program->ageLabel() }}</span>@endif
            @if ($program->members_only)<span class="tag">Members only</span>@endif
        </div>
        @if ($program->excerpt)<p><strong>{{ $program->excerpt }}</strong></p>@endif
        @foreach (preg_split("/\R\s*\R/", (string) $program->description) ?: [] as $para)
            @if (trim($para) !== '')<p>{!! nl2br(e(trim($para))) !!}</p>@endif
        @endforeach
    </div>
    <aside class="card">
        <h3>Details</h3>
        <ul class="small" style="padding-left:1.1rem;margin:0">
            <li>{{ $program->duration_min }} minutes</li>
            <li>Up to {{ $program->capacity }} per class</li>
            @if ($program->dropin_price_cents !== null && ! $program->members_only)<li>Drop-in {{ \App\Support\Money::format($program->dropin_price_cents, $studio->currency) }}</li>@endif
            @if ($program->season)<li>{{ $program->season->name }}</li>@endif
        </ul>
        <a class="btn btn-secondary btn-small mt" href="{{ route('studio.plans') }}">See memberships &amp; passes</a>
    </aside>
</div>

<h2 class="day">Upcoming</h2>
@if ($sessions->isEmpty())
    <div class="card empty">No upcoming sessions.</div>
@else
    <div class="sessions">
        @foreach ($sessions as $s)
            <div>
                <div class="small muted" style="margin-bottom:.3rem">{{ $studio->local($s->starts_at)->format('l, M j') }}</div>
                @include('studio._session-card', ['s' => $s, 'action' => $actions[$s->id]])
            </div>
        @endforeach
    </div>
@endif
@endsection
