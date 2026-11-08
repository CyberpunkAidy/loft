# Changelog

Every change to the web app, newest first. Each links to its pull request. All were merged into `main` and deployed to Render automatically. Dates are UTC.

## In review

- **Documentation and repository layout**
  - The project wiki (`docs/wiki/`, published by the Wiki workflow), and READMEs updated to match.
  - The WordPress plugin moved to `plugin/`. Its releases are paused, and the release checklist, zip script and `.gitattributes` zip rules are removed.
  - Decisions recorded:
    - keep Laravel, and build the mobile app with React Native against a Laravel API;
    - fold rooms and room-based apparatus into the roadmap (option A);
    - keep signed waivers.

## 2026-11-08

- **Delayed payments and payments taken in person** ([#13](https://github.com/CyberpunkAidy/loft-studio/pull/13))
  - Bank-debit and other delayed payments for packs and drop-ins are granted when they clear, and members are emailed if they fail.
  - Staff record cash, check, card-terminal, transfer and payment-app payments, and comps, with an amount, date, "valid until" date, note and who recorded it. Records can be voided.
  - Admin → Payments shows this month's in-person total.
  - Adds the `payments` table.
  - **Action needed:** add the two `checkout.session.async_payment_*` events to the Stripe webhook.
- **Emails restyled to match the site** ([#12](https://github.com/CyberpunkAidy/loft-studio/pull/12))
  - A shared layout built for email apps (tables, inline styles), and studio-branded messages.
  - A Loft-branded password and invitation email, and a PNG logo for email.
  - Apache sends a cross-origin header on fonts, so Apple Mail loads them; CI checks it.
- **Loft logo and redesigned UI** ([#11](https://github.com/CyberpunkAidy/loft-studio/pull/11))
  - The logo, favicons and per-studio favicons.
  - A new stylesheet with self-hosted Inter and Bricolage Grotesque.
  - Lucide icons in the admin sidebar, and a new home page.
  - Readable studio colors (`App\Support\Color`).
  - Fixed admin pages scrolling sideways on phones.
- **A studio's only owner can delete their account** ([#10](https://github.com/CyberpunkAidy/loft-studio/pull/10))
  - They hand the studio to a staff member, or close it.
  - Closing cancels members' renewing memberships at that studio first.
- **Users can delete their account** ([#9](https://github.com/CyberpunkAidy/loft-studio/pull/9))
  - From Profile → Delete account, everywhere at once.
  - Renewing memberships are cancelled in Stripe first, waitlists fill freed seats, and other logins are signed out.
  - Owners can make other owners.
- **Studio Stripe accounts created with Accounts v2** ([#8](https://github.com/CyberpunkAidy/loft-studio/pull/8)). Stripe blocks v1 account creation for new Connect platforms.

## 2026-11-07

- **Stripe webhooks of any API version** ([#7](https://github.com/CyberpunkAidy/loft-studio/pull/7)). The app takes only the object's ID from each webhook and fetches the object in its pinned version.
- **loftstudio.app for the site and email** ([#6](https://github.com/CyberpunkAidy/loft-studio/pull/6)). The custom domain on Render, and email sent from `hello@mail.loftstudio.app`.
- *Closed without merging:* [#5](https://github.com/CyberpunkAidy/loft-studio/pull/5) tried to read Stripe's newer payload shapes directly. #7 solved the same problem by fetching each object instead.
- **Email through Resend** ([#4](https://github.com/CyberpunkAidy/loft-studio/pull/4)).
- **Render build fix** ([#3](https://github.com/CyberpunkAidy/loft-studio/pull/3)). Docker paths are relative to `rootDir`.
- **Deploy on Render** ([#2](https://github.com/CyberpunkAidy/loft-studio/pull/2))
  - The Dockerfile and Blueprint: web, worker, hourly cron and Postgres.
  - Trust for Render's proxy.
- **The Loft web app** ([#1](https://github.com/CyberpunkAidy/loft-studio/pull/1))
  - The WordPress plugin rebuilt as a hosted service for many studios, in Laravel.
  - Studios, staff roles, schedules and seasons, booking with waitlists, plans and Stripe Connect checkout, family accounts, waivers, check-in, emails, and an hourly housekeeping job.
