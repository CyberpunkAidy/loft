{{-- A series taking sign-ups (decision 47): its dates, classes, price and spots. Needs $series, $quote. --}}
@php $late = $quote['left'] < $quote['total']; @endphp
<article class="card session series-card">
    <div class="session-time">{{ $series->datesLabel() }} · {{ $series->program->scheduleLabel() }}</div>
    <h3><a href="{{ route('studio.series', ['series' => $series]) }}">{{ $series->name }}</a></h3>
    <p class="small muted" style="margin:0">
        {{ $late ? $quote['left'].' of '.$quote['total'] : $quote['total'] }} {{ $quote['total'] === 1 ? 'class' : 'classes' }} ·
        <strong>{{ \App\Support\Money::format($quote['price_cents'], $series->studio->currency) }}</strong>{{ $late ? ' (joining late)' : '' }}
    </p>
    <div class="session-foot">
        <span @class(['badge', 'badge-low' => $quote['spots_left'] <= 2])>{{ $quote['spots_left'] }} {{ $quote['spots_left'] === 1 ? 'spot' : 'spots' }} left</span>
        <a class="btn btn-small" href="{{ route('studio.series', ['series' => $series]) }}">Sign up</a>
    </div>
</article>
