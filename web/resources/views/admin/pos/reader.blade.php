@extends('layouts.admin')

@section('title', 'Card reader')

@if ($state === 'waiting')
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif

@section('content')
<p class="small"><a href="{{ route('studio.admin.pos') }}">‹ Point of sale</a></p>
<div class="card narrow reader-wait" style="margin:0 auto">
    @if ($state === 'waiting')
        <h1>Waiting for the card</h1>
        <p>Ask the customer to tap, insert or swipe their card on <strong>{{ $order->cardReader?->label ?? 'the reader' }}</strong>.</p>
    @else
        <h1>The payment didn’t go through</h1>
        <div class="alert alert-error" style="text-align:left"><div>{{ $message }}</div></div>
    @endif

    <div class="reader-amount">{{ $order->totalLabel() }}</div>
    <p class="small muted" style="margin:0">{{ $order->itemsLabel() }} · {{ $order->customerLabel() }}</p>

    @if ($error)<div class="alert alert-warning mt" style="text-align:left"><div>{{ $error }}</div></div>@endif

    @if ($state === 'waiting')
        <div class="spinner" role="status" aria-label="Waiting for the card"></div>
        <p class="small muted">This page updates by itself.</p>
    @endif

    <div class="actions mt">
        @if ($state === 'failed' && $order->cardReader)
            <form method="post" action="{{ route('studio.admin.pos.reader.retry', ['order' => $order]) }}">@csrf<button class="btn">Try again on {{ $order->cardReader->label }}</button></form>
        @endif
        <form method="post" action="{{ route('studio.admin.pos.reader.cancel', ['order' => $order]) }}">@csrf<button class="btn btn-secondary">Cancel sale</button></form>
    </div>

    @if ($testMode && $state === 'waiting' && $order->cardReader)
        <div class="actions mt small">
            <span class="muted">Test mode:</span>
            <form method="post" action="{{ route('studio.admin.pos.reader.simulate', ['order' => $order]) }}" class="inline">@csrf<button class="link small">Tap a test card</button></form>
            <form method="post" action="{{ route('studio.admin.pos.reader.simulate', ['order' => $order]) }}" class="inline">@csrf<input type="hidden" name="decline" value="1"><button class="link small">Tap a card that’s declined</button></form>
        </div>
    @endif
</div>
@endsection
