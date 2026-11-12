@extends('layouts.studio')

@section('title', $series->name)

@section('content')
@php
    $program = $series->program;
    $first = $allClasses->first();
    $late = $quote['left'] < $quote['total'];
    $here = route('studio.series', ['series' => $series], false);
    $price = \App\Support\Money::format($quote['price_cents'], $studio->currency);
    $eligible = $people->filter(fn ($a) => ! $states[$a->id]['enrolled'] && ! $states[$a->id]['age_error']);
@endphp
<div class="narrow" style="margin: 0 auto">
    <p class="small"><a href="{{ route('studio.schedule') }}">‹ Schedule</a></p>
    <div class="card mb">
        <span class="badge badge-info">Series</span>
        <h1 style="margin:.4rem 0 .25rem">{{ $series->name }}</h1>
        <p class="muted" style="margin:0">{{ $series->datesLabel() }} · {{ $program->scheduleLabel() }}@if ($program->instructor) · with {{ $program->instructor->first_name }}@endif</p>
        @if ($program->excerpt)<p style="margin:.7rem 0 0">{{ $program->excerpt }}</p>@endif
        <div class="series-price">
            <div><strong>{{ $price }}</strong> <span class="muted">for {{ $late ? $quote['left'].' of '.$quote['total'] : 'all '.$quote['total'] }} {{ $quote['total'] === 1 ? 'class' : 'classes' }}</span></div>
            @if ($late && $quote['open'])<div class="small muted">It’s already started, so you pay for the classes left. The full series is {{ $series->priceLabel() }}.</div>@endif
            @if ($quote['left'])<span @class(['badge', 'badge-low' => $quote['spots_left'] <= 2, 'badge-full' => $quote['spots_left'] === 0])>{{ $quote['spots_left'] }} {{ $quote['spots_left'] === 1 ? 'spot' : 'spots' }} left</span>@endif
        </div>
        <ul class="small series-rules">
            <li>Sign up once and you’re booked into every class, with your spot held each week.</li>
            @if ($series->makeups)<li>Can’t make one? Cancel at least {{ $studio->setting('late_cancel_hours') }} hours before and you get a make-up class for another day.</li>@endif
            @if ($series->join_until_class > 1)<li>You can still join until class {{ $series->join_until_class }} starts.</li>@endif
        </ul>
    </div>

    @if (! $quote['open'])
        <div class="alert alert-warning">{{ $quote['closed'] }}</div>
    @elseif (! auth()->check())
        <div class="card empty">
            <p>Sign in or create an account to sign up.</p>
            <a class="btn" href="{{ route('studio.login', ['return' => $here]) }}">Sign in</a>
            <a class="btn btn-secondary" href="{{ route('studio.join') }}">Create an account</a>
        </div>
    @elseif ($people->isEmpty())
        <div class="card empty">
            <p>Who’s taking it? Add yourself or a family member first.</p>
            <a class="btn" href="{{ route('studio.attendees.create', ['return' => $here]) }}">Add someone</a>
        </div>
    @else
        <form method="post" action="{{ route('studio.series.signup', ['series' => $series]) }}" class="form">
            @csrf
            <h2>Who’s signing up?</h2>
            <div class="list" role="radiogroup">
                @foreach ($people as $a)
                    @php $st = $states[$a->id]; $disabled = $st['enrolled'] || $st['age_error']; @endphp
                    <label @class(['pick', 'disabled' => $disabled])>
                        <input type="radio" name="attendee_id" value="{{ $a->id }}" @disabled($disabled) @checked(! $disabled && ($eligible->count() === 1 || (int) old('attendee_id', $picked) === $a->id))>
                        <span class="grow">
                            <strong>{{ $a->name }}</strong>
                            <span class="muted small" style="display:block">
                                @if ($st['enrolled'] === 'active')
                                    Already signed up
                                @elseif ($st['enrolled'] === 'pending')
                                    Paying now: finish in the other tab, or choose again in a few minutes
                                @elseif ($st['age_error'])
                                    {{ $st['age_error'] }}
                                @elseif ($st['questions'] || $st['needs_waiver'])
                                    {{ match (true) { $st['questions'] && $st['needs_waiver'] => 'We’ll ask '.($st['questions'] === 1 ? 'a quick question' : 'two quick questions').' and for the waiver first', (bool) $st['questions'] => 'We’ll ask '.($st['questions'] === 1 ? 'a quick question' : 'two quick questions').' first', default => 'We’ll ask you to sign the waiver first' } }}
                                @endif
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>
            @error('attendee_id')<small class="error-text">Choose who’s signing up.</small>@enderror
            <div class="actions">
                <button class="btn" @disabled($eligible->isEmpty())>{{ $quote['price_cents'] > 0 ? 'Sign up and pay '.$price : 'Sign up' }}</button>
                <a class="link small" href="{{ route('studio.attendees.create', ['return' => $here]) }}">+ Add someone else</a>
            </div>
            @if ($quote['price_cents'] > 0)<p class="small muted" style="margin:0">You’ll pay on Stripe’s secure checkout page.</p>@endif
        </form>
    @endif

    <h2 style="margin-top:2rem">Classes</h2>
    <div class="card card-flush">
        <ol class="series-classes">
            @foreach ($allClasses as $i => $c)
                @php $done = $c->hasStarted(); @endphp
                <li @class(['muted' => $done])>
                    <span>{{ $studio->local($c->starts_at)->format('D, M j · g:i A') }}</span>
                    @if ($done)<span class="small">Done</span>@elseif ($makeup)<a class="small" href="{{ route('studio.book', ['session' => $c]) }}">Book with your make-up</a>@endif
                </li>
            @endforeach
        </ol>
    </div>
    @if ($makeup)
        <p class="small muted">You have a make-up class to use by {{ $studio->local($makeup->expires_at)->format('M j') }}: book any one of these, or another class on the schedule.</p>
    @endif
</div>
@endsection
