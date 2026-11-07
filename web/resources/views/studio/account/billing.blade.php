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
