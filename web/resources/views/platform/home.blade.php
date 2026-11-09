@extends('layouts.platform')

@section('title', 'Studio software')

@section('content')
<section class="hero">
    <div>
        <span class="eyebrow"><x-icon name="sparkles" /> Studio software for dance, aerial and fitness</span>
        <h1>Run your studio, <span class="gradient-text">not your spreadsheets.</span></h1>
        <p>Class schedules, memberships and class packs, family accounts, digital waivers and check-in — with payments going straight to your own Stripe account.</p>
        <div class="actions">
            <a class="btn btn-large" href="{{ route('signup') }}">Start your studio <x-icon name="arrow-right" /></a>
            @auth
                <a class="btn btn-large btn-secondary" href="{{ route('studios') }}">Go to my studios</a>
            @else
                <a class="btn btn-large btn-secondary" href="{{ route('login') }}">Sign in</a>
            @endauth
        </div>
        <div class="hero-note">
            <span><x-icon name="check" /> Free for {{ $trialDays }} days, no card needed</span>
            <span><x-icon name="check" /> Paid straight to your Stripe</span>
            <span><x-icon name="check" /> Works on any device</span>
        </div>
    </div>

    {{-- A static picture of a studio's schedule, for illustration. --}}
    <div class="preview" aria-hidden="true">
        <div class="preview-window">
            <div class="preview-bar">
                <span class="brand-mark">S</span>
                <strong>Skyline Aerial Arts</strong>
                <span class="chip active">This week</span>
            </div>
            <div class="preview-day">Tuesday</div>
            <div class="preview-slot">
                <time>4:30 PM</time>
                <span><b>Kids Aerial Silks</b><small>Ages 6–11 · Cam</small></span>
                <span class="badge">3 spots left</span>
            </div>
            <div class="preview-slot">
                <time>6:30 PM</time>
                <span><b>Adult Silks Basics</b><small>Drop-in $28</small></span>
                <span class="badge badge-full">Waitlist</span>
            </div>
            <div class="preview-slot">
                <time>7:45 PM</time>
                <span><b>Teen Lyra</b><small>Ages 12–17 · Maya</small></span>
                <span class="badge badge-mine">Booked</span>
            </div>
        </div>
        <div class="preview-toast">
            <span class="tick"><x-icon name="check" /></span>
            <span>You’re booked!<small>Kids Aerial Silks · Tue 4:30 PM</small></span>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="features-title">
    <div class="section-head">
        <h2 id="features-title">Everything your studio runs on</h2>
        <p>One place for the schedule, the people and the payments, built around how studios actually work.</p>
    </div>
    <div class="features">
        <div class="card feature"><span class="feature-icon"><x-icon name="calendar-clock" /></span><h3>Schedule &amp; waitlists</h3><p>Weekly classes across a season and one-off workshops. Members book online; full classes fill from the waitlist automatically.</p></div>
        <div class="card feature"><span class="feature-icon"><x-icon name="ticket" /></span><h3>Memberships &amp; packs</h3><p>Monthly memberships with weekly limits, seasonal tuition, class packs and drop-ins — scoped to the classes each one covers.</p></div>
        <div class="card feature"><span class="feature-icon"><x-icon name="users-round" /></span><h3>Family accounts</h3><p>One parent account books for every child, with ages, medical notes and emergency contacts on the roster.</p></div>
        <div class="card feature"><span class="feature-icon"><x-icon name="file-pen-line" /></span><h3>Digital waivers</h3><p>Versioned waivers signed per attendee, with a drawn signature. Publish a new version and everyone re-signs before their next class.</p></div>
        <div class="card feature"><span class="feature-icon"><x-icon name="wallet" /></span><h3>Paid directly</h3><p>Connect your own Stripe account. Checkout, receipts, failed-card emails and a self-serve billing portal are built in.</p></div>
        <div class="card feature"><span class="feature-icon"><x-icon name="tablet-smartphone" /></span><h3>Tablet check-in</h3><p>Instructors mark attendance from any device. Late cancels and no-shows follow the policy you set.</p></div>
    </div>
</section>

<section class="section" aria-labelledby="steps-title">
    <div class="section-head">
        <h2 id="steps-title">Up and running in an afternoon</h2>
        <p>No migration project. Set it up once and share one link with your families.</p>
    </div>
    <ol class="steps">
        <li>Create your studio and connect Stripe.</li>
        <li>Add a season, your classes and your waiver.</li>
        <li>Set up memberships, packs and drop-ins.</li>
        <li>Share your schedule link with families.</li>
    </ol>
</section>

<section class="cta">
    <h2>Ready when your students are.</h2>
    <p>Set up your schedule today. Families book, sign and pay from one link. Free for {{ $trialDays }} days, then {{ $price }} a month. <a href="{{ route('pricing') }}">See pricing</a></p>
    <a class="btn btn-large" href="{{ route('signup') }}">Start your studio <x-icon name="arrow-right" /></a>
</section>
@endsection
