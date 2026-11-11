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
    @if ($studio->isDemo())
        <h2>Payments are off in the demo</h2>
        <p class="muted" style="margin:0">Nothing here charges a card. In your own studio, you connect your Stripe account on this page in a few minutes, and members pay you directly: plans, drop-ins, the shop and the front desk.</p>
    @elseif (! $configured)
        <h2>Not available yet</h2>
        <p class="muted" style="margin:0">Online payments haven’t been switched on for this server. (Operator: set <code>STRIPE_SECRET</code> and <code>STRIPE_WEBHOOK_SECRET</code>.)</p>
    @elseif ($studio->stripeReady())
        <h2>Stripe is connected <span class="badge">Live</span></h2>
        <p>Account <code>{{ $studio->stripe_account_id }}</code>. Members can buy plans, and failed renewals pause bookings automatically.</p>
        <div class="actions">
            <a class="btn btn-secondary" href="https://dashboard.stripe.com/" target="_blank" rel="noopener">Open Stripe dashboard ↗</a>
            <a class="btn btn-secondary" href="{{ route('studio.admin.plans.index') }}">Plans &amp; pricing</a>
            <a class="btn btn-secondary" href="{{ route('studio.admin.payments.readers') }}">Card readers</a>
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

<section class="mt">
    <div class="page-head" style="margin-bottom:1rem">
        <div>
            <h2 style="margin:0">Payments recorded in person</h2>
            <p>Card readers, cash, checks, other card terminals, transfers and payment apps, plus comps: from the <a href="{{ route('studio.admin.pos') }}">point of sale</a>, or “Add a plan” on a member’s page. Every sale, online and in person, is under <a href="{{ route('studio.admin.sales.index') }}">Sales</a>.</p>
        </div>
    </div>
    <div class="stats">
        <div class="stat"><strong>{{ \App\Support\Money::format($monthTotal, $studio->currency) }}</strong><span>Taken in person this month</span></div>
        <div class="stat"><strong>{{ $monthCount }}</strong><span>{{ \Illuminate\Support\Str::plural('Sale', $monthCount) }} in person this month</span></div>
    </div>
    @if ($orders->isEmpty())
        <div class="card empty">Nothing taken in person yet. Use the point of sale, or “Add a plan” on a member’s page.</div>
    @else
        @include('admin.orders._table', ['orders' => $orders, 'showMember' => true])
        {{ $orders->links() }}
    @endif
</section>
@endsection
