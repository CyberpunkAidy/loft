@extends('layouts.studio')

@section('title', $waiver->title)

@section('content')
<div style="max-width: 760px; margin: 0 auto">
    <p class="small"><a href="{{ $return ?? route('studio.account.waivers') }}">‹ Back</a></p>
    <div class="card stack">
        <h1>{{ $waiver->title }}</h1>
        <p class="muted" style="margin:0">Signing for <strong>{{ $attendee->name }}</strong> · version {{ $waiver->version }}</p>
        @if ($status['signed'])
            <div class="alert alert-success" style="margin:0">Already signed on {{ $studio->local($status['signed_at'])->format('M j, Y') }}. You can sign again if anything has changed.</div>
        @endif
        <div class="waiver-text" tabindex="0">
            @foreach ($waiver->paragraphs() as $para)
                <p>{!! nl2br(e($para)) !!}</p>
            @endforeach
        </div>
        <form method="post" action="{{ route('studio.waiver', ['attendee' => $attendee]) }}" class="form">
            @csrf
            @if ($return)<input type="hidden" name="return" value="{{ $return }}">@endif
            <x-field name="signed_name" :label="$isMinor ? 'Parent or guardian’s full legal name' : 'Your full legal name'" autocomplete="name" required minlength="3" />
            <div class="field">
                <span>Draw your signature <span class="hint">(optional)</span></span>
                <canvas id="sigpad" class="sigpad" data-sigpad="signature_png" aria-label="Signature pad"></canvas>
                <input type="hidden" name="signature_png" id="signature_png">
                <button type="button" class="link small" data-sigpad-clear="sigpad" style="align-self:flex-start">Clear</button>
            </div>
            <label class="check">
                <input type="checkbox" name="agree" value="1" required>
                <span>I have read and agree to this waiver.@if ($isMinor) I confirm I am {{ $attendee->first_name }}’s parent or legal guardian.@endif</span>
            </label>
            @error('agree')<small class="error-text">{{ $message }}</small>@enderror
            <button class="btn">Sign waiver</button>
        </form>
    </div>
</div>
@endsection
