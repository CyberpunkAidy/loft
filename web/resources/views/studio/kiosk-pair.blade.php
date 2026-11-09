@extends('layouts.base')

@section('title', 'Check-in kiosk')
@section('body-class', 'kiosk-body')

@section('body')
<main class="kiosk">
    <div class="kiosk-card">
        <h1>Set up this check-in kiosk</h1>
        <p>Enter the device code from <strong>Admin → Check-in devices</strong> at {{ $studio->name }}. This browser stays set up; nobody needs to stay signed in.</p>
        <form method="post" action="{{ route('studio.kiosk.pair') }}" class="form">
            @csrf
            <x-field name="code" label="Device code" required autocomplete="off" placeholder="loftdev_…" />
            <button class="btn btn-block">Set up kiosk</button>
        </form>
    </div>
</main>
@endsection
