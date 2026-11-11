@extends('layouts.platform')

@section('title', 'Try the demo')
@section('description', 'Try Loft with your own copy of a sample aerial and pole studio: classes, members, bookings and sales, ready to click through. No sign-up, nothing to install.')

@section('content')
<div class="narrow demo-page" style="margin: 0 auto">
    <div class="page-head">
        <div>
            <h1>Try Loft with a sample studio</h1>
            <p>Skyline Aerial Arts is an aerial and pole studio with a month of classes, members, bookings and sales. You get your own copy, signed in as its owner. Change anything you like.</p>
        </div>
    </div>
    <div class="card">
        <ul class="demo-points">
            <li><x-icon name="layout-dashboard" /><span><strong>Run it as the owner:</strong> the dashboard, check-in, members at risk of leaving, sales, the point of sale and every setting.</span></li>
            <li><x-icon name="users-round" /><span><strong>Switch to a member</strong> with two kids and a membership: book a silk, sign the waiver, see a check-in code.</span></li>
            <li><x-icon name="shield-check" /><span><strong>Nothing is real:</strong> no emails go out and no cards are charged. Your copy is private and deleted after {{ $hours }} hours.</span></li>
        </ul>
        @if ($current)
            <div class="actions">
                <a class="btn btn-large" href="{{ route('studio.admin.dashboard', ['studio' => $current['studio']]) }}">Back to your demo <x-icon name="arrow-right" /></a>
                <form method="post" action="{{ route('demo.store') }}" data-demo-start>@csrf<button class="btn btn-large btn-secondary">Start a fresh one</button></form>
            </div>
        @else
            <form method="post" action="{{ route('demo.store') }}" data-demo-start>@csrf
                <button class="btn btn-large btn-block">Open the demo studio <x-icon name="arrow-right" /></button>
            </form>
        @endif
        <p class="small muted" style="margin:.9rem 0 0">No sign-up needed.@auth @unless (auth()->user()->isDemo()) Opening the demo signs you out of your account here. @endunless @endauth</p>
    </div>
    <p class="small muted" style="text-align:center;margin-top:1.25rem">Ready for the real thing? <a href="{{ route('signup') }}">Start your studio</a>: free for {{ (int) config('loft.billing.trial_days') }} days, no card needed.</p>
</div>
@endsection
