@extends('layouts.platform')

@section('title', 'Setting up your demo')

@push('head')<noscript><meta http-equiv="refresh" content="5"></noscript>@endpush

@section('content')
<div class="narrow demo-page" style="margin: 0 auto">
    <div class="card demo-wait" data-demo-wait="{{ route('demo.status') }}" aria-live="polite">
        <h1>{{ $ready ? 'Your demo studio is ready' : 'Setting up your studio…' }}</h1>
        <p>We’re making your own copy of Skyline Aerial Arts: its classes, members, bookings and sales. When lots of people are trying Loft, this takes about half a minute. It opens by itself.</p>
        <form method="post" action="{{ route('demo.store') }}" data-demo-start>@csrf
            <button class="btn btn-large btn-block" @disabled(! $ready)>{{ $ready ? 'Open the demo studio' : 'Getting it ready…' }}</button>
        </form>
        <p class="small muted" data-demo-slow hidden style="margin:.9rem 0 0">This is taking longer than usual. Keep this page open, or <a href="{{ route('demo') }}">try again</a> in a few minutes.</p>
    </div>
</div>
@endsection
