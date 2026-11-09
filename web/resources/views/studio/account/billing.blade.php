@extends('layouts.studio')

@section('title', 'Plans & billing')

@section('content')
@include('studio.account._tabs')

@if ($entitlements->isEmpty())
    <div class="card empty">You don’t have a membership or class credits yet.</div>
@else
    <div class="list">
        @foreach ($entitlements as $e)
            @php
                $detail = $e->isCredits()
                    ? "{$e->credits_remaining} of {$e->credits_total} credits left".($e->expires_at ? ' · expire '.$studio->local($e->expires_at)->format('M j, Y') : '')
                    : collect([
                        $e->current_period_end && ! $e->expires_at ? 'Renews '.$studio->local($e->current_period_end)->format('M j, Y') : null,
                        $e->expires_at ? 'Ends '.$studio->local($e->expires_at)->format('M j, Y') : null,
                        $e->weekly_limit ? "Up to {$e->weekly_limit} classes a week" : 'Unlimited classes',
                    ])->filter()->join(' · ');
                $badge = ['active' => 'badge-mine', 'past_due' => 'badge-full'][$e->status] ?? 'badge-muted';
            @endphp
            <div class="card item">
                <div>
                    <strong>{{ $e->plan?->name ?? 'Plan' }}</strong>
                    <div class="muted small">{{ $detail }}</div>
                    @if ($e->status === 'past_due')<div class="error-text small">Your last payment failed. Update your card to keep booking.</div>@endif
                </div>
                <span class="badge {{ $badge }}">{{ $e->statusLabel() }}</span>
            </div>
        @endforeach
    </div>
@endif

@if ($orders->isNotEmpty())
    <h2 class="mt">Purchases</h2>
    <div class="list">
        @foreach ($orders as $order)
            <div @class(['card', 'item', 'is-void' => $order->isUndone()])>
                <div>
                    <strong>{{ $order->itemsLabel() }}</strong>
                    <div class="muted small">{{ $studio->local($order->paid_at)->format('M j, Y') }} · {{ $order->methodLabel() }} · Order #{{ $order->number }}</div>
                    @if ($order->awaitsPickup())<div class="small"><span class="badge badge-warn">Ready to pick up</span></div>@elseif ($order->fulfillment_status === 'picked_up')<div class="small muted">Picked up {{ $studio->local($order->picked_up_at)->format('M j') }}</div>@endif
                </div>
                <span @class(['badge', 'badge-muted' => $order->isUndone()])>{{ $order->isUndone() ? $order->statusLabel() : $order->totalLabel().($order->refunded_cents ? ' · '.$order->refundedLabel().' refunded' : '') }}</span>
            </div>
        @endforeach
    </div>
    <p class="small muted">Receipts for online payments are in the billing portal.</p>
@endif

<div class="actions mt">
    <a class="btn" href="{{ route('studio.plans') }}">Buy a plan or credits</a>
    @if ($canOpenPortal)
        <form method="post" action="{{ route('studio.billing.portal') }}" class="inline">
            @csrf
            <button class="btn btn-secondary">Manage cards, invoices &amp; cancellations</button>
        </form>
    @endif
</div>
@endsection
