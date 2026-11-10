@extends('layouts.platform')

@section('title', 'Pricing')
@section('description', "Loft costs {$price} a month per studio".($yearly ? ", or {$yearly} a year" : '').", with every feature included and unlimited members and staff. Free for {$trialDays} days, no card needed, and your price is locked while you stay.")

@php
    $feeLabel = rtrim(rtrim(number_format($fee, 2), '0'), '.');
    $period = $yearly ? 'month or year' : 'month';
    $included = [
        'Schedule and booking' => [
            'Weekly classes across a season, and one-off workshops',
            'Online booking, with waitlists that move up by themselves',
            'Rooms and apparatus, with a spot on each silk, pole or reformer',
            'Late-cancel and no-show rules you set',
            'Live online and hybrid classes',
        ],
        'Members and families' => [
            'One parent account for every child, with ages, medical notes and emergency contacts',
            'Digital waivers, versioned and signed by each attendee',
            'Check-in by staff, or with QR codes, cards and fobs at the door',
            'Your studio’s own booking page, in your colors, on any phone',
        ],
        'Payments and sales' => [
            'Memberships with weekly limits, season tuition, class packs and drop-ins',
            'Payments straight into your own Stripe account',
            'Failed-card emails, refunds and a billing portal for members',
            'Point of sale with Stripe card readers, and cash or check',
            'An online shop with pickup at the studio',
            'Every sale in one report, with a CSV export',
        ],
        'Keeping members' => [
            'Booking, waitlist, reminder and receipt emails',
            'Automatic emails after a first class, on birthdays and to members who’ve gone quiet',
            'Members at risk of leaving, scored each night, with the reasons',
            'Marketing consent and one-click unsubscribe',
        ],
        'Video' => [
            'An on-demand library, up to '.$videoHours.' hours per studio',
            'Videos for everyone, or only for plans that include them',
        ],
        'Getting started' => [
            'Import members, families, credits and memberships from a CSV',
            'Free help moving from another system',
            'Unlimited members, staff, classes and bookings',
            'Email support from a person',
        ],
    ];
    $compare = [
        ['Price', $price.' a month for everything', 'Often $150–500 a month for a similar set of features'],
        ['As you grow', 'The same price for 30 members or 600', 'Tiers that cost more as your member count rises'],
        ['Your sales', $fee > 0 ? $feeLabel.'% on online sales' : 'No cut of your sales', 'Some add a percentage to every card sale'],
        ['Contract', $yearly ? 'Month to month, or yearly with '.($yearlySaving ?? 'a discount') : 'Month to month', 'Yearly contracts, sometimes with a fee to leave early'],
        ['At renewal', 'Your price is locked while you stay', 'Prices that rise at renewal'],
        ['Extras', 'Everything included', 'Marketing emails, video or extra features cost more'],
        ['Leaving', 'Cancel any time, and download your sales and signed waivers', 'Some charge to export your data'],
    ];
    $faqs = array_values(array_filter([
        [
            'q' => 'Do you take a cut of my sales?',
            'a' => $fee > 0
                ? "Loft adds {$feeLabel}% to online sales. Members pay you directly through your own Stripe account, and Stripe’s usual card fees apply."
                : 'No. Members pay you directly through your own Stripe account, and the money is yours. Stripe’s usual card fees apply.',
        ],
        $yearly ? [
            'q' => 'Is there a yearly plan?',
            'a' => "Yes: {$yearly} a year".($yearlySaving ? ", which is {$yearlySaving}" : '').". Choose it when you subscribe, or switch from monthly any time in the billing portal; Stripe takes off what’s left of the month you’ve paid for.",
        ] : null,
        [
            'q' => 'Will my price go up?',
            'a' => 'Not while you stay subscribed. The price you start at is the price you keep'.($yearly ? ' on that plan' : '').'. If Loft’s price changes, the new one is only for studios that subscribe after the change.',
        ],
        [
            'q' => 'What happens after the free trial?',
            'a' => "Subscribe any time during the trial; your card isn’t charged until it ends. If you don’t, everything keeps working for {$graceDays} more days, then online booking pauses until you subscribe. Nothing is deleted.",
        ],
        [
            'q' => 'Can I cancel?',
            'a' => "Any time, from your studio’s Subscription page. Everything keeps running until the end of the {$period} you’ve paid for. There’s no contract and no fee to leave.",
        ],
        [
            'q' => 'I have more than one location. How does that work?',
            'a' => "Each location with its own schedule is its own studio, at {$price} a month each, and one login covers all of them. Several rooms in one building are one studio: add each room, and what’s rigged in it, under Rooms.",
        ],
        [
            'q' => 'Is anything limited?',
            'a' => "Members, staff, classes and bookings are unlimited. On-demand video includes up to {$videoHours} hours of stored video per studio.",
        ],
        [
            'q' => 'Do my members pay anything to Loft?',
            'a' => 'No. Members pay your prices, to your studio. Loft never adds a booking fee.',
        ],
        [
            'q' => 'Can you help me move from another system?',
            'a' => "Yes, for free. Export your client list from your old software and email it to {$supportEmail}, and we’ll import it and check it with you. Or do it yourself from Members → Import.",
        ],
    ]));
    $offer = fn (float $amount, string $name, string $unit) => [
        '@type' => 'Offer', 'name' => $name, 'price' => number_format($amount, 2, '.', ''), 'priceCurrency' => $currency,
        'priceSpecification' => ['@type' => 'UnitPriceSpecification', 'price' => number_format($amount, 2, '.', ''), 'priceCurrency' => $currency, 'unitCode' => $unit, 'referenceQuantity' => ['@type' => 'QuantitativeValue', 'value' => 1, 'unitCode' => $unit]],
    ];
