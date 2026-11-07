@extends('layouts.platform')

@section('title', 'Studio software')

@section('content')
<section class="hero">
    <h1>Run your studio, not your spreadsheets.</h1>
    <p>Class schedules, memberships and class packs, family accounts, digital waivers and check-in — with payments going straight to your own Stripe account.</p>
    <div class="actions">
        <a class="btn" href="{{ route('signup') }}">Start your studio</a>
        @auth
            <a class="btn btn-secondary" href="{{ route('studios') }}">Go to my studios</a>
        @else
            <a class="btn btn-secondary" href="{{ route('login') }}">Sign in</a>
        @endauth
    </div>
</section>

<section class="features" aria-label="Features">
    <div class="card feature"><h3>Schedule &amp; waitlists</h3><p>Weekly classes across a season and one-off workshops. Members book online; full classes fill from the waitlist automatically.</p></div>
    <div class="card feature"><h3>Memberships &amp; packs</h3><p>Monthly memberships with weekly limits, seasonal tuition, class packs and drop-ins — scoped to the classes each one covers.</p></div>
    <div class="card feature"><h3>Family accounts</h3><p>One parent account books for every child, with ages, medical notes and emergency contacts on the roster.</p></div>
    <div class="card feature"><h3>Digital waivers</h3><p>Versioned waivers signed per attendee, with a drawn signature. Publish a new version and everyone re-signs before their next class.</p></div>
    <div class="card feature"><h3>Paid directly</h3><p>Connect your own Stripe account. Checkout, receipts, failed-card emails and a self-serve billing portal are built in.</p></div>
    <div class="card feature"><h3>Tablet check-in</h3><p>Instructors mark attendance from any device. Late cancels and no-shows follow the policy you set.</p></div>
</section>

<section class="card narrow" style="margin: 0 auto">
    <h2>Up and running in an afternoon</h2>
    <ol class="steps">
        <li>Create your studio and connect Stripe.</li>
        <li>Add a season, your classes and your waiver.</li>
        <li>Set up memberships, packs and drop-ins.</li>
        <li>Share your schedule link with families.</li>
    </ol>
    <a class="btn btn-block" href="{{ route('signup') }}">Start your studio</a>
</section>
@endsection
