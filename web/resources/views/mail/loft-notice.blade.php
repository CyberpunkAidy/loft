@extends('mail.layout')

@section('title', $headline)
@section('preheader', $lines[0] ?? $headline)
@section('bar', 'background:#5b4bf5;background-image:linear-gradient(90deg,#5b4bf5,#c05ad8,#ff8a5c);')

@section('header')
    <a href="{{ route('home') }}" style="text-decoration:none;"><img src="{{ asset('images/loft-logo-email.png') }}" width="78" height="32" alt="{{ config('app.name') }}" style="display:block;border:0;"></a>
@endsection

@section('content')
    <x-mail.headline>{{ $headline }}</x-mail.headline>
    @foreach ($lines as $line)
        <p style="margin:0 0 12px;">{{ $line }}</p>
    @endforeach
    @if ($actionUrl)
        <x-mail.button :url="$actionUrl" background="#5b4bf5">{{ $actionLabel }}</x-mail.button>
    @endif
    @foreach ($after as $line)
        <p style="margin:18px 0 0;font-size:14px;color:#6c6776;">{{ $line }}</p>
    @endforeach
@endsection

@section('footer')
    <p style="margin:0 0 6px;">{{ $footerLine ?? 'You’re getting this because you own '.$studio->name.' on '.config('app.name').'.' }}</p>
    <p style="margin:0;">{{ config('app.name') }} · Scheduling, memberships, waivers and billing for studios.</p>
@endsection
