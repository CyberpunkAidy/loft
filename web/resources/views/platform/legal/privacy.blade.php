@extends('layouts.platform')

@section('title', 'Privacy Policy')

@section('content')
<article class="legal">
    <header>
        <h1>Privacy Policy</h1>
        <p class="muted">Last updated {{ $privacyDate }}</p>
    </header>

    <p>This policy explains what personal information Loft collects, how it’s used and shared, how long it’s kept, and the choices and rights you have. Loft is provided by {{ $entity }} (“Loft”, “we”, “us”): the website at loftstudio.app, each studio’s pages on it, and the Loft mobile app (the “Service”).</p>

    <nav class="legal-contents" aria-label="Contents">
        <ol>
            <li><a href="#roles">Loft and your studio</a></li>
            <li><a href="#collect">What we collect</a></li>
            <li><a href="#use">How it’s used</a></li>
            <li><a href="#bases">Legal bases (EEA and UK)</a></li>
            <li><a href="#share">Who it’s shared with</a></li>
            <li><a href="#cookies">Cookies</a></li>
            <li><a href="#keep">How long it’s kept</a></li>
            <li><a href="#rights">Your choices and rights</a></li>
            <li><a href="#us-states">US state privacy rights</a></li>
            <li><a href="#children">Children</a></li>
            <li><a href="#security">Security</a></li>
            <li><a href="#transfers">Where your information is stored</a></li>
            <li><a href="#changes">Changes to this policy</a></li>
            <li><a href="#contact">Contact</a></li>
        </ol>
    </nav>

    <section id="roles">
        <h2>1. Loft and your studio</h2>
        <p>Studios use Loft to run their classes, memberships and payments. When you book with a studio, the information you give it through Loft (your bookings, family, waivers, plans and so on) is that studio’s, and the studio decides how it’s used: the studio is the “controller” and Loft processes the information for it, under our <a href="{{ route('terms') }}#data-processing">data processing terms</a>. The studio’s own privacy notice applies to that, and you can ask the studio about it directly.</p>
        <p>Loft is the controller for the information we use to run Loft itself: your account and sign-in, the security of the Service, and our billing of studios. If you belong to several studios, you have one Loft account for all of them, but each studio sees only its own members’ information.</p>
    </section>

    <section id="collect">
        <h2>2. What we collect</h2>
        <h3>You give us</h3>
        <ul>
            <li><strong>Your account:</strong> name, email address, phone number (optional) and password (stored only as a one-way hash).</li>
            <li><strong>Your family:</strong> the names, birthdays, medical notes and emergency contacts of people you book for, including your children, and your answers to the studio’s questions about each of them: any injuries or health conditions, and whether the studio may take photos or video of them (with when you answered). Medical notes can include health information; add only what the studio needs to keep everyone safe in class. You can change these answers any time in your account.</li>
            <li><strong>Waivers:</strong> when you sign one, your typed name, your relationship to the person it covers, the drawing of your signature, the exact version of the text, and the time.</li>
            <li><strong>Studios:</strong> if you run a studio, its name, address, settings and branding; the classes, plans, products and videos you add; your staff’s names and emails; and, when you start it, how you heard about Loft and any referral code you used.</li>
            <li><strong>Messages to us:</strong> anything you send when you contact us.</li>
        </ul>
        <h3>Created as you use Loft</h3>
        <ul>
            <li><strong>Bookings and attendance:</strong> classes booked, waitlists, cancellations, check-ins and no-shows.</li>
            <li><strong>Plans and purchases:</strong> memberships, class packs and shop orders, with amounts, dates and refunds. Card numbers go straight to Stripe and never reach Loft; we keep only Stripe’s references to them.</li>
            <li><strong>Check-in:</strong> rotating check-in codes and any cards or fobs the studio gives you, and a log of each scan with its result.</li>
            <li><strong>Email:</strong> a log of the emails sent to you (which kind, when, and whether it was delivered), and your choice about the studio’s marketing emails.</li>
            <li><strong>Videos:</strong> which of the studio’s videos you watched, and on which day.</li>
            <li><strong>Activity and risk scores:</strong> a history of the things above, nightly summaries of your activity at each studio, and, for studios, an “at risk of leaving” score worked out from them with simple rules (for example, not having booked in a while). Studios use these scores to check in with members; they don’t lead to any automatic decision about you.</li>
            <li><strong>Technical information:</strong> your IP address and browser when you sign in, sign a waiver or use the Service, the name of the device you sign in to the app on, and the records our host keeps of requests to the Service.</li>
            <li><strong>Where a new studio’s visit began:</strong> when you start a studio, the site that linked you to Loft (just its name, like google.com), any campaign tags in that link, and the first page you opened. It’s kept for your visit only, and saved only if you start a studio.</li>
        </ul>
        <h3>From others</h3>
        <ul>
            <li><strong>Your studio:</strong> a studio moving to Loft can bring its members’ records over from its previous system: names, contact details, birthdays, family, medical notes, emergency contacts, marketing preferences, and the plans you’d already paid for.</li>
            <li><strong>Stripe:</strong> confirmation of payments, refunds and failed payments, so your plans and the studio’s records stay correct.</li>
        </ul>
    </section>

    <section id="use">
        <h2>3. How it’s used</h2>
        <ul>
            <li>to provide the Service: accounts, booking, waitlists, waivers, check-in, payments, plans, videos and live classes;</li>
            <li>to show the studios you belong to what they need to run your classes, including your family’s medical notes, emergency contacts and photo answers;</li>
            <li>to send emails about your bookings, payments and account, and, for studios, about their Loft subscription;</li>
            <li>to send a studio’s news and offers on its behalf, only if you agreed to receive them;</li>
            <li>to keep the Service secure, prevent abuse and fix problems;</li>
            <li>to bill studios for Loft, and to reward studios that bring other studios to Loft;</li>
            <li>to see which ways of finding Loft work for studios;</li>
            <li>to improve the Service; and</li>
            <li>to meet legal obligations and enforce our terms.</li>
        </ul>
        <p>We don’t sell your information, and we don’t use it for advertising.</p>
    </section>

    <section id="bases">
        <h2>4. Legal bases (EEA and UK)</h2>
        <p>Where the GDPR or UK GDPR applies, we rely on:</p>
        <ul>
            <li><strong>contract:</strong> to provide the Service you’ve signed up for;</li>
            <li><strong>legitimate interests:</strong> to keep the Service secure, improve it, and support studios (for example, with activity history and risk scores), balanced against your rights;</li>
            <li><strong>consent:</strong> for marketing emails, and for the health information you choose to give in medical notes or in answer to a studio’s injury question; you can withdraw it at any time; and</li>
            <li><strong>legal obligation:</strong> where the law requires us to keep or share information.</li>
        </ul>
        <p>For information a studio controls, the studio decides its own legal basis.</p>
    </section>

    <section id="share">
        <h2>5. Who it’s shared with</h2>
        <ul>
            <li><strong>Studios you belong to:</strong> their staff see your account details, family, bookings, plans, waivers and check-ins at that studio, and nothing from other studios.</li>
            <li><strong>Service providers</strong> who process information for us under contract: Render (hosting, database and file storage), Resend (sending email), Mux (hosting and streaming videos; it receives your IP address when you watch, with no cookies and no member details), and Stripe (payments).</li>
            <li><strong>Stripe:</strong> payments you make to a studio are processed by Stripe on the studio’s own Stripe account, under Stripe’s privacy policy and the studio’s agreement with Stripe.</li>
            <li><strong>The law:</strong> when we must, to comply with law or legal process, or to protect someone’s safety or rights.</li>
            <li><strong>A new owner:</strong> if our business is merged or sold, with notice to you.</li>
        </ul>
    </section>

    <section id="cookies">
        <h2>6. Cookies</h2>
        <p>Loft uses only the cookies it needs to work: one that keeps you signed in during a visit, one that protects forms from forgery, and, if you choose “remember me”, one that keeps you signed in on that device until you sign out. If you follow a referral link, one more remembers its code for 30 days, so the form for starting a studio can fill it in. We don’t use advertising or analytics cookies, and the video player is set to use none. The mobile app keeps your sign-in in your phone’s secure storage.</p>
    </section>

    <section id="keep">
        <h2>7. How long it’s kept</h2>
        <ul>
            <li><strong>Your account</strong> is kept until you delete it (My account → Profile → Delete account) or ask us to.</li>
            <li><strong>When you delete your account</strong>, we remove your account, your family members, your plans and your bookings at every studio, cancel any renewing memberships, sign you out everywhere, and delete your activity summaries and risk scores. Your activity history and email log are kept without anything that identifies you.</li>
            <li><strong>Some records stay with each studio</strong>, without a link to your account, because they’re the studio’s business records: signed waivers (with the names, signature drawing, IP address and browser), sales and payments it recorded, and its payment history in its own Stripe account.</li>
            <li><strong>When a studio closes</strong>, everything in it is deleted, including its signed waivers, which its owner can download first.</li>
            <li><strong>Demo studios</strong> (from “Try the demo”) are deleted with everything in them, including any accounts made inside them, a day after they’re opened.</li>
            <li><strong>Files a studio uploads to import its members</strong> are kept only until they’re imported; unused uploads are deleted after a week.</li>
            <li><strong>Backups and server logs</strong> are kept by our host for a limited time and then overwritten.</li>
        </ul>
    </section>

    <section id="rights">
        <h2>8. Your choices and rights</h2>
        <ul>
            <li><strong>See and correct</strong> your details and your family’s in your account.</li>
            <li><strong>Delete</strong> your account yourself, at any time.</li>
            <li><strong>Stop marketing emails</strong> with the unsubscribe link in any of them, or in your profile.</li>
            <li><strong>Get a copy</strong> of your information, or ask us to restrict or stop using it, by emailing <a href="mailto:{{ $email }}">{{ $email }}</a>.</li>
        </ul>
        <p>Depending on where you live (for example in the EEA, the UK, Canada, Australia or some US states), you may have rights to access, correct, delete, port or restrict your information, to object to how it’s used, and to withdraw consent. To use them, email us; we’ll confirm who you are and reply within the time the law allows, and we won’t treat you differently for asking. Where a studio controls the information, we’ll pass your request to it or help it reply. You can also complain to your data protection authority.</p>
    </section>

    <section id="us-states">
        <h2>9. US state privacy rights</h2>
        <p>In the last 12 months we’ve collected the categories of information in section 2: identifiers (such as name, email and IP address), customer records, age (birthdays), commercial information (plans and purchases), internet activity (sign-ins and use of the Service), and sensitive information (account sign-in details, and health information you choose to add). We collect them for the purposes in section 3 and share them only as section 5 describes. We don’t sell or “share” personal information for cross-context behavioural advertising, and we use sensitive information only to provide the Service. Residents of California and other states with privacy laws can ask to know, correct or delete their information, by themselves or through an authorised agent, by emailing <a href="mailto:{{ $email }}">{{ $email }}</a>.</p>
    </section>

    <section id="children">
        <h2>10. Children</h2>
        <p>Loft accounts are for adults. Parents and guardians can add their children to their account to book classes for them, and choose what information to add. We don’t knowingly collect personal information directly from children under 13. If you think a child has created an account, email us and we’ll delete it.</p>
    </section>

    <section id="security">
        <h2>11. Security</h2>
        <p>We protect your information with encrypted connections (HTTPS), passwords stored as one-way hashes, access limited by each studio’s staff roles, expiring signed links for sensitive actions, and hosting with providers that maintain physical and network security. Card details are handled by Stripe and never stored by Loft. No system is perfectly secure; if a breach affects you, we’ll tell you and the studio as the law requires.</p>
    </section>

    <section id="transfers">
        <h2>12. Where your information is stored</h2>
        <p>Loft stores information in the United States. When it comes from the EEA, the UK or Switzerland, we protect it with the European Commission’s Standard Contractual Clauses and the UK and Swiss equivalents.</p>
    </section>

    <section id="changes">
        <h2>13. Changes to this policy</h2>
        <p>We may update this policy; the date at the top shows the latest version. If a change matters, we’ll tell you by email or in the Service before it applies.</p>
    </section>

    <section id="contact">
        <h2>14. Contact</h2>
        <p>{{ $entity }} · <a href="mailto:{{ $email }}">{{ $email }}</a></p>
    </section>
</article>
@endsection
