@extends('mail.layout')

@section('title', 'Choose your '.config('app.name').' password')
@section('preheader', 'Use this link to choose a password for your '.config('app.name').' account.')
@section('bar', 'background:#5b4bf5;background-image:linear-gradient(90deg,#5b4bf5,#c05ad8,#ff8a5c);')

@section('header')
    <a href="{{ route('home') }}" style="text-decoration:none;"><img src="{{ asset('images/loft-logo-email.png') }}" width="78" height="32" alt="{{ config('app.name') }}" style="display:block;border:0;"></a>
@endsection

@section('content')
    <x-mail.headline>Choose your password</x-mail.headline>
    <p style="margin:0 0 12px;">Use the button below to choose a password for your {{ config('app.name') }} account. It’s one login for every studio you belong to.</p>
    <x-mail.button :url="$url" background="#5b4bf5">Choose a password</x-mail.button>
    <p style="margin:18px 0 0;font-size:14px;color:#6c6776;">The link works for {{ $minutes }} minutes. If you didn’t ask for it, you can ignore this email; your password won’t change.</p>
    <p style="margin:18px 0 0;font-size:13px;color:#6c6776;">Button not working? Paste this link into your browser:<br><a href="{{ $url }}" style="color:#5b4bf5;word-break:break-all;">{{ $url }}</a></p>
@endsection

@section('footer')
    <p style="margin:0;">{{ config('app.name') }} · Scheduling, memberships, waivers and billing for studios.</p>
@endsection
