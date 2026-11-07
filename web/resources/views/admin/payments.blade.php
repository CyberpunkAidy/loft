@extends('layouts.admin')

@section('title', 'Payments')

@section('content')
<div class="page-head">
    <div>
        <h1>Payments</h1>
        <p>Members pay you directly through your own Stripe account. Payouts, refunds and disputes are managed in your Stripe dashboard.</p>
    </div>
</div>
@if ($error)<div class="alert alert-error">Stripe: {{ $error }}</div>@endif

<div class="card narrow">
    @if (! $configured)
        <h2>Not available yet</h2>
        <p class="muted" style="margin:0">Online payments haven’t been switched on for this server. (Operator: set <code>STRIPE_SECRET</code> and <code>STRIPE_WEBHOOK_SECRET</code>.)</p>
    @elseif ($studio->stripeReady())
        <h2>Stripe is connected <span class="badge">Live</span></h2>
        <p>Account <code>{{ $studio->stripe_account_id }}</code>. Members can buy plans, and failed renewals pause bookings automatically.</p>
        <div class="actions">
            <a class="btn btn-secondary" href="https://dashboard.stripe.com/" target="_blank" rel="noopener">Open Stripe dashboard ↗</a>
            <a class="btn btn-secondary" href="{{ route('studio.admin.plans.index') }}">Plans &amp; pricing</a>
        </div>
    @elseif ($studio->stripe_account_id)
        <h2>Finish connecting Stripe</h2>
        <p>Stripe needs a few more details{{ $studio->stripe_details_submitted ? ' and is reviewing your account' : '' }} before you can take payments.</p>
        <form method="post" action="{{ route('studio.admin.payments.connect') }}">@csrf<button class="btn">Continue Stripe setup</button></form>
    @else
        <h2>Connect Stripe</h2>
        <p>Use your existing Stripe account or create one. It takes about five minutes; you’ll need your business and bank details.</p>
        <form method="post" action="{{ route('studio.admin.payments.connect') }}">@csrf<button class="btn">Connect Stripe</button></form>
    @endif
</div>
@endsection
