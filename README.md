# Loft Studio (v0.1.1)

Class scheduling, memberships, family accounts, waivers and Stripe billing for studios — as a WordPress plugin.
Built by Goldseam Tech. No Composer, no build step, no external SDKs. Stripe handles all money.

> **The hosted web app lives in [`web/`](web/README.md)** and runs at **https://loftstudio.app**. It's the same
> product for many studios at once (Laravel, Postgres, Stripe Connect), with the same booking, billing and
> waiver rules. This plugin is unchanged and still releases from the repository root; `web/` is left out of
> plugin zips. The web app's documentation (architecture, deployment, Stripe, decisions, roadmap,
> changelog) is in the [project wiki](https://github.com/CyberpunkAidy/loft-studio/wiki), generated from
> [`docs/wiki/`](docs/wiki).

## Install (staging first)

1. Upload the `loft-studio` folder to `wp-content/plugins/` and activate.
2. **Settings → General**: confirm the site timezone (e.g. America/Denver). All class times use it.
3. **Loft Studio → Settings**: studio name, accent color, policies, email from-address, Stripe keys.
4. Create four pages and drop in the shortcodes, then select them in Settings → Pages:
   - Schedule: `[loft_schedule]`
   - My Account: `[loft_account]`
   - Plans: `[loft_plans]`
   - Waiver: `[loft_waiver]`
   - Optional: Join: `[loft_register]` (or use your existing registration form; new users need role `loft_member`)
   - Staff check-in (tablet): `[loft_checkin]` on a page only staff know about (it's capability-gated anyway)
5. **Loft Studio → Waivers**: add the waiver text, tick *This is the active waiver*, publish.
6. **Loft Studio → Seasons**: add e.g. "2026–27 Season", Sept 1 → May 31, current.
7. **Loft Studio → Programs**: add each class. Set weekday, start time, duration, capacity, season, Age Group, Level, and **Plan Tags** (e.g. `recreational`, `competitive`). Save, then **Generate sessions**.
   - For a one-off workshop: Type = *One-off event*, set *Event date* and *Drop-in / event price*, tag it `events`.
8. **Loft Studio → Plans & Pricing**: create plans, then **Create in Stripe** for each.
   - Monthly membership: kind *subscription*, $90, scope `recreational`, weekly limit blank (unlimited) or 1–2.
   - Competitive: kind *subscription*, $115, scope `competitive`.
   - Seasonal tuition: kind *season*, pick the season, optional registration fee. Stripe auto-cancels at season end.
   - Drop-in: kind *dropin*, $25, scope `events` (or all).
   - 5-class pack: kind *pack*, credits 5, validity 90 days.

## Stripe setup

- Dashboard → Developers → API keys: paste test keys into Settings (switch to live keys at launch).
- Developers → Webhooks → Add endpoint: the URL shown in Settings. Events:
  `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`.
  Paste the signing secret into Settings.
- Settings → Billing → Customer portal: enable, allow cancelling and updating payment methods.
- Test with card `4242 4242 4242 4242`. Use Stripe CLI (`stripe listen --forward-to https://staging.example.com/wp-json/loft/v1/stripe/webhook`) on local/staging if the site isn't publicly reachable.

## How it fits together

- **Programs** (CPT) → **Sessions** (table, generated per season) → **Bookings** (per attendee).
- **Accounts** are WP users (`loft_member`); **Attendees** are the people in classes (kids or self).
- **Plans** are what you sell; **Entitlements** are what an account holds (a live subscription or a credit balance). Webhooks create/update entitlements; booking checks entitlement scope (via Plan Tags) and weekly limits, or consumes a credit.
- "Book" with no valid entitlement returns the eligible plans → member picks one → Stripe Checkout → webhook grants the entitlement and completes the original booking automatically.
- Waitlist auto-promotes on cancellation/capacity increase. Late cancels (configurable hours) keep the credit by default.
- Hourly cron: reminders, credit-expiry warnings, expiring credit packs.

## Theming

All UI uses CSS variables on `:root` (`--loft-accent`, `--loft-ink`, `--loft-card`, `--loft-radius`, …). Override in Elementor → Custom CSS or the theme. Elementor: drop a Shortcode widget.

## Hooks for extension

Actions: `loft_booking_created($booking, $waitlisted)`, `loft_booking_cancelled($booking, $late)`, `loft_waitlist_promoted`, `loft_session_cancelled_booking`, `loft_entitlement_granted($e, $plan, $user_id)`, `loft_payment_failed`, `loft_subscription_cancelled`, `loft_member_registered`, `loft_stripe_event($event)`, `loft_send_sms($user_id, $text, $key, $ctx)`.
Filters: `loft_email_subject_{key}`, `loft_email_body_{key}`.

## REST

Base `/wp-json/loft/v1/` — see `ARCHITECTURE.md`. Member endpoints need the logged-in cookie + `X-WP-Nonce`.

## Roadmap (v2)

SMS adapter (Twilio), spectator tickets, makeup credits, sibling discounts via Stripe coupons, branded PWA, multi-location, licensing/update server.
