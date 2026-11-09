@extends('layouts.admin')

@section('title', 'To pick up')

@section('content')
<p class="small"><a href="{{ route('studio.admin.sales.index') }}">‹ Sales</a></p>
<div class="page-head">
    <div>
        <h1>To pick up</h1>
        <p>Paid online, waiting at the front desk. Oldest first. Mark each one when it’s collected.</p>
    </div>
</div>
@if ($orders->isEmpty())
    <div class="card empty">Nothing waiting to be picked up.</div>
@else
    @include('admin.orders._table', ['orders' => $orders, 'showMember' => true])
    {{ $orders->links() }}
@endif
@endsection
