@extends('layouts.studio')

@section('title', 'Your cart')

@section('content')
@php $money = fn (int $cents) => \App\Support\Money::format($cents, $studio->currency); @endphp
<div class="narrow" style="margin:0 auto">
    <p class="small"><a href="{{ route('studio.shop') }}">‹ Shop</a></p>
    <h1>Your cart</h1>
    @if ($lines->isEmpty())
        <div class="card empty">Your cart is empty. <a href="{{ route('studio.shop') }}">Browse the shop</a>.</div>
    @else
        <div class="card">
            <div class="pos-lines">
                @foreach ($lines as $line)
                    <div class="pos-line">
                        <div class="grow">
                            <div>{{ $line['description'] }}</div>
                            <div class="small muted">{{ $money($line['unit']) }}{{ $line['tax'] ? ' + tax' : '' }}@if (! $line['model']->has($line['qty'])) · <span class="error-text">only {{ $line['model']->stock }} left</span>@endif</div>
                        </div>
                        <form method="post" action="{{ route('studio.shop.update', ['variant' => $line['model']->id]) }}" class="row pos-qty">
                            @csrf
                            <button name="qty" value="{{ $line['qty'] - 1 }}" class="btn btn-secondary btn-small" aria-label="One fewer">−</button>
                            <span>{{ $line['qty'] }}</span>
                            <button name="qty" value="{{ $line['qty'] + 1 }}" class="btn btn-secondary btn-small" aria-label="One more">+</button>
                        </form>
                        <strong class="pos-line-total">{{ $money($line['total']) }}</strong>
                    </div>
                @endforeach
            </div>
            <dl class="pos-totals">
                <dt>Subtotal</dt><dd>{{ $money($totals['subtotal']) }}</dd>
                @if ($totals['tax'])<dt>Tax ({{ rtrim(rtrim(number_format($studio->salesTaxPercent(), 3), '0'), '.') }}%)</dt><dd>{{ $money($totals['tax']) }}</dd>@endif
                <dt class="pos-total">Total</dt><dd class="pos-total">{{ $money($totals['total']) }}</dd>
            </dl>
            @if (! $studio->stripeReady())
                <div class="alert alert-warning" style="margin:0">Online payments aren’t set up yet. Ask at the front desk.</div>
            @elseif (auth()->user()?->hasRoleIn($studio))
                <form method="post" action="{{ route('studio.shop.checkout') }}">
                    @csrf
                    <button class="btn btn-block">Check out · {{ $money($totals['total']) }}</button>
                </form>
                <p class="small muted" style="margin:.6rem 0 0">Pay securely through Stripe. Pick up at the front desk. Your items are held for 30 minutes while you pay.</p>
            @elseif (auth()->check())
                <a class="btn btn-block" href="{{ route('studio.join') }}">Join {{ $studio->name }} to check out</a>
            @else
                <a class="btn btn-block" href="{{ route('studio.login') }}">Sign in to check out</a>
                <p class="small muted" style="margin:.6rem 0 0">New here? <a href="{{ route('studio.join') }}">Join {{ $studio->name }}</a> first.</p>
            @endif
        </div>
    @endif
</div>
@endsection
