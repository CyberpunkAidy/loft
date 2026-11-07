@extends('layouts.admin')

@section('title', 'Plans & pricing')

@section('content')
<div class="page-head">
    <div>
        <h1>Plans &amp; pricing</h1>
        <p>What members can buy. Each plan covers the programs carrying its plan tags.</p>
    </div>
    <a class="btn" href="{{ route('studio.admin.plans.create') }}">Add a plan</a>
</div>
@unless ($studio->stripeReady())
    <div class="alert alert-warning">Members can’t buy plans until you <a href="{{ route('studio.admin.payments') }}">connect Stripe</a>. You can still create plans and comp them to members.</div>
@endunless
@if ($plans->isEmpty())
    <div class="card empty">
        <p>No plans yet. Most studios start with a monthly membership, a class pack and a drop-in.</p>
        <a class="btn" href="{{ route('studio.admin.plans.create') }}">Add a plan</a>
    </div>
@else
    <div class="card card-flush table-wrap">
        <table class="table">
            <thead><tr><th>Plan</th><th>Price</th><th>Covers</th><th>Holders</th><th>Stripe</th><th></th></tr></thead>
            <tbody>
            @foreach ($plans as $p)
                <tr>
                    <td>
                        <a href="{{ route('studio.admin.plans.edit', ['plan' => $p]) }}"><strong>{{ $p->name }}</strong></a>
                        @unless ($p->active)<span class="badge badge-muted">Hidden</span>@endunless
                        <div class="muted small">{{ $p->kindLabel() }} · {{ $p->detailLabel() }}</div>
                    </td>
                    <td class="nowrap">{{ $p->priceLabel() }}</td>
                    <td class="small">{{ $p->coversAll() ? 'All programs' : implode(', ', $p->program_scope) }}</td>
                    <td>{{ $p->holders_count }}</td>
                    <td>
                        @if ($p->isStripeReady())
                            <span class="badge">Ready</span>
                        @elseif ($studio->stripeReady())
                            <form method="post" action="{{ route('studio.admin.plans.sync', ['plan' => $p]) }}" class="inline">@csrf<button class="btn btn-secondary btn-small">Create in Stripe</button></form>
                        @else
                            <span class="badge badge-muted">Not connected</span>
                        @endif
                    </td>
                    <td class="right"><a href="{{ route('studio.admin.plans.edit', ['plan' => $p]) }}">Edit</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endif
@endsection
