@extends('layouts.admin')

@section('title', 'Point of sale')

@section('content')
@php $money = fn (int $cents) => \App\Support\Money::format($cents, $studio->currency); @endphp
<div class="page-head">
    <div>
        <h1>Point of sale</h1>
        <p>Sell products and plans at the front desk. Take payment however it’s paid; card readers come later.</p>
    </div>
    <a class="btn btn-secondary btn-small" href="{{ route('studio.admin.products.index') }}">Products</a>
</div>

<div class="split pos">
    <section class="stack">
        @if ($products->isEmpty() && $plans->isEmpty())
            <div class="card empty">Nothing to sell yet. <a href="{{ route('studio.admin.products.create') }}">Add a product</a> or a plan.</div>
        @endif
        @if ($products->isNotEmpty())
            <h2 style="margin:0">Products</h2>
            <div class="pos-grid">
                @foreach ($products as $product)
                    @foreach ($product->variants as $v)
                        @php $out = $v->isTracked() && $v->stock <= 0; @endphp
                        <form method="post" action="{{ route('studio.admin.pos.add') }}">
                            @csrf
                            <input type="hidden" name="type" value="variant"><input type="hidden" name="id" value="{{ $v->id }}">
                            @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
                            <button class="pos-tile" @disabled($out)>
                                <strong>{{ $product->name }}</strong>
                                @if ($v->name !== 'One size')<span>{{ $v->name }}</span>@endif
                                <span class="pos-tile-foot">{{ $money($v->price()) }} <span class="muted">{{ $v->isTracked() ? ($out ? 'Sold out' : $v->stock.' left') : '' }}</span></span>
                            </button>
                        </form>
                    @endforeach
                @endforeach
            </div>
        @endif
        @if ($plans->isNotEmpty())
            <h2 style="margin:.5rem 0 0">Plans</h2>
            <div class="pos-grid">
                @foreach ($plans as $plan)
                    <form method="post" action="{{ route('studio.admin.pos.add') }}">
                        @csrf
                        <input type="hidden" name="type" value="plan"><input type="hidden" name="id" value="{{ $plan->id }}">
                        @if ($q !== '')<input type="hidden" name="q" value="{{ $q }}">@endif
                        <button class="pos-tile">
                            <strong>{{ $plan->name }}</strong>
                            <span class="muted">{{ $plan->kindLabel() }}</span>
                            <span class="pos-tile-foot">{{ $money($plan->price_cents) }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        @endif
    </section>

    <aside class="card pos-cart">
        <h3>Customer</h3>
        @if ($member)
            <p style="margin:0 0 .5rem"><strong>{{ $member->name }}</strong> <span class="muted small">{{ $member->email }}</span></p>
            <form method="post" action="{{ route('studio.admin.pos.customer') }}" class="inline">@csrf<button class="link small">Change</button></form>
        @elseif ($walkIn)
            <p style="margin:0 0 .5rem"><strong>Walk-in</strong> <span class="muted small">no account, no receipt email</span></p>
            <form method="post" action="{{ route('studio.admin.pos.customer') }}" class="inline">@csrf<button class="link small">Change</button></form>
        @else
            <form method="get" action="{{ route('studio.admin.pos') }}" class="row">
                <input class="input grow" type="search" name="q" value="{{ $q }}" placeholder="Find a member" aria-label="Find a member">
                <button class="btn btn-secondary btn-small">Search</button>
            </form>
            @foreach ($matches as $match)
                <form method="post" action="{{ route('studio.admin.pos.customer') }}" class="item small" style="padding:.35rem 0">
                    @csrf <input type="hidden" name="member_id" value="{{ $match->id }}">
                    <span>{{ $match->name }} <span class="muted">{{ $match->email }}</span></span>
                    <button class="btn btn-secondary btn-small">Choose</button>
                </form>
            @endforeach
            @if ($q !== '' && $matches->isEmpty())<p class="small muted">No members match “{{ $q }}”.</p>@endif
            <form method="post" action="{{ route('studio.admin.pos.customer') }}" style="margin-top:.5rem">
                @csrf <input type="hidden" name="walk_in" value="1">
                <button class="link small">Walk-in (no account)</button>
            </form>
        @endif

        <h3 class="mt">Cart</h3>
        @if ($lines->isEmpty())
            <p class="small muted" style="margin:0">Empty. Tap a product or plan to add it.</p>
        @else
            <div class="pos-lines">
                @foreach ($lines as $i => $line)
                    <div class="pos-line">
                        <div class="grow">
                            <div>{{ $line['description'] }}</div>
                            <div class="small muted">{{ $money($line['unit']) }}{{ $line['tax'] ? ' + tax' : '' }}</div>
                        </div>
                        @if ($line['type'] === 'variant')
                            <form method="post" action="{{ route('studio.admin.pos.quantity', ['line' => $i]) }}" class="row pos-qty">
                                @csrf
                                <button name="qty" value="{{ $line['qty'] - 1 }}" class="btn btn-secondary btn-small" aria-label="One fewer">−</button>
                                <span>{{ $line['qty'] }}</span>
                                <button name="qty" value="{{ $line['qty'] + 1 }}" class="btn btn-secondary btn-small" aria-label="One more">+</button>
                            </form>
                        @else
                            <form method="post" action="{{ route('studio.admin.pos.quantity', ['line' => $i]) }}">
                                @csrf <button name="qty" value="0" class="link link-danger small">Remove</button>
                            </form>
                        @endif
                        <strong class="pos-line-total">{{ $money($line['total']) }}</strong>
                    </div>
                @endforeach
            </div>
            <dl class="pos-totals">
                <dt>Subtotal</dt><dd>{{ $money($totals['subtotal']) }}</dd>
                @if ($totals['tax'])<dt>Tax ({{ rtrim(rtrim(number_format($studio->salesTaxPercent(), 3), '0'), '.') }}%)</dt><dd>{{ $money($totals['tax']) }}</dd>@endif
                <dt class="pos-total">Total</dt><dd class="pos-total">{{ $money($totals['total']) }}</dd>
            </dl>
            <form method="post" action="{{ route('studio.admin.pos.checkout') }}" class="form">
                @csrf
                <x-select name="method" label="Paid by" :options="$methods" value="cash" />
                <x-field name="note" label="Note" maxlength="190" placeholder="Check #1042" />
                <button class="btn btn-block">Take payment · {{ $money($totals['total']) }}</button>
            </form>
            <form method="post" action="{{ route('studio.admin.pos.clear') }}" class="mt">@csrf<button class="link small">Clear the cart</button></form>
        @endif
    </aside>
</div>
@endsection
