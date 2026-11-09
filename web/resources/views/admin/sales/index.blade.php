@extends('layouts.admin')

@section('title', 'Sales')

@section('content')
@php $month = $from->format('Y-m'); @endphp
<div class="page-head">
    <div>
        <h1>Sales</h1>
        <p>Every sale: online checkouts, each membership payment, and payments taken in person.</p>
    </div>
    <div class="actions">
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.sales.index', ['month' => $from->copy()->subMonthNoOverflow()->format('Y-m')]) }}" aria-label="Previous month">‹</a>
        <strong>{{ $from->format('F Y') }}</strong>
        <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.sales.index', ['month' => $from->copy()->addMonthNoOverflow()->format('Y-m')]) }}" aria-label="Next month">›</a>
        <a class="btn btn-small" href="{{ route('studio.admin.sales.export', ['month' => $month]) }}">Download CSV</a>
    </div>
</div>

@if ($toPickUp)
    <div class="alert alert-warning"><div>{{ $toPickUp }} online {{ \Illuminate\Support\Str::plural('order', $toPickUp) }} waiting to be picked up. <a href="{{ route('studio.admin.sales.index', ['pickup' => 1]) }}">See them</a></div></div>
@endif
<div class="stats">
    <div class="stat"><strong>{{ \App\Support\Money::format($total, $studio->currency) }}</strong><span>Total sales</span></div>
    <div class="stat"><strong>{{ \App\Support\Money::format($online, $studio->currency) }}</strong><span>Online</span></div>
    <div class="stat"><strong>{{ \App\Support\Money::format($inPerson, $studio->currency) }}</strong><span>In person</span></div>
    <div class="stat"><strong>{{ $count }}</strong><span>Number of sales{{ $comps ? ', plus '.$comps.' '.\Illuminate\Support\Str::plural('comp', $comps) : '' }}</span></div>
    @if ($refunds)<div class="stat"><strong>−{{ \App\Support\Money::format($refunds, $studio->currency) }}</strong><span>Refunded</span></div>@endif
</div>

@if ($orders->isEmpty())
    <div class="card empty">No sales in {{ $from->format('F Y') }}.</div>
@else
    @include('admin.orders._table', ['orders' => $orders, 'showMember' => true])
    {{ $orders->links() }}
@endif
<p class="small muted mt">Totals are what you kept: voided sales and refunds are left out, and a refund counts in the month of the sale. Online sales are listed from when the ledger began (November 2026); earlier ones are in your Stripe Dashboard.</p>
@endsection
