@extends('layouts.platform')

@section('title', 'Studio software for one flat price')
@section('description', "Booking, memberships, waivers, check-in, point of sale and video for class-based studios. {$price} a month, unlimited members and staff, paid straight to your own Stripe account. Free for {$trialDays} days.")

@php
    $money = fn ($amount) => str_replace('.00', '', \App\Support\Money::format((int) round($amount * 100), strtolower($currency)));
    $feeLabel = rtrim(rtrim(number_format($fee, 2), '0'), '.');
    $faqs = [
        [
            'q' => 'Do you take a cut of my sales?',
            'a' => $fee > 0
                ? "Loft adds {$feeLabel}% to online sales. Members pay you through your own Stripe account, and Stripe’s usual card fees apply (in the US, 2.9% + 30¢ a charge)."
                : 'No. Members pay you through your own Stripe account, so the money goes straight to you. Stripe’s usual card fees apply, as they would anywhere (in the US, 2.9% + 30¢ a charge).',
        ],
        [
            'q' => 'Can you move my members over from my old system?',
            'a' => "Yes, for free. Export your client list from your old software and email it to {$supportEmail}, and we’ll import it and check it with you. Or do it yourself: Loft matches the columns, shows you exactly what will happen before anything changes, and lets you undo an import for 30 days. Class credits come across, and members renew their memberships in Loft when the time they’ve paid for runs out.",
        ],
        [
            'q' => 'Do my members need to download an app?',
            'a' => 'No. Members book, sign waivers and pay from your studio’s page on any phone or computer. There’s nothing to install.',
        ],
        [
            'q' => 'What do I need to get started?',
            'a' => 'An email address and an afternoon. To take payments, connect your Stripe account from your studio’s Payments page, or create one there in a few minutes.',
        ],
        [
            'q' => 'What happens after the free trial?',
            'a' => "Subscribe any time during the {$trialDays} days; your card isn’t charged until the trial ends. If you don’t, everything keeps working for {$graceDays} more days, then online booking pauses until you subscribe. Nothing is deleted.",
        ],
        [
            'q' => 'Can I leave?',
            'a' => 'Any time. There’s no contract: cancel from your studio’s Subscription page and Loft keeps working until the end of the month you’ve paid for. Your payments and customers stay in your own Stripe account, and you can download your sales and signed waivers whenever you like.',
        ],
        [
            'q' => 'Who do I ask when something goes wrong?',
            'a' => "Email {$supportEmail}. A person reads and answers every message.",
        ],
    ];
@endphp

@push('head')
    <link rel="canonical" href="{{ route('home') }}">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Loft">
    <meta property="og:url" content="{{ route('home') }}">
    <meta property="og:title" content="Loft: your whole studio, one flat price">
    <meta property="og:description" content="Booking, memberships, waivers, check-in, point of sale and video for {{ $price }} a month. Unlimited members and staff, paid straight to your Stripe.">
    <meta property="og:image" content="{{ asset('images/og/loft-home.jpg') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="Loft’s studio dashboard, with the price: {{ $price }} a month, everything included">
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
                'description' => 'Studio management software: class booking, memberships, waivers, check-in, point of sale and video for dance, yoga, Pilates, aerial, pole and fitness studios.',
                'offers' => ['@type' => 'Offer', 'price' => number_format($monthly, 2, '.', ''), 'priceCurrency' => $currency, 'description' => "Per studio, per month. Free for {$trialDays} days."],
                'publisher' => ['@type' => 'Organization', 'name' => config('loft.legal.entity')],
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => array_map(fn ($f) => ['@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']]], $faqs),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
<section class="home-hero" aria-labelledby="hero-title">
    <span class="eyebrow"><x-icon name="sparkles" /> For aerial, pole, Pilates, yoga, dance and fitness studios</span>
    <h1 id="hero-title">Your whole studio, <span class="gradient-text">one flat price.</span></h1>
    <p class="lead">Booking, memberships, waivers, check-in, point of sale and video in one place, for {{ $price }} a month. Unlimited members and staff, and members pay you straight into your own Stripe account.</p>
    <div class="actions">
        <a class="btn btn-large" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
        @auth
            <a class="btn btn-large btn-secondary" href="{{ route('studios') }}">Go to my studios</a>
        @else
            <a class="btn btn-large btn-secondary" href="#tour">See how it works</a>
        @endauth
    </div>
    <p class="hero-note"><span><x-icon name="check" /> Free for {{ $trialDays }} days</span><span><x-icon name="check" /> No card needed</span><span><x-icon name="check" /> Cancel any time</span></p>

    <div class="hero-shots">
        <figure class="shot shot-browser">
            <span class="shot-bar" aria-hidden="true"><i></i><i></i><i></i></span>
            <img src="{{ asset('images/home/dashboard.webp') }}" width="1600" height="853" fetchpriority="high"
                 alt="A studio owner’s dashboard in Loft: 63 members, 5 at high risk of leaving, this month’s sales, active memberships, waitlists and today’s classes.">
        </figure>
        <figure class="shot shot-phone">
            <img src="{{ asset('images/home/schedule-phone.webp') }}" width="600" height="1131"
                 alt="The studio’s schedule on a parent’s phone: Kids Aerial Silks, with Maya and Leo booked.">
        </figure>
    </div>