@endphp

@push('head')
    <link rel="canonical" href="{{ route('pricing') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Loft">
    <meta property="og:url" content="{{ route('pricing') }}">
    <meta property="og:title" content="Loft pricing: {{ $price }} a month, everything included">
    <meta property="og:description" content="One flat price per studio{{ $yearly ? ', or '.$yearly.' a year' : '' }}. Unlimited members and staff, no cut of your sales, and your price is locked while you stay.">
    <meta property="og:image" content="{{ asset('images/og/loft-home.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'SoftwareApplication',
                'name' => 'Loft',
                'url' => route('home'),
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'offers' => array_values(array_filter([
                    $offer($monthly, 'Monthly', 'MON'),
                    $yearlyAmount ? $offer($yearlyAmount, 'Yearly', 'ANN') : null,
                ])),
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<section class="section pricing" aria-labelledby="pricing-title">
    <div class="section-head">
        <h1 id="pricing-title">One price. <span class="gradient-text">Everything included.</span></h1>
        <p>No tiers, no setup fees and no per-member charges. Try all of it free for {{ $trialDays }} days, then keep the price you start at for as long as you stay.</p>
    </div>

    <div class="plan-switch">
        @if ($yearly)
            <input type="radio" name="period" id="period-month" class="period-input" checked>
            <input type="radio" name="period" id="period-year" class="period-input">
            <div class="segmented">
                <label for="period-month">Monthly</label>
                <label for="period-year">Yearly @if ($yearlySaving)<span class="save">{{ $yearlySaving }}</span>@endif</label>
            </div>
        @endif
        <div class="card pricing-card">
            <div class="price-period price-month">
                <div class="price">{{ $price }}<small>/ month per studio</small></div>
                <p class="muted">Billed monthly. Cancel any time.</p>
            </div>
            @if ($yearly)
                <div class="price-period price-year">
                    <div class="price">{{ $yearly }}<small>/ year per studio</small></div>
                    <p class="muted">That’s {{ $yearlyMonthly }} a month, billed yearly.</p>
                </div>
            @endif
            <a class="btn btn-large btn-block" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
            <p class="small muted trial-note">{{ $trialDays }} days free, no card needed.{{ $yearly ? ' Choose monthly or yearly when you subscribe.' : '' }}</p>
            <ul class="included">
                <li><x-icon name="check" />Every feature, for every studio</li>
                <li><x-icon name="check" />Unlimited members, staff, classes and bookings</li>
                <li><x-icon name="check" />@if ($fee > 0){{ $feeLabel }}% Loft fee on online sales @else No cut of your sales @endif</li>
                <li><x-icon name="check" />No setup fee and no contract</li>
                <li><x-icon name="check" />Free help moving your members over</li>
            </ul>
        </div>
    </div>

    <div class="card lock-note">
        <span class="feature-icon"><x-icon name="lock" /></span>
        <div>
            <h2>Your price is locked</h2>
            <p>The price you start at is the price you keep, for as long as you stay subscribed. If Loft’s price goes up, the new price is only for studios that join after.</p>
        </div>
    </div>
