@extends('layouts.admin')

@section('title', 'Check-in devices')

@section('content')
<div class="page-head">
    <div>
        <h1>Check-in devices</h1>
        <p>Kiosks and readers members check themselves in at: with a tag (card or fob) or the check-in code in their account. Give out tags from a member’s page.</p>
    </div>
</div>

@if ($newDevice)
    <div class="card narrow" style="margin-bottom:1.25rem">
        <h2>{{ $newDevice['name'] }} is ready</h2>
        <p>Its device code, shown only this once. Enter it on the kiosk at <a href="{{ route('studio.kiosk') }}">{{ route('studio.kiosk') }}</a>, or give it to the app or reader that uses the API.</p>
        <p><code style="font-size:1rem;word-break:break-all">{{ $newDevice['token'] }}</code></p>
        <form method="post" action="{{ route('studio.kiosk.pair') }}">
            @csrf
            <input type="hidden" name="code" value="{{ $newDevice['token'] }}">
            <button class="btn btn-secondary">Use this browser as the kiosk</button>
        </form>
    </div>
@endif

<div class="split">
    <section class="stack">
        <h2>Scans</h2>
        <div class="filters">
            <a href="{{ route('studio.admin.access.index') }}" @class(['chip', 'active' => ! request('result')])>All</a>
            <a href="{{ route('studio.admin.access.index', ['result' => 'denied']) }}" @class(['chip', 'active' => request('result') === 'denied'])>Not let in</a>
        </div>
        @if ($events->isEmpty())
            <div class="card empty">No scans yet.</div>
        @else
            <div class="card card-flush table-wrap">
                <table class="table">
                    <thead><tr><th>When</th><th>Who</th><th>Result</th><th>Class</th><th>Where</th></tr></thead>
                    <tbody>
                    @foreach ($events as $event)
                        <tr>
                            <td class="nowrap small">{{ $studio->local($event->occurred_at)->format('M j, g:i A') }}</td>
                            <td>{{ $event->attendee?->name ?? '—' }}<div class="small muted">{{ $event->method === 'qr' ? 'Check-in code' : 'Tag' }}</div></td>
                            <td>@if ($event->result === 'granted')<span class="badge">{{ $event->reasonLabel() }}</span>@else<span class="badge badge-full">{{ $event->reasonLabel() }}</span>@endif</td>
                            <td class="small">{{ $event->booking?->classSession?->program?->title ?? '—' }}</td>
                            <td class="small muted">{{ $event->device?->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            {{ $events->links() }}
        @endif
    </section>

    <aside class="stack">
        <div class="card">
            <h2>Devices</h2>
            @if ($devices->isEmpty())
                <p class="muted small">No devices yet.</p>
            @else
                <div class="pos-lines">
                    @foreach ($devices as $device)
                        <div class="pos-line">
                            <div class="grow">
                                <strong>{{ $device->name }}</strong>
                                <div class="small muted">Code …{{ $device->token_last4 }} · {{ $device->last_seen_at ? 'last used '.$device->last_seen_at->diffForHumans() : 'not used yet' }}</div>
                            </div>
                            @if ($device->isRevoked())
                                <span class="badge badge-muted">Removed</span>
                            @else
                                <form method="post" action="{{ route('studio.admin.access.devices.revoke', ['accessDevice' => $device]) }}" class="inline" data-confirm="Remove {{ $device->name }}? Its code stops working at once.">@csrf<button class="link link-danger small">Remove</button></form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            <form method="post" action="{{ route('studio.admin.access.devices.store') }}" class="form mt">
                @csrf
                <x-field name="name" label="Add a device" required maxlength="80" placeholder="Front door kiosk" />
                <button class="btn btn-block">Add device</button>
            </form>
        </div>
        <div class="card small">
            <h3>Setting up a kiosk</h3>
            <p>Any tablet or computer with a browser. Open <a href="{{ route('studio.kiosk') }}">{{ route('studio.kiosk') }}</a> and enter the device code. A USB or Bluetooth NFC reader or QR scanner that types what it reads works as is.</p>
            <p class="muted" style="margin:0">Members check in up to {{ \App\Services\CheckIns::OPENS_MINUTES_BEFORE }} minutes before class, until it ends. Anyone with a waiver to sign is sent to the front desk.</p>
        </div>
    </aside>
</div>
@endsection