</section>

<section class="facts" aria-label="Loft at a glance">
    <div><strong>{{ $price }}<small>/month</small></strong><span>per studio, everything included</span></div>
    <div><strong>Unlimited</strong><span>members, staff and classes</span></div>
    @if ($fee > 0)
        <div><strong>{{ $feeLabel }}%</strong><span>Loft fee on online sales</span></div>
    @else
        <div><strong>0%</strong><span>of your sales to Loft</span></div>
    @endif
    <div><strong>No contract</strong><span>month to month, cancel any time</span></div>
</section>

<section class="section spotlight" id="tour" aria-labelledby="spots-title">
    <div class="spotlight-copy">
        <span class="kicker">Built for aerial, pole and reformer studios</span>
        <h2 id="spots-title">Booking that fills every spot</h2>
        <p>Tell Loft what’s rigged in each room and how many people can share each silk, hoop, pole or reformer. Members choose their apparatus when they book, and when one is full they join its waitlist.</p>
        <ul class="ticks">
            <li><x-icon name="check" /> When someone cancels, the next person on the waitlist moves up and gets an email.</li>
            <li><x-icon name="check" /> Weekly classes across a season, one-off workshops, and online spots for live classes.</li>
            <li><x-icon name="check" /> Parents book every child from one account, with ages and medical notes on the roster.</li>
            <li><x-icon name="check" /> Late cancels and no-shows follow the policy you set.</li>
        </ul>
    </div>
    <figure class="shot shot-phone spotlight-shot">
        <img src="{{ asset('images/home/book-phone.webp') }}" width="600" height="1271" loading="lazy"
             alt="Booking Open Practice on a phone: choose who’s coming, then Silks (8 spots left) or Lyra (full, join the waitlist).">
    </figure>
</section>

