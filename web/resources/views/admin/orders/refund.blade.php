@extends('layouts.admin')

@section('title', 'Refund sale #'.$order->number)

@section('content')
@php
    $left = $order->total_cents - $order->refunded_cents;
    $takenBack = $order->items->filter(fn ($i) => $i->entitlement && $i->entitlement->id !== $membership?->id && in_array($i->entitlement->status, ['active', 'past_due'], true));
    $products = $order->items->filter(fn ($i) => $i->product_variant_id);
@endphp
<p class="small"><a href="{{ $back }}">‹ Back</a></p>
<div class="page-head">
    <div>
        <h1>Refund sale #{{ $order->number }}</h1>
        <p>{{ $order->customerLabel() }} · {{ $studio->local($order->paid_at)->format('M j, Y') }} · {{ $order->methodLabel() }}</p>
    </div>
</div>

<div class="split">
    <section>
        <div class="card">
            <table class="table">
                <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td>{{ $item->description }}{{ $item->quantity > 1 ? ' ×'.$item->quantity : '' }}</td>
                        <td class="right nowrap">{{ \App\Support\Money::format($item->total_cents, $order->currency) }}</td>
                    </tr>
                @endforeach
                <tr><td><strong>Paid</strong></td><td class="right"><strong>{{ $order->totalLabel() }}</strong></td></tr>
                @if ($order->refunded_cents)
                    <tr><td class="muted">Already refunded</td><td class="right muted">−{{ $order->refundedLabel() }}</td></tr>
                @endif
                </tbody>
            </table>
        </div>

        <div class="card mt">
            <h3>What the refund does</h3>
            <ul class="refund-effects">
                <li><strong>{{ \App\Support\Money::format($left, $order->currency) }}</strong> goes back to the {{ $order->method === 'reader' ? 'card' : 'customer’s card or account' }} through Stripe. Banks usually take 5 to 10 days to show it. Stripe doesn’t return its own fee.</li>
                @foreach ($takenBack as $item)
                    <li><strong>{{ $item->entitlement->plan?->name ?? $item->description }}</strong> is cancelled{{ $item->entitlement->isCredits() ? ' ('.$item->entitlement->credits_remaining.' '.\Illuminate\Support\Str::plural('credit', (int) $item->entitlement->credits_remaining).' left on it)' : '' }}. Classes already booked with it stay booked; remove them from the class if you need to.</li>
                @endforeach
                @if ($products->isNotEmpty())
                    <li>{{ $products->map(fn ($i) => $i->description.($i->quantity > 1 ? ' ×'.$i->quantity : ''))->join(', ', ' and ') }} {{ $products->sum('quantity') === 1 ? 'goes' : 'go' }} back into stock.@if ($order->fulfillment_status === 'picked_up') It was picked up: if they keep it, take it off the stock in Products.@endif</li>
                @endif
            </ul>
        </div>
    </section>

    <aside class="card">
        <form method="post" action="{{ route('studio.admin.orders.refund.store', ['order' => $order]) }}" class="form">
            @csrf
            @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif
            @if ($membership)
                <h3>{{ $membership->plan?->name ?? 'The membership' }}</h3>
                <div class="list" role="radiogroup" aria-label="The membership">
                    <label class="pick">
                        <input type="radio" name="end_membership" value="0" @checked(! old('end_membership'))>
                        <span class="grow"><strong>Refund this payment only</strong><span class="muted small" style="display:block">The membership keeps renewing and can still book.</span></span>
                    </label>
                    <label class="pick">
                        <input type="radio" name="end_membership" value="1" @checked(old('end_membership'))>
                        <span class="grow"><strong>Refund and end the membership now</strong><span class="muted small" style="display:block">It stops renewing in Stripe and can’t book from now on.</span></span>
                    </label>
                </div>
            @endif
            <button class="btn btn-danger">Refund {{ \App\Support\Money::format($left, $order->currency) }}</button>
            <p class="small muted" style="margin:0">To refund only part of it, use the Stripe Dashboard; Loft records it.</p>
        </form>
    </aside>
</div>
@endsection
