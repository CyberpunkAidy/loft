@extends('layouts.admin')

@section('title', 'Sale #'.$order->number)

@section('content')
<p class="small no-print"><a href="{{ route('studio.admin.pos') }}">‹ Point of sale</a></p>
<div class="page-head no-print">
    <h1>Sale #{{ $order->number }} @if ($order->isVoid())<span class="badge badge-muted">Void</span>@endif</h1>
    <div class="actions">
        <button type="button" class="btn btn-secondary btn-small" onclick="window.print()">Print receipt</button>
        <a class="btn btn-small" href="{{ route('studio.admin.pos') }}">New sale</a>
    </div>
</div>

<div class="card receipt narrow">
    <h2 style="margin:0">{{ $studio->name }}</h2>
    <p class="small muted" style="margin:.2rem 0 1rem">Receipt #{{ $order->number }} · {{ $studio->local($order->paid_at)->format('M j, Y g:i A') }} · {{ $order->customerLabel() }}</p>
    <table class="table">
        <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->description }}{{ $item->quantity > 1 ? ' ×'.$item->quantity : '' }}</td>
                <td class="right nowrap">{{ \App\Support\Money::format($item->total_cents, $order->currency) }}</td>
            </tr>
        @endforeach
        @if ($order->tax_cents)
            <tr><td class="muted">Tax</td><td class="right">{{ \App\Support\Money::format($order->tax_cents, $order->currency) }}</td></tr>
        @endif
        <tr><td><strong>Total</strong></td><td class="right"><strong>{{ $order->totalLabel() }}</strong></td></tr>
        </tbody>
    </table>
    <p class="small" style="margin:.8rem 0 0">Paid by {{ strtolower($order->methodLabel()) }}@if ($order->noteText()) · {{ $order->noteText() }}@endif · served by {{ $order->recordedBy?->first_name ?? 'staff' }}</p>
    @if ($order->user)<p class="small muted" style="margin:.3rem 0 0">A copy was emailed to {{ $order->user->email }}.</p>@endif
</div>

@unless ($order->isVoid())
    <form method="post" action="{{ route('studio.admin.orders.void', ['order' => $order]) }}" class="no-print mt" data-confirm="Void sale #{{ $order->number }}? Plans it added are cancelled and products go back into stock.">
        @csrf
        <button class="link link-danger small">Void this sale</button>
    </form>
@endunless
@endsection