<section class="section tour" aria-label="What Loft does">
    <div class="tour-row">
        <div class="tour-copy">
            <span class="feature-icon"><x-icon name="shield-check" /></span>
            <h2>Waivers and check-in, sorted before class</h2>
            <p>Everyone signs your current waiver before their first class: each attendee, with a drawn signature and the exact text they agreed to. Publish a new version and people re-sign at their next booking.</p>
            <ul class="ticks">
                <li><x-icon name="check" /> The roster shows ages, medical notes, a parent’s phone number and who’s on Silks 3.</li>
                <li><x-icon name="check" /> Instructors check people in from a phone or tablet, or members scan their code or fob at the door.</li>
            </ul>
        </div>
        <figure class="shot shot-window">
            <img src="{{ asset('images/home/checkin.webp') }}" width="1200" height="1031" loading="lazy"
                 alt="The check-in roster for Kids Aerial Silks: each child with their age, parent’s phone number, silk number and a medical note.">
        </figure>
    </div>

    <div class="tour-row flip">
        <div class="tour-copy">
            <span class="feature-icon"><x-icon name="wallet" /></span>
            <h2>Paid straight into your Stripe account</h2>
            <p>Sell monthly memberships with weekly limits, season tuition, class packs and drop-ins, each covering the classes you choose. Members pay you directly: the money never passes through Loft.</p>
            <ul class="ticks">
                <li><x-icon name="check" /> Failed-card emails, refunds and a self-serve billing portal are built in.</li>
                <li><x-icon name="check" /> Take payment at the front desk on a card reader, or record cash and checks.</li>
                <li><x-icon name="check" /> Every sale in one report, with a CSV for your bookkeeper.</li>
            </ul>
        </div>
        <figure class="shot shot-window">
            <img src="{{ asset('images/home/sales.webp') }}" width="1200" height="828" loading="lazy"
                 alt="The Sales page for November: total, online and in-person sales, and a list of membership, pack, drop-in and merchandise sales.">
        </figure>
    </div>

    <div class="tour-row">
        <div class="tour-copy">
            <span class="feature-icon"><x-icon name="heart-pulse" /></span>
            <h2>See who’s drifting away, before they go</h2>
            <p>Each night Loft looks at how often every member comes, what they’ve booked and whether their card is failing, and shows you who’s at risk of leaving and why, while there’s still time to reach out.</p>
            <ul class="ticks">
                <li><x-icon name="check" /> Automatic emails after a first class, on birthdays, and to members who’ve gone quiet.</li>
                <li><x-icon name="check" /> Members choose whether they get your offers, and can unsubscribe in one click.</li>
            </ul>
        </div>
        <figure class="shot-stack">
            <span class="shot shot-window"><img src="{{ asset('images/home/at-risk.webp') }}" width="1200" height="547" loading="lazy"
                 alt="The Members page filtered to members at risk of leaving, each marked high risk."></span>
            <span class="shot shot-card"><img src="{{ asset('images/home/risk-card.webp') }}" width="558" height="406" loading="lazy"
                 alt="Why one member is at high risk: no class in 33 days, came 4 times the month before and not at all this month, nothing booked."></span>
        </figure>
    </div>
</section>

<section class="section" aria-labelledby="more-title">
    <div class="section-head">
        <h2 id="more-title">And everything else, included</h2>
        <p>No add-ons and no higher tier to unlock them.</p>
    </div>
    <div class="mini-features">
        <div><x-icon name="store" /><h3>Point of sale</h3><p>Sell plans and merchandise at the desk, on a Stripe card reader.</p></div>
        <div><x-icon name="shopping-bag" /><h3>Online shop</h3><p>Members order grip, tees and gear online and pick up at class.</p></div>
        <div><x-icon name="clapperboard" /><h3>Video library</h3><p>On-demand classes for members whose plan includes them.</p></div>
        <div><x-icon name="video" /><h3>Live online classes</h3><p>Online spots alongside in-person ones; members join from their account.</p></div>
        <div><x-icon name="mail-check" /><h3>Emails that send themselves</h3><p>Booking confirmations, waitlist moves, receipts and reminders.</p></div>
        <div><x-icon name="users-round" /><h3>Family accounts</h3><p>One parent account for every child, with ages and emergency contacts.</p></div>
        <div><x-icon name="scan-line" /><h3>Door check-in</h3><p>Rotating QR codes on members’ phones, or cards and fobs you hand out.</p></div>
        <div><x-icon name="smartphone" /><h3>Works on any phone</h3><p>Your studio’s own page, in your colors. Nothing for members to install.</p></div>
    </div>
</section>

