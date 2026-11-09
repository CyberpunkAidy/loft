@extends('layouts.studio')

@section('title', 'Shop')

@section('content')
<div class="page-head">
    <div>
        <h1>Shop</h1>
        <p>Order online and pick it up at the front desk next time you’re in.</p>
    </div>
    <a class="btn btn-secondary" href="{{ route('studio.shop.cart') }}">Cart{{ $count ? ' ('.$count.')' : '' }}</a>
</div>

@if ($products->isEmpty())
    <div class="card empty">Nothing for sale online right now.</div>
@else
    <div class="plans">
        @foreach ($products as $product)
            @php
                $available = $product->variants->filter(fn ($v) => $v->has(1));
                $single = $product->variants->count() === 1 ? $product->variants->first() : null;
            @endphp
            <div class="card plan">
                <h3>{{ $product->name }}</h3>
                <div class="plan-price">{{ $product->priceLabel() }}</div>
                @if ($product->description)<p class="small" style="margin:0">{{ $product->description }}</p>@endif
                @if ($available->isEmpty())
                    <span class="btn btn-secondary" aria-disabled="true">Sold out</span>
                @else
                    <form method="post" action="{{ route('studio.shop.add') }}" class="form">
                        @csrf
                        @if ($single)
                            <input type="hidden" name="variant_id" value="{{ $single->id }}">
                            @if ($single->isTracked() && $single->stock <= \App\Models\ProductVariant::LOW_STOCK)<p class="small muted" style="margin:0">Only {{ $single->stock }} left</p>@endif
                        @else
                            <label class="field">
                                <span>{{ $product->variants->pluck('name')->contains(fn ($n) => in_array(strtolower($n), ['xs', 's', 'm', 'l', 'xl', 'small', 'medium', 'large'], true)) ? 'Size' : 'Option' }}</span>
                                <select name="variant_id" aria-label="Choose an option for {{ $product->name }}">
                                    @foreach ($product->variants as $v)
                                        <option value="{{ $v->id }}" @disabled(! $v->has(1))>{{ $v->name }}{{ $v->price_cents !== null ? ' · '.\App\Support\Money::format($v->price(), $studio->currency) : '' }}{{ ! $v->has(1) ? ' (sold out)' : ($v->isTracked() && $v->stock <= \App\Models\ProductVariant::LOW_STOCK ? ' (only '.$v->stock.' left)' : '') }}</option>
                                    @endforeach
                                </select>
                            </label>
                        @endif
                        <button class="btn btn-block">Add to cart</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endif
@endsection
