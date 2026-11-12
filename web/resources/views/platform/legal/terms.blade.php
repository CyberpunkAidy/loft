@extends('layouts.platform')

@section('title', 'Terms of Service')

@section('content')
<article class="legal">
    <header>
        <h1>Terms of Service</h1>
        <p class="muted">Last updated {{ $termsDate }}</p>
    </header>

    <p>These Terms of Service (“terms”) are an agreement between you and {{ $entity }} (“Loft”, “we”, “us”), which provides Loft: the website at loftstudio.app, each studio’s pages on it, and the Loft mobile app (together, the “Service”). By creating an account, starting a studio or using the Service, you agree to these terms and to our <a href="{{ route('privacy') }}">Privacy Policy</a>. If you don’t agree, please don’t use the Service.</p>

    <nav class="legal-contents" aria-label="Contents">
        <ol>
            <li><a href="#who">Who these terms are for</a></li>
            <li><a href="#accounts">Accounts</a></li>
            <li><a href="#use">Using the Service</a></li>
            <li><a href="#billing">Studios: subscription and billing</a></li>
            <li><a href="#studios">Studios: your responsibilities</a></li>
            <li><a href="#members">Members</a></li>
            <li><a href="#ours">Our software and brand</a></li>
            <li><a href="#third-parties">Other companies’ services</a></li>
            <li><a href="#availability">Availability and changes</a></li>
            <li><a href="#ending">Ending your use</a></li>
            <li><a href="#disclaimers">Disclaimers</a></li>
            <li><a href="#liability">Limits on liability</a></li>
            <li><a href="#indemnity">Indemnity</a></li>
            <li><a href="#changes">Changes to these terms</a></li>
            <li><a href="#data-processing">Data processing terms</a></li>
            <li><a href="#law">Governing law and disputes</a></li>
            <li><a href="#general">General</a></li>
            <li><a href="#contact">Contact</a></li>
        </ol>
    </nav>

    <section id="who">
        <h2>1. Who these terms are for</h2>
        <ul>
            <li><strong>Studios.</strong> Businesses that use Loft to run their classes, memberships and payments, and the people who start or manage a studio for them (owners, admins and instructors). If you start or manage a studio, you accept these terms for that business and confirm you’re allowed to.</li>
            <li><strong>Members.</strong> People who create an account to book classes, buy plans or manage their family at a studio.</li>
        </ul>
        <p>Sections 4, 5, 13 and 15 apply only to studios. Section 6 applies only to members. Everything else applies to everyone.</p>
    </section>

    <section id="accounts">
        <h2>2. Accounts</h2>
        <ul>
            <li>You must be at least 18, or the age of majority where you live, to create an account. Parents and guardians can add their children to their account and book for them; children don’t have accounts of their own.</li>
            <li>One Loft account works at every studio you belong to. Keep your password safe, and tell us at <a href="mailto:{{ $email }}">{{ $email }}</a> if you think someone else has used your account. You’re responsible for what happens under it.</li>
            <li>Give accurate information about yourself and the people on your account, and keep it up to date.</li>
            <li>A studio can bring its members over from another system. If yours did, it will invite you to choose a password. If you’d rather not have an account, tell the studio or email us and we’ll delete it.</li>
        </ul>
    </section>

    <section id="use">
        <h2>3. Using the Service</h2>
        <p>Use the Service only lawfully and as it’s meant to be used. In particular, don’t:</p>
        <ul>
            <li>break the law, or help anyone else do so, including laws on privacy, consumer protection, marketing and payments;</li>
            <li>upload anything you don’t have the right to share, or anything harmful, hateful, harassing or deceptive;</li>
            <li>access accounts, studios or data that aren’t yours, or try to get around security, rate limits or signed links;</li>
            <li>probe, scan or test the Service for vulnerabilities without our written permission (if you’ve found a security problem, please tell us at <a href="mailto:{{ $email }}">{{ $email }}</a>);</li>
            <li>scrape the Service, overload it, or interfere with how it runs;</li>
            <li>copy, modify, reverse engineer, resell or build a competing product from the Service;</li>
            <li>send spam or unsolicited messages through it.</li>
        </ul>
    </section>

    <section id="billing">
        <h2>4. Studios: subscription and billing</h2>
        <ul>
            <li><strong>Free trial.</strong> A new studio gets a {{ $trialDays }}-day free trial. No card is needed to start.</li>
            @if ($yearly)
                <li><strong>Subscription.</strong> After the trial, Loft costs the price on our <a href="{{ route('pricing') }}">pricing page</a> (currently {{ $price }} a month, or {{ $yearly }} a year, per studio), plus any tax that applies. You choose monthly or yearly billing. It’s billed in advance through Stripe and renews each month or year until you cancel. If you subscribe during the trial, the first payment is taken when the trial ends. You can switch between monthly and yearly in the billing portal; Stripe credits what’s left of the period you’ve paid for.</li>
                <li><strong>Cancelling.</strong> Cancel any time from Admin → Subscription. Your subscription ends at the end of the month or year you’ve paid for. We don’t refund part of a month or year, except where the law requires it.</li>
            @else
                <li><strong>Subscription.</strong> After the trial, Loft costs the price on our <a href="{{ route('pricing') }}">pricing page</a> (currently {{ $price }} a month per studio), plus any tax that applies. It’s billed monthly in advance through Stripe and renews each month until you cancel. If you subscribe during the trial, the first payment is taken when the trial ends.</li>
                <li><strong>Cancelling.</strong> Cancel any time from Admin → Subscription. Your subscription ends at the end of the month you’ve paid for. We don’t refund part-months, except where the law requires it.</li>
            @endif
            <li><strong>Your price is locked.</strong> The price you subscribe at stays the same for as long as your subscription continues{{ $yearly ? ' on the same plan (monthly or yearly)' : '' }}. A new price applies only to subscriptions started{{ $yearly ? ', or switched to the other plan,' : '' }} after it takes effect. If your subscription ends and you subscribe again later, the price at that time applies.</li>
            <li><strong>Offers.</strong> We may offer discounts, promotion codes or free access. Each offer lasts as long as it says, and we can end free access by telling the studio’s owners at least 30 days ahead.</li>
            @if ($bonusDays)
                <li><strong>Referrals.</strong> A studio that starts with another studio’s or a Loft partner’s referral code gets {{ $bonusDays }} more days of free trial. Once it has made its first payment to Loft, the studio whose code it used gets a credit worth one month of its plan on its next Loft bills, or {{ $bonusDays }} more days of free trial if it hasn’t subscribed yet. A studio that Loft is free for earns nothing more. Credits can’t be exchanged for cash or moved to another studio, the owners and admins of a studio can’t use its code for a studio of their own, and we may change or end referrals at any time; what’s already been earned is kept.</li>
            @endif
            <li><strong>If you don’t pay.</strong> If your trial ends without a subscription, your subscription ends, or a payment fails, everything keeps working for {{ $graceDays }} more days. After that your studio is paused: staff can see only the subscription page, and members can’t book or pay online, until you subscribe or update your card. Nothing is deleted while a studio is paused. We may close a studio that has stayed paused for more than 12 months, after emailing its owners at least 30 days before.</li>
        </ul>
    </section>

    <section id="studios">
        <h2>5. Studios: your responsibilities</h2>
        <h3>5.1 Payments from your members</h3>
        <p>Payments your members make to you, online or on a card reader, are processed by Stripe through your studio’s own Stripe account, under your agreement with Stripe. You are the seller: Loft isn’t a party to those payments. You’re responsible for your prices, taxes, refunds, disputes and chargebacks, and for following Stripe’s terms. Stripe’s fees apply. Loft doesn’t currently charge a fee on these payments, and we’ll give at least 30 days’ notice before that changes. Loft isn’t responsible for Stripe’s service, including holds on or delays to your payouts.</p>

        <h3>5.2 Your classes and your members</h3>
        <p>You alone are responsible for your classes and services: instruction, staff, premises, equipment (including rigging and apparatus), safety, and your relationship with your members, including your cancellation, refund and membership policies.</p>

        <h3>5.3 Waivers</h3>
        <p>Loft lets you publish waivers and collect signatures. You write the waivers and decide when they’re needed. Loft doesn’t give legal advice and doesn’t promise that a waiver will be enforceable; have yours reviewed by a lawyer where you operate.</p>
        <p>Loft can also ask your members about injuries and about photos and video, and shows you their answers. Following those answers (keeping someone out of photos, or a photo off social media) is up to you, as is any further consent your local law needs before you publish someone’s image.</p>

        <h3>5.4 Your members’ information</h3>
        <p>You’re responsible for having a lawful basis to collect your members’ information and to put it into Loft, including information you import from another system and information about children; for telling your members how you use it; and for answering their requests about it. Section 15 explains how we handle that information for you.</p>

        <h3>5.5 Marketing</h3>
        <p>Send marketing emails only to members who agreed to receive them, using Loft’s consent settings, and follow the laws that apply to you (such as CAN-SPAM in the US, CASL in Canada, and the GDPR and its UK equivalent). Loft adds an unsubscribe link to marketing emails and stops sending them to anyone who unsubscribes.</p>

        <h3>5.6 Your content</h3>
        <p>Anything you put into Loft (class descriptions, waivers, logos, product photos, videos, emails) must be yours or licensed to you, and lawful. You give us a worldwide, non-exclusive, royalty-free licence to host, copy, process, display and deliver it, only as needed to provide the Service to you and your members. You keep ownership of it. Meeting links you add for live classes lead to other companies’ services, under their terms.</p>

        <h3>5.7 Your staff</h3>
        <p>You decide who has staff access to your studio, and you’re responsible for what they do with it.</p>
    </section>

    <section id="members">
        <h2>6. Members</h2>
        <ul>
            <li>Bookings, plans, memberships, purchases and waivers are agreements between you and the studio, under the studio’s policies (for example its cancellation window, refunds and membership terms). Loft provides the software. It doesn’t run classes and isn’t a party to them.</li>
            <li>Payments you make to a studio are processed by Stripe on the studio’s own account. Questions about charges and refunds go to the studio.</li>
            <li>Recurring memberships renew as the studio’s plan describes until you cancel them, which you can do from your account.</li>
            <li>What you add about yourself and your family, such as birthdays, medical notes, emergency contacts and your answers to the studio’s injury and photo questions, is shared with the studio’s staff so they can run classes safely and respect your wishes about photos. Please keep it accurate.</li>
            <li>Physical activity carries a risk of injury. If you’re unsure whether a class is right for you or your child, check with a doctor, and follow the studio’s instructions.</li>
        </ul>
    </section>

    <section id="ours">
        <h2>7. Our software and brand</h2>
        <p>We own the Service, its software and design, and the Loft name and logo. While you follow these terms, we give you a limited, non-exclusive, non-transferable right to use the Service. If you send us ideas or feedback, we may use them without any obligation to you.</p>
    </section>

    <section id="third-parties">
        <h2>8. Other companies’ services</h2>
        <p>The Service works with other companies’ services, including Stripe (payments), Mux (video), video-meeting providers chosen by studios, and Apple’s and Google’s app stores. Their terms may also apply when you use them, and we aren’t responsible for their services.</p>
    </section>

    <section id="availability">
        <h2>9. Availability and changes</h2>
        <p>We work to keep Loft running and your data safe, but we don’t guarantee the Service will always be available or free of errors; there may be maintenance and outages. We may change, add or remove features. We’ll give studios’ owners at least 30 days’ notice by email before removing a feature that’s important to how they use Loft, unless security or the law needs us to act sooner.</p>
    </section>

    <section id="ending">
        <h2>10. Ending your use</h2>
        <ul>
            <li>Members can delete their account at any time from My account → Profile. A studio’s owner can close the studio by deleting their account and choosing to close it; download your signed waivers first. Our <a href="{{ route('privacy') }}">Privacy Policy</a> explains what happens to the data.</li>
            <li>We may suspend or end your access if you break these terms, don’t pay, put others at risk, or where the law requires it. Where it’s reasonable, we’ll tell you first and give you a chance to put things right.</li>
            <li>Sections 5.1, 7 and 11 to 17 continue to apply after your use ends.</li>
        </ul>
    </section>

    <section id="disclaimers">
        <h2>11. Disclaimers</h2>
        <p>The Service is provided “as is” and “as available”. To the fullest extent the law allows, we disclaim all warranties, express or implied, including warranties of merchantability, fitness for a particular purpose and non-infringement. We don’t give instruction, supervise classes, inspect premises or equipment, or check that studios, instructors or members are qualified or insured, and we aren’t responsible for injuries or losses arising from classes or a studio’s services.</p>
    </section>

    <section id="liability">
        <h2>12. Limits on liability</h2>
        <p>To the fullest extent the law allows:</p>
        <ul>
            <li>neither you nor we will be liable for indirect, incidental, special, consequential or punitive damages, or for lost profits, revenue, goodwill or data; and</li>
            <li>our total liability for all claims arising out of or relating to these terms or the Service is limited to the greater of US $100 and the amounts your studio paid us in the 12 months before the event giving rise to the claim.</li>
        </ul>
        <p>Nothing in these terms limits liability that the law doesn’t allow to be limited.</p>
    </section>

    <section id="indemnity">
        <h2>13. Indemnity</h2>
        <p>If you use Loft as a studio, you’ll defend and indemnify Loft, its owners and its staff against third-party claims, and the losses and reasonable costs that come with them, arising from your classes and services, your premises, equipment and staff, your waivers and content, your members’ payments, refunds and disputes, your marketing, or your breach of these terms or the law.</p>
    </section>

    <section id="changes">
        <h2>14. Changes to these terms</h2>
        <p>We may update these terms; the date at the top shows the latest version. For changes that matter, we’ll email studios’ owners at least 30 days before they apply to existing studios, unless the change is needed for legal reasons or only covers new features. If you keep using the Service after a change applies, you accept it. If you don’t, stop using the Service and, if you run a studio, cancel your subscription.</p>
    </section>

    <section id="data-processing">
        <h2>15. Data processing terms</h2>
        <p>This section applies when a studio uses Loft, and forms a data processing agreement between the studio and us.</p>

        <h3>15.1 Roles</h3>
        <p>For personal information about members, their families and the studio’s staff that the studio puts into Loft, or that members give the studio through Loft (“Studio Data”), the studio is the controller (or “business”) and Loft is its processor (or “service provider”). For the information we use to run Loft itself, such as accounts, sign-in and our own billing, Loft is the controller, as our <a href="{{ route('privacy') }}">Privacy Policy</a> describes.</p>

        <h3>15.2 Instructions</h3>
        <p>We process Studio Data only to provide the Service as these terms and the studio’s use of Loft’s features describe, which are the studio’s documented instructions, or where the law requires us to (and then we’ll tell the studio first, unless the law forbids it). We don’t sell Studio Data, use it for advertising, or keep, use or disclose it outside our relationship with the studio. We combine it with other information only where the Service needs to, for example so that one person’s login works at every studio they belong to.</p>

        <h3>15.3 What’s processed</h3>
        <p>Subject matter and purpose: providing the Service. People: the studio’s members, their family members (including children), its staff, and people it imports from another system. Information: names and contact details; birthdays; medical notes and emergency contacts; answers to the studio’s injury and photo questions, and when, by whom and how each photo answer was given; bookings, attendance and waitlists; plans, purchases and payment records (not card numbers, which Stripe holds); waiver signatures, including the signer’s IP address and browser; check-in codes, tags and scans; marketing consent and the emails sent; videos watched; and activity history and the “at risk of leaving” scores Loft works out from it for the studio. Duration: while the studio uses Loft, then as in 15.8.</p>

        <h3>15.4 Confidentiality and security</h3>
        <p>Everyone who can access Studio Data on our side is bound to keep it confidential. We protect it with appropriate technical and organisational measures, including: encrypted connections (HTTPS); passwords stored only as one-way hashes; access to each studio’s data limited to the staff roles that studio grants, and on our side to people who need it; signed, expiring links for invitations and unsubscribes; card details never stored by Loft (Stripe handles them); and hosting with providers that maintain physical, network and backup security.</p>

        <h3>15.5 Subprocessors</h3>
        <p>The studio authorises these subprocessors:</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Company</th><th>What it does</th><th>Where</th></tr></thead>
                <tbody>
                    <tr><td>Render</td><td>Hosting: the application, database and file storage</td><td>United States</td></tr>
                    <tr><td>Resend</td><td>Sending email</td><td>United States</td></tr>
                    <tr><td>Mux</td><td>Hosting and streaming on-demand videos (videos only, with no member details)</td><td>United States</td></tr>
                    <tr><td>Stripe</td><td>Loft’s own subscription billing. Members’ payments are processed in the studio’s own Stripe account, under the studio’s agreement with Stripe</td><td>United States</td></tr>
                </tbody>
            </table>
        </div>
        <p>We hold our subprocessors to data protection terms that protect Studio Data at least as well as this section, and we remain responsible for them. We’ll update this list, and email studios’ owners, at least 30 days before adding or replacing a subprocessor. A studio can object by emailing us in that time; if we can’t resolve it, the studio can end its subscription and we’ll refund any fees paid for the time after it ends.</p>

        <h3>15.6 Helping the studio</h3>
        <p>We’ll help the studio answer requests from the people its data is about. Much of this is built in: members can correct their details and delete their account, and owners can download every signed waiver. We’ll also give reasonable help with data protection impact assessments and with consulting regulators about the Service.</p>

        <h3>15.7 Breaches</h3>
        <p>If we become aware of a breach of security that affects Studio Data, we’ll tell the studio’s owners without undue delay, and within 72 hours, with what we know about it, and we’ll take reasonable steps to contain it and limit the harm.</p>

        <h3>15.8 Return and deletion</h3>
        <p>While a studio uses Loft, its owners can download signed waivers at any time and can ask us for a copy of the rest of their Studio Data. When a studio is closed, its Studio Data is deleted from Loft, except copies in backups (overwritten on our host’s rolling schedule) and anything the law requires us to keep.</p>

        <h3>15.9 Showing compliance</h3>
        <p>On reasonable request, no more than once a year unless a regulator requires it, we’ll give the studio the information it needs to show that this section is being followed, in writing.</p>

        <h3>15.10 International transfers</h3>
        <p>Loft stores data in the United States. For Studio Data from the European Economic Area, the United Kingdom or Switzerland, the Standard Contractual Clauses adopted by the European Commission in Decision (EU) 2021/914 (Module Two, controller to processor, or Module Three where the studio is itself a processor), the UK International Data Transfer Addendum, and the changes the Swiss data protection authority requires, are incorporated into these terms by reference, with the studio as data exporter and Loft as data importer. In them: clause 7 doesn’t apply; under clause 9, option 2 (general authorisation) applies with the notice period in 15.5; the optional language in clause 11 doesn’t apply; under clauses 17 and 18, Irish law applies and the courts of Ireland have jurisdiction; Annex I is completed by 15.1 and 15.3; Annex II by 15.4; and Annex III by 15.5. If those clauses conflict with these terms, the clauses prevail.</p>

        <h3>15.11 Which terms prevail</h3>
        <p>For Studio Data, this section prevails over the rest of these terms if they conflict.</p>
    </section>

    <section id="law">
        <h2>16. Governing law and disputes</h2>
        <p>These terms are governed by the laws of the State of {{ $state }}, without regard to its conflict-of-law rules. Except where the law gives you the right to bring a claim elsewhere, any dispute will be heard only in the state and federal courts located in {{ $state }}, and you and we agree to their jurisdiction. If you live in the EU, the UK or another place whose consumer laws give you protections that can’t be waived, you keep those protections. Before starting a claim, please email us at <a href="mailto:{{ $email }}">{{ $email }}</a> and give us 30 days to try to resolve it.</p>
    </section>

    <section id="general">
        <h2>17. General</h2>
        <ul>
            <li>These terms and our Privacy Policy are the whole agreement between you and us about the Service.</li>
            <li>If part of these terms can’t be enforced, the rest still applies. If we don’t enforce a term straight away, we haven’t given up the right to.</li>
            <li>You can’t transfer these terms without our written agreement. We may transfer them as part of a merger, acquisition or sale of our business, and we’ll tell studios’ owners if we do.</li>
            <li>Neither of us is responsible for delays or failures caused by events beyond reasonable control.</li>
            <li>We’ll send notices to the email address on your account. Send notices to us at <a href="mailto:{{ $email }}">{{ $email }}</a>.</li>
            <li>You and we are independent; these terms don’t create a partnership, employment or agency. They give no rights to anyone else.</li>
        </ul>
    </section>

    <section id="contact">
        <h2>18. Contact</h2>
        <p>{{ $entity }} · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </section>
</article>
@endsection