<section class="section pricing-home" id="pricing" aria-labelledby="price-title">
    <div class="section-head">
        <h2 id="price-title">One price. <span class="gradient-text">Everything included.</span></h2>
        <p>Getting all of this elsewhere often costs $150–500 a month, and some platforms also take a cut of every card sale. Loft is {{ $price }}, the same for a studio of 30 members or 600.</p>
    </div>
    <div class="price-grid">
        <div class="card price-card">
            <div class="price">{{ $price }}<small>/ month per studio</small></div>
            <p class="muted">{{ $trialDays }}-day free trial. No card needed to start.</p>
            <ul class="included">
                <li><x-icon name="check" />Unlimited members, staff, classes and bookings</li>
                <li><x-icon name="check" />Every feature on this page</li>
                <li><x-icon name="check" />@if ($fee > 0){{ $feeLabel }}% Loft fee on online sales @else No Loft fee on your sales @endif</li>
                <li><x-icon name="check" />No setup fee, no contract</li>
                <li><x-icon name="check" />Free help moving your members over</li>
            </ul>
            <a class="btn btn-large btn-block" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
            <p class="small muted center"><a href="{{ route('pricing') }}">Pricing details</a></p>
        </div>

        <form class="card calc" data-calc data-monthly="{{ $monthly }}" data-fee="{{ $fee }}" data-currency="{{ $currency }}">
            <h3><x-icon name="calculator" /> What do you pay now?</h3>
            <p class="small muted">Enter what your current software costs. It’s worked out here in your browser; nothing is sent anywhere.</p>
            <div class="calc-fields">
                <label class="calc-field"><span>Software a month</span>
                    <span class="input-affix"><i>$</i><input type="number" name="software" min="0" step="1" inputmode="decimal" value="{{ $calc['software'] }}"></span></label>
                <label class="calc-field"><span>Its fee on sales</span>
                    <span class="input-affix"><input type="number" name="percent" min="0" max="20" step="0.1" inputmode="decimal" value="{{ $calc['percent'] }}"><i>%</i></span></label>
                <label class="calc-field"><span>Card sales a month</span>
                    <span class="input-affix"><i>$</i><input type="number" name="sales" min="0" step="100" inputmode="decimal" value="{{ $calc['sales'] }}"></span></label>
            </div>
            <dl class="calc-result" aria-live="polite">
                <div><dt>You pay now</dt><dd data-out="now">{{ $money($calc['now']) }}<small>/year</small></dd></div>
                <div><dt>With Loft</dt><dd data-out="loft">{{ $money($calc['loft']) }}<small>/year</small></dd></div>
                <div class="calc-saved"><dt data-out="saved-label">You’d keep</dt><dd data-out="saved">{{ $money(abs($calc['saved'])) }}<small>/year</small></dd></div>
            </dl>
            <p class="tiny muted">Only count fees your software adds on top of card processing. Stripe’s own card fees (in the US, 2.9% + 30¢ a charge) apply with Loft too, so they’re left out of both sides.</p>
        </form>
    </div>
</section>

<section class="section" aria-labelledby="start-title">
    <div class="section-head">
        <h2 id="start-title">Up and running this week</h2>
        <p>Whether this is your first studio software or your third.</p>
    </div>
    <div class="start-grid">
        <div class="card start-card">
            <h3><x-icon name="sparkles" /> Starting fresh</h3>
            <ol class="steps-compact">
                <li>Create your studio and connect Stripe.</li>
                <li>Add your rooms, classes and waiver.</li>
                <li>Set up memberships, packs and drop-ins.</li>
                <li>Share your schedule link on your website and Instagram.</li>
            </ol>
            <p class="small muted">You can be taking bookings the same afternoon.</p>
        </div>
        <div class="card start-card switch-card">
            <h3><x-icon name="file-input" /> Moving from another system</h3>
            <p><strong>We’ll move your members over for free.</strong> Export your client list from your old software and email it to <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>, and we’ll import it and check it with you.</p>
            <p class="small muted">Or import it yourself. Loft matches the columns and shows you a preview before anything changes; families stay together, class credits come across, and you can undo an import for 30 days.</p>
            <figure class="shot shot-window">
                <img src="{{ asset('images/home/import.webp') }}" width="1100" height="803" loading="lazy"
                     alt="An import preview: 6 new accounts and 1 existing member, with each family’s children and any medical notes, before anything is imported.">
            </figure>
        </div>
    </div>
</section>

<section class="section faq-home" aria-labelledby="faq-title">
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
    <p>Set up your schedule today; families book, sign and pay from one link. Free for {{ $trialDays }} days, then {{ $price }} a month.</p>
    <a class="btn btn-large" href="{{ route('signup') }}">Start your free trial <x-icon name="arrow-right" /></a>
    <p class="cta-help"><x-icon name="life-buoy" /> Questions first? Email <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
</section>
@endsection
