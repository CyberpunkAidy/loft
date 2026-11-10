@extends('layouts.base')

@section('title', 'Check in')
@section('body-class', 'kiosk-body')

@if ($result)
    @push('head')<meta http-equiv="refresh" content="5;url={{ route('studio.kiosk') }}">@endpush
@endif

@section('body')
<main class="kiosk">
    <div class="kiosk-card">
        <div class="kiosk-brand"><x-studio-brand :studio="$studio" :height="56" :max-width="320" /></div>

        @if ($result)
            <div @class(['kiosk-result', 'is-granted' => $result['granted'], 'is-denied' => ! $result['granted']]) role="status">
                <strong>{{ $result['granted'] ? ($result['name'] ? "Hi {$result['name']}!" : 'Checked in') : 'Not checked in' }}</strong>
                <p>{{ $result['message'] }}</p>
            </div>
        @else
            <x-icon name="scan-line" class="kiosk-icon" />
            <h1>Check in</h1>
            <p>Tap your card or fob on the reader, or scan your check-in code from the app.</p>
        @endif

        <form method="post" action="{{ route('studio.kiosk.scan') }}" class="kiosk-scan">
            @csrf
            <label class="sr-only" for="kiosk-credential">Card or code</label>
            <input id="kiosk-credential" name="credential" autocomplete="off" autofocus required placeholder="Waiting for a scan…" aria-describedby="kiosk-help">
            <button class="btn btn-secondary btn-small">Check in</button>
        </form>
        <p id="kiosk-help" class="small muted">No card? Ask at the front desk.</p>
    </div>
    <div class="kiosk-foot small muted">{{ $device->name }} ·
        <form method="post" action="{{ route('studio.kiosk.unpair') }}" class="inline" data-confirm="Remove this kiosk from this browser?">@csrf<button class="link small">Remove from this browser</button></form>
    </div>
</main>
<script>
    // Readers and scanners type into the box: keep it focused, even after a tap elsewhere.
    (function () {
        var input = document.getElementById('kiosk-credential');
        setInterval(function () { if (document.activeElement !== input) input.focus(); }, 1500);
    })();
</script>
@endsection
