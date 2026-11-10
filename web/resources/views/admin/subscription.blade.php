@extends('layouts.admin')

@section('title', 'Subscription')

@php
    use App\Support\BillingState;
    $date = fn ($at) => $at ? $studio->local($at)->format('F j, Y') : '';
    $needsAction = in_array($state->status, [BillingState::TRIAL, BillingState::GRACE, BillingState::PAUSED], true);
    $offer = $yearly ? $price.' a month, or '.$yearly.' a year,' : $price.' a month';
    $isYearly = $studio->billing_interval === 'year';
@endphp

@section('content')
<div class="page-head">
    <div>
        <h1>Your Loft subscription</h1>
        <p>{{ $offer }} for {{ $studio->name }}, with every feature. Your members’ payments go straight to your own Stripe account; this is what you pay Loft.</p>
    </div>
</div>

<div class="card narrow subscription">
    @switch($state->status)
        @case(BillingState::COMPED)
            <h2>Free <span class="badge">Comped</span></h2>
            <p class="muted" style="margin:0">Loft is free for {{ $studio->name }}. There’s nothing to pay.</p>
            @break

        @case(BillingState::SUBSCRIBED)
            @if ($state->cancelsAt)
                <h2>Subscribed <span class="badge badge-warn">Ends {{ $studio->local($state->cancelsAt)->format('M j') }}</span></h2>
                <p>Your subscription ends on {{ $date($state->cancelsAt) }}, and everything keeps running until then. Changed your mind? Renew it in the billing portal.</p>
            @elseif ($state->inStripeTrial)
                <h2>Subscribed <span class="badge">Active</span></h2>
                <p>Your free trial runs until {{ $date($state->periodEndsAt) }}. Your card is first charged then: {{ $plan }}.</p>
            @else
                <h2>Subscribed <span class="badge">Active</span></h2>
                <p>{{ ucfirst($plan) }}. Your next payment is on {{ $date($state->periodEndsAt) }}.</p>
            @endif
            <p class="small muted"><x-icon name="lock" /> Your price is locked: {{ $plan }} for as long as you stay subscribed{{ $yearly ? ' on this plan' : '' }}.</p>
            @if ($isOwner)
                <div class="actions">
                    <form method="post" action="{{ route('studio.admin.subscription.portal') }}">@csrf<button class="btn btn-secondary">Manage billing</button></form>
                    @if ($yearly && ! $isYearly && ! $state->cancelsAt)
                        <form method="post" action="{{ route('studio.admin.subscription.portal') }}">@csrf<input type="hidden" name="switch" value="1"><button class="btn btn-secondary">Switch to yearly{{ $yearlySaving ? ' ('.$yearlySaving.')' : '' }}</button></form>
                    @endif
                </div>
                <p class="small muted" style="margin:.75rem 0 0">Change your card, download invoices, {{ $yearly ? 'switch between monthly and yearly, ' : '' }}or cancel.</p>
            @endif
            @break

        @case(BillingState::TRIAL)
            @php $left = $state->trialDaysLeft(); @endphp
            <h2>Free trial <span class="badge badge-info">{{ $left === 0 ? 'Last day' : $left.' '.\Illuminate\Support\Str::plural('day', $left).' left' }}</span></h2>
            <p>Your trial ends on {{ $date($state->trialEndsAt) }}. Subscribe any time: your card isn’t charged until the trial ends.</p>
            @break

        @case(BillingState::GRACE)
            <h2>
                @if ($state->paymentFailing()) Your last payment didn’t go through
                @elseif ($state->reason === 'cancelled') Your subscription has ended
                @else Your free trial has ended
                @endif
                <span class="badge badge-warn">Pauses {{ $studio->local($state->pausesAt)->format('M j') }}</span>
            </h2>
            <p>Everything keeps working until {{ $date($state->pausesAt) }}. After that, members can’t book or pay online, and staff can only see this page, until you {{ $state->paymentFailing() ? 'update your card' : 'subscribe' }}. Nothing is deleted.</p>
            @break

        @default
            <h2>{{ $studio->name }} is paused <span class="badge badge-err">Paused</span></h2>
            <p>Members can’t book or pay online, and staff can only see this page. Nothing has been deleted: {{ $state->paymentFailing() ? 'update your card' : 'subscribe' }} and everything comes back straight away.</p>
    @endswitch

    @if ($needsAction)
        @if ($isOwner)
            @if ($state->paymentFailing())
                <form method="post" action="{{ route('studio.admin.subscription.portal') }}">@csrf<button class="btn">Update your card</button></form>
            @elseif ($yearly)
                <form method="post" action="{{ route('studio.admin.subscription.checkout') }}" class="plan-choice">@csrf
                    <div class="list" role="radiogroup" aria-label="Billing">
                        <label class="pick"><input type="radio" name="interval" value="month" @checked(old('interval', 'month') === 'month')><span class="grow"><strong>Monthly</strong><span class="muted small" style="display:block">{{ $price }} a month</span></span></label>
                        <label class="pick"><input type="radio" name="interval" value="year" @checked(old('interval') === 'year')><span class="grow"><strong>Yearly</strong><span class="muted small" style="display:block">{{ $yearly }} a year{{ $yearlySaving ? ', '.$yearlySaving : '' }}</span></span></label>
                    </div>
                    <button class="btn">Subscribe</button>
                </form>
            @else
                <form method="post" action="{{ route('studio.admin.subscription.checkout') }}">@csrf<button class="btn">Subscribe for {{ $price }} a month</button></form>
            @endif
            @if (! $configured)
                <p class="small muted" style="margin:.75rem 0 0">Subscriptions aren’t switched on for this server yet. (Operator: set <code>STRIPE_SECRET</code>.)</p>
            @else
                <p class="small muted" style="margin:.75rem 0 0">You’ll enter your card on Stripe’s secure page. Cancel any time.</p>
            @endif
        @else
            <p class="small muted" style="margin:0">Only the studio’s {{ \Illuminate\Support\Str::plural('owner', $owners->count()) }} can {{ $state->paymentFailing() ? 'update the card' : 'subscribe' }}: {{ $owners->pluck('name')->join(', ', ' or ') ?: 'ask your studio’s owner' }}.</p>
        @endif
    @endif
</div>

@if ($state->status !== BillingState::COMPED)
    <section class="card narrow mt">
        <h3>What’s included</h3>
        <ul class="included">
            <li><x-icon name="check" />Every feature: classes and waitlists, memberships and packs, family accounts, waivers, the point of sale and shop, card readers, check-in, automations, and live and on-demand classes.</li>
            @if ((float) config('loft.stripe.platform_fee_percent') <= 0)
                <li><x-icon name="check" />No Loft fees on your members’ payments. Stripe’s usual card fees apply.</li>
            @endif
            <li><x-icon name="check" />No limits on members, staff, classes or bookings.</li>
            <li><x-icon name="check" />The price you start at is the price you keep, for as long as you stay subscribed.</li>
            <li><x-icon name="check" />Cancel any time from the billing portal. If you stop, your studio pauses after {{ $graceDays }} days and nothing is deleted.</li>
        </ul>
    </section>
@endif
@endsection
