@extends('layouts.platform')

@section('title', 'Pricing')

@section('content')
<section class="section pricing" aria-labelledby="pricing-title">
    <div class="section-head">
        <h1 id="pricing-title">One price. <span class="gradient-text">Everything included.</span></h1>
        <p>No tiers, no setup fees and no per-member charges. Try all of it free for {{ $trialDays }} days.</p>
    </div>

    <div class="card pricing-card">
        <div class="price">{{ $price }}<small>/ month per studio</small></div>
        <p class="muted">{{ $trialDays }}-day free trial. No card needed to start.</p>
        <a class="btn btn-large btn-block" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
        <ul class="included">
            <li><x-icon name="check" />Class schedules, seasons, waitlists and online booking</li>
            <li><x-icon name="check" />Memberships, class packs, drop-ins and season tuition</li>
            <li><x-icon name="check" />Family accounts, digital waivers and check-in</li>
            <li><x-icon name="check" />Rooms and apparatus, with spots on each</li>
            <li><x-icon name="check" />Point of sale, online shop and card readers</li>
            <li><x-icon name="check" />Live online classes and an on-demand video library</li>
            <li><x-icon name="check" />Automated emails and members-at-risk alerts</li>
            <li><x-icon name="check" />Unlimited members and staff</li>
        </ul>
    </div>
</section>

<section class="section" aria-labelledby="faq-title">
    <div class="section-head">
        <h2 id="faq-title">Questions</h2>
    </div>
    <div class="faq">
        <div class="card">
            <h3>Do you take a cut of my sales?</h3>
            @if ($fee > 0)
                <p>Loft adds {{ rtrim(rtrim(number_format($fee, 2), '0'), '.') }}% to online sales. Members pay you directly through your own Stripe account, and Stripe’s usual card fees apply.</p>
            @else
                <p>No. Members pay you directly through your own Stripe account, and the money is yours. Stripe’s usual card fees apply.</p>
            @endif
        </div>
        <div class="card">
            <h3>What happens after the free trial?</h3>
            <p>Subscribe any time during the trial; your card isn’t charged until it ends. If you don’t, everything keeps working for {{ $graceDays }} more days, then online booking pauses until you subscribe. Nothing is deleted.</p>
        </div>
        <div class="card">
            <h3>Can I cancel?</h3>
            <p>Any time, from your studio’s Subscription page. Everything keeps running until the end of the month you’ve paid for.</p>
        </div>
        <div class="card">
            <h3>Is anything limited?</h3>
            <p>Members, staff, classes and bookings are unlimited. On-demand video includes up to {{ $videoHours }} hours of stored video per studio.</p>
        </div>
    </div>
</section>

<section class="cta">
    <h2>Ready when your students are.</h2>
    <p>Set up your schedule today. Families book, sign and pay from one link.</p>
    <a class="btn btn-large" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
</section>
@endsection
