@extends('mail.layout')
@php
    $accent = $studio->accent();
    $ink = $studio->accentInk();
    $link = $studio->accentText();
@endphp

@section('title', $headline)
@section('preheader', $lines[0] ?? $headline)
@section('bar', "background:{$accent};")

@section('header')
    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
        <tr>
            <td width="34" height="34" align="center" bgcolor="{{ $accent }}" style="width:34px;height:34px;border-radius:11px;background:{{ $accent }};color:{{ $ink }};font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:700;line-height:34px;text-align:center;">{{ $studio->initial() }}</td>
            <td style="padding-left:11px;font-family:'Bricolage Grotesque Variable','Inter Variable',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:17px;font-weight:650;letter-spacing:-0.015em;color:#17141f;">{{ $studio->name }}</td>
        </tr>
    </table>
@endsection

@section('content')
    <x-mail.headline>{{ $headline }}</x-mail.headline>
    @foreach ($lines as $line)
        <p style="margin:0 0 12px;">{{ $line }}</p>
    @endforeach

    @if ($details)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0 6px;background:#faf9f7;border:1px solid #e9e6e0;border-radius:14px;border-collapse:separate;">
            @foreach ($details as $label => $value)
                <tr>
                    <td style="padding:11px 16px;{{ $loop->first ? '' : 'border-top:1px solid #efece7;' }}width:96px;vertical-align:top;font-size:13px;color:#6c6776;">{{ $label }}</td>
                    <td style="padding:11px 16px 11px 0;{{ $loop->first ? '' : 'border-top:1px solid #efece7;' }}vertical-align:top;font-size:15px;font-weight:600;color:#17141f;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($actionUrl)
        <x-mail.button :url="$actionUrl" :background="$accent" :color="$ink">{{ $actionLabel }}</x-mail.button>
    @endif

    @foreach ($after as $line)
        <p style="margin:18px 0 0;font-size:14px;color:#6c6776;">{{ $line }}</p>
    @endforeach
@endsection

@section('footer')
    <p style="margin:0 0 6px;">Manage your classes and billing in <a href="{{ route('studio.account', $studio) }}" style="color:{{ $link }};">your account</a>.@if ($studio->contact_email) Questions? Just reply to this email.@endif</p>
    <p style="margin:0 0 14px;">{{ $studio->name }}@if ($studio->contact_email) · <a href="mailto:{{ $studio->contact_email }}" style="color:#6c6776;">{{ $studio->contact_email }}</a>@endif</p>
    <a href="{{ route('home') }}" style="display:inline-block;color:#9c97a5;text-decoration:none;font-size:12px;">
        <span style="vertical-align:middle;">Powered by</span>
        <img src="{{ asset('images/loft-logo-email.png') }}" width="44" height="18" alt="{{ config('app.name') }}" style="vertical-align:middle;border:0;margin-left:3px;">
    </a>
@endsection
