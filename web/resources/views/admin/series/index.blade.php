@extends('layouts.admin')

@section('title', 'Series')

@section('content')
<div class="page-head">
    <div>
        <h1>Series</h1>
        <p>A run of a program’s classes sold as one, like an 8-week Silks Level 1. Members pay once and are booked into every class.</p>
    </div>
    <div class="actions"><a class="btn" href="{{ route('studio.admin.series.create') }}">New series</a></div>
</div>
@if ($series->isEmpty())
    <div class="card empty">
        <p>No series yet. Make one from a weekly program: choose its dates and price, and members can sign up for the whole run.</p>
        <a class="btn" href="{{ route('studio.admin.series.create') }}">New series</a>
    </div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Series</th><th>Dates</th><th>Classes</th><th>Price</th><th>Signed up</th><th>Sign-ups</th></tr></thead>
            <tbody>
            @foreach ($series as $s)
                @php $quote = $quotes[$s->id]; @endphp
                <tr>
                    <td><a href="{{ route('studio.admin.series.show', ['series' => $s]) }}"><strong>{{ $s->name }}</strong></a><div class="muted small">{{ $s->program->title }}</div></td>
                    <td class="small">{{ $s->datesLabel() }}</td>
                    <td>{{ $s->class_count }}</td>
                    <td>{{ $s->priceLabel() }}</td>
                    <td>{{ $s->signed_up }}@if ($quote['left'])<span class="muted small"> · {{ $quote['spots_left'] }} {{ $quote['spots_left'] === 1 ? 'spot' : 'spots' }} left</span>@endif</td>
                    <td>@if ($quote['open'])<span class="badge">Open</span>@elseif ($quote['left'] === 0)<span class="badge badge-muted">Finished</span>@else<span class="badge badge-muted" title="{{ $quote['closed'] }}">Closed</span>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
