# Legal

Loft's [Terms of Service](https://loftstudio.app/terms) and [Privacy Policy](https://loftstudio.app/privacy) ([decision 38](Decisions)). **They're a starting point written from how Loft actually works; have a lawyer review them** before relying on them, and again before taking on studios in a new country.

## What they cover

- **Terms of Service** (`/terms`): who they're for (studios and members), accounts, acceptable use, the studio subscription (trial, billing, cancelling, price changes, grace and pause, as in [Loft Subscription](Loft-Subscription)), studios' responsibilities (payments through their own Stripe accounts, classes and safety, waivers, members' data, marketing, content), members' terms, our IP, availability, ending use, disclaimers, limits on liability, studio indemnity, changes, and **section 15, the data processing terms**: Loft as the studios' processor for their members' data, subprocessors, security, breaches, deletion, and the EU Standard Contractual Clauses and UK and Swiss equivalents for transfers to the US. Governed by **Wyoming** law.
- **Privacy Policy** (`/privacy`): Loft and the studio's roles, what's collected (matching [Accounts and Data](Accounts-and-Data)), how it's used, legal bases, who it's shared with, cookies (essential only), how long it's kept, rights (including US states), children, security and transfers.

Both name **Goldseam Tech LLC** and **privacy@loftstudio.app**.

## Where it lives

| What | Where |
|---|---|
| The texts | `web/resources/views/platform/legal/terms.blade.php` and `privacy.blade.php` |
| Operator, state, contact email | `config/loft.php` → `legal`, or `LOFT_LEGAL_ENTITY`, `LOFT_LEGAL_STATE`, `LOFT_LEGAL_EMAIL` |
| Versions (shown as "Last updated") | `config/loft.php` → `legal.terms_version`, `legal.privacy_version` |
| Prices, trial and grace in the terms | Read from the billing settings, so they always match the pricing page |
| The price lock (section 4) | "Your price is locked": a subscription keeps its price while it continues on the same plan; a new price applies only to new subscriptions or plan switches. With the yearly plan on, section 4 also covers monthly or yearly billing and switching. Matches how billing works ([Loft Subscription](Loft-Subscription)) |

Links: the site, studio and admin footers; the studio signup form (a **required** checkbox); the member join form, the welcome page for imported members, and the mobile app's sign-up and Account screens (a line saying creating an account agrees to them).

## Who agreed, and when

- **Studios:** `studios.terms_accepted_at` and `terms_version`, from the signup checkbox.
- **People:** `users.terms_accepted_at` and `terms_version`, when they create an account (studio signup, joining a studio on the website or in the app) or, for imported members, when they choose a password from their invitation. Accounts created before this, and staff invited by email, have none until they're asked.

## Changing them

1. Edit the text, then set the version in `config/loft.php` to the date it takes effect.
2. **For changes that matter, email studios' owners at least 30 days before they apply** (the terms promise this, as they do for price changes, removing important features, and adding or replacing a subprocessor).
3. **Subprocessors:** section 15.5 of the terms lists Render, Resend, Mux and Stripe. Adding one (for example Twilio for SMS, or Expo for push notifications) means updating that list and emailing owners 30 days ahead.

## To set up

- **privacy@loftstudio.app must reach you.** In Porkbun, add an email forward for `privacy@loftstudio.app` (Domain → Email forwarding). Mail sent from Loft uses `mail.loftstudio.app`, so forwarding on the main domain doesn't affect it.
- **App stores** ask for a privacy policy URL: use `https://loftstudio.app/privacy` ([Mobile App](Mobile-App)).
