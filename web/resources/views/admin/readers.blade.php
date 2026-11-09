@extends('layouts.admin')

@section('title', 'Card readers')

@section('content')
<p class="small"><a href="{{ route('studio.admin.payments') }}">‹ Payments</a></p>
<div class="page-head">
    <div>
        <h1>Card readers</h1>
        <p>Stripe Terminal readers at the front desk. Charge the point of sale’s cart on one, and the money goes to your Stripe account like online payments.</p>
    </div>
</div>
@if ($error)<div class="alert alert-error">Stripe: {{ $error }}</div>@endif

@if (! $studio->stripeReady())
    <div class="card narrow">
        <h2>Connect Stripe first</h2>
        <p class="muted" style="margin:0">Card readers take payments into your Stripe account. <a href="{{ route('studio.admin.payments') }}">Connect Stripe</a>, then come back here.</p>
    </div>
@elseif (! $studio->stripe_terminal_location_id)
    <div class="card narrow">
        <h2>Where are your readers?</h2>
        <p>Stripe needs the studio’s address for its card readers. You only do this once.</p>
        <form method="post" action="{{ route('studio.admin.payments.readers.location') }}" class="form">
            @csrf
            <x-field name="line1" label="Street address" required maxlength="200" autocomplete="address-line1" />
            <x-field name="line2" label="Suite or unit (optional)" maxlength="200" autocomplete="address-line2" />
            <div class="grid-2">
                <x-field name="city" label="City" required maxlength="100" autocomplete="address-level2" />
                <x-field name="state" label="State or region" maxlength="100" autocomplete="address-level1" />
            </div>
            <div class="grid-2">
                <x-field name="postal_code" label="ZIP or postal code" required maxlength="20" autocomplete="postal-code" />
                <x-select name="country" label="Country" :options="$countries" value="US" />
            </div>
            <button class="btn">Save address</button>
        </form>
    </div>
@else
    @if ($readers->isEmpty())
        <div class="card empty">No readers yet. Pair one below.</div>
    @else
        <div class="card card-flush table-wrap">
            <table class="table">
                <thead><tr><th>Reader</th><th>Model</th><th>Serial number</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @foreach ($readers as $reader)
                    @php $status = $live[$reader->stripe_reader_id]['status'] ?? null; @endphp
                    <tr>
                        <td><strong>{{ $reader->label }}</strong></td>
                        <td class="small">{{ $reader->deviceLabel() }}</td>
                        <td class="small muted">{{ $reader->serial_number ?? '—' }}</td>
                        <td>@if ($status === 'online')<span class="badge">Online</span>@elseif ($status === 'offline')<span class="badge badge-muted">Offline</span>@else<span class="muted small">Unknown</span>@endif</td>
                        <td class="right">
                            <form method="post" action="{{ route('studio.admin.payments.readers.destroy', ['cardReader' => $reader]) }}" class="inline" data-confirm="Remove {{ $reader->label }}? You can pair it again later with a new code.">
                                @csrf @method('delete')
                                <button class="link link-danger small">Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="card narrow mt">
        <h2>Pair a reader</h2>
        <p>On the reader, swipe right from the left edge, open <strong>Settings</strong>, enter the admin PIN (07139 by default) and choose <strong>Generate pairing code</strong>. Enter the code it shows.</p>
        @if ($testMode)
            <div class="alert alert-info"><div>Test mode: pair a simulated reader with the code <code>simulated-wpe</code>. The point of sale can then tap a test card for you.</div></div>
        @endif
        <form method="post" action="{{ route('studio.admin.payments.readers.store') }}" class="form">
            @csrf
            <div class="grid-2">
                <x-field name="code" label="Pairing code" required maxlength="60" placeholder="sepia-cerulean-aqua" autocomplete="off" />
                <x-field name="label" label="Name" required maxlength="80" placeholder="Front desk" />
            </div>
            <button class="btn">Pair reader</button>
        </form>
        <p class="small muted" style="margin:.8rem 0 0">Readers work with the BBPOS WisePOS E and the Stripe Reader S700; order them in your <a href="https://dashboard.stripe.com/terminal/shop" target="_blank" rel="noopener">Stripe Dashboard ↗</a>.</p>
    </div>
@endif
@endsection