</section>

<section class="section" aria-labelledby="included-title">
    <div class="section-head">
        <h2 id="included-title">Everything included</h2>
        <p>Every studio gets all of it, from the first day of the trial.</p>
    </div>
    <div class="included-grid">
        @foreach ($included as $group => $items)
            <div class="card">
                <h3>{{ $group }}</h3>
                <ul class="included">
                    @foreach ($items as $item)
                        <li><x-icon name="check" />{{ $item }}</li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </div>
</section>

<section class="section" aria-labelledby="fees-title">
    <div class="section-head">
        <h2 id="fees-title">What else you’ll pay</h2>
        <p>Only card processing, and that goes to Stripe, not Loft.</p>
    </div>
    <div class="price-grid">
        <div class="card fees-card">
            <h3><x-icon name="credit-card" /> Card fees go to Stripe</h3>
            <p>Members pay you through your own Stripe account. Stripe takes its usual fee on each payment (in the US, 2.9% + 30¢ for an online card payment) and pays the rest into your bank account.</p>
            <p>@if ($fee > 0)Loft adds {{ $feeLabel }}% to online sales.@else Loft takes nothing from your sales, and members never pay Loft a booking fee.@endif</p>
            <p class="small muted">Stripe’s fees depend on your country and how people pay. <a href="https://stripe.com/pricing" rel="noopener">See Stripe’s pricing</a>. Loft’s price is before any sales tax or VAT that applies to your studio.</p>
        </div>
        @include('platform._calculator')
    </div>
</section>

<section class="section" aria-labelledby="compare-title">
    <div class="section-head">
        <h2 id="compare-title">How Loft’s pricing compares</h2>
        <p>What studios often run into with other software, and what Loft does instead.</p>
    </div>
    <div class="card card-flush compare-wrap">
        <table class="compare">
            <thead>
                <tr><th scope="col"><span class="sr-only">What</span></th><th scope="col">Loft</th><th scope="col">Often elsewhere</th></tr>
            </thead>
            <tbody>
                @foreach ($compare as [$what, $loft, $elsewhere])
                    <tr><th scope="row">{{ $what }}</th><td><x-icon name="check" />{{ $loft }}</td><td>{{ $elsewhere }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>

<section class="section" aria-labelledby="faq-title">
    <div class="section-head">
        <h2 id="faq-title">Questions</h2>
    </div>
    <div class="faq-list">
        @foreach ($faqs as $faq)
            <details @if ($loop->first) open @endif>
                <summary>{{ $faq['q'] }}</summary>
                <p>{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </div>
</section>

<section class="cta">
    <h2>Ready when your students are.</h2>
    <p>Set up your schedule today; families book, sign and pay from one link.</p>
    <a class="btn btn-large" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
    <p class="cta-help"><x-icon name="life-buoy" /> Questions first? Email <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
</section>
@endsection
