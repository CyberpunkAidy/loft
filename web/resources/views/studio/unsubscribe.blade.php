@extends('layouts.studio')

@section('title', 'Email preferences')

@section('content')
<div class="narrow" style="margin: 0 auto">
    <div class="card">
        <h1>Email preferences</h1>
        @if ($subscribed)
            <p>Stop getting news and offers from {{ $studio->name }} at {{ $member->email }}? You’ll still get emails about your bookings and payments.</p>
            <form method="post" action="{{ request()->fullUrl() }}">
                <button class="btn">Unsubscribe</button>
            </form>
        @else
            <p>{{ $member->email }} doesn’t get news and offers from {{ $studio->name }}. You’ll still get emails about your bookings and payments.</p>
            <p class="small muted" style="margin:0">Changed your mind? Turn them back on from your profile when you’re signed in.</p>
        @endif
    </div>
</div>
@endsection
