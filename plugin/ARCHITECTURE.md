# Loft Studio — Architecture

Working name: **Loft Studio** (slug `loft-studio`, prefix `loft_`, PHP namespace `Loft\`).
Rename later via the constants in `loft-studio.php`; nothing is tied to a studio name.

Target: WordPress 6.4+, PHP 8.1+, MySQL 5.7+/MariaDB 10.3+. No Composer dependencies at runtime.
Payments: Stripe Billing via Checkout Sessions + Customer Portal + webhooks (no Stripe SDK; thin
client over `wp_remote_request`).

## Concepts

| Term | Meaning |
|---|---|
| **Program** | A class offering. CPT `loft_program`. Has age group, level, default price, default capacity, instructor, and a recurrence rule (e.g. Tuesdays 4:30–5:30 Sept–May). One-off events are programs with `type = event` and a single session. |
| **Session** | A concrete occurrence of a program on a date/time with its own capacity. Table `loft_sessions`. Generated from the program's recurrence; individually editable/cancellable. |
| **Season** | Named date range (e.g. "2026–27 Season", Sept 1–May 31). Table `loft_seasons`. Programs belong to a season; seasonal tuition plans are tied to one. |
| **Account** | A WordPress user (role `loft_member`). Owns billing. Usually a parent or an adult student. |
| **Attendee** | A person who attends classes. Table `loft_attendees`. Belongs to an account. The account holder may also be an attendee ("self"). Children are attendees without logins. |
| **Plan** | Something you can buy. Table `loft_plans`. Kinds: `subscription` (monthly, Stripe Price recurring), `season` (seasonal tuition: Stripe subscription with `cancel_at` = season end, optional registration fee), `pack` (N credits, one-time Stripe Price), `dropin` (1 credit, one-time). A plan grants **entitlements** to a set of programs (via program tags/IDs). |
| **Entitlement** | What an account currently holds. Table `loft_entitlements`. Rows: active subscription (program scope, unlimited bookings per week or `weekly_limit`), credit balance (`credits_remaining`, expiry). Updated by Stripe webhooks and by bookings. |
| **Booking** | An attendee reserved into a session. Table `loft_bookings`. Status: `booked`, `waitlisted`, `cancelled`, `late_cancelled`, `no_show`, `attended`. Records which entitlement paid for it. |
| **Waiver** | A versioned document (CPT `loft_waiver`). Signatures stored per attendee in `loft_waiver_signatures` (name typed, IP, timestamp, waiver version, signed by account user). Re-sign required when a new version is published or after `waiver_validity_days`. |

## Data model (custom tables, prefix `{$wpdb->prefix}loft_`)

```
seasons           id, name, starts_on, ends_on, is_current, created_at
sessions          id, program_id, season_id, starts_at (UTC), ends_at (UTC), capacity, status(scheduled|cancelled),
                  instructor_id (user), price_cents (override, nullable), notes, created_at
attendees         id, account_user_id, first_name, last_name, dob, is_self(bool), medical_notes, emergency_contact,
                  status(active|archived), created_at
plans             id, name, kind(subscription|season|pack|dropin), price_cents, currency, interval(month|null),
                  credits(int|null), weekly_limit(int|null), season_id(nullable), registration_fee_cents,
                  stripe_product_id, stripe_price_id, stripe_fee_price_id, program_scope(json: tag slugs or 'all'),
                  active(bool), sort, created_at
entitlements      id, account_user_id, plan_id, kind(subscription|credits), status(active|past_due|cancelled|expired),
                  stripe_subscription_id, stripe_customer_id, credits_total, credits_remaining, expires_at,
                  current_period_end, program_scope(json), weekly_limit, created_at, updated_at
bookings          id, session_id, attendee_id, account_user_id, entitlement_id(nullable), status, position(int, for waitlist),
                  credit_consumed(bool), booked_at, cancelled_at, attended_at, source(web|admin|waitlist_promote)
waiver_signatures id, waiver_post_id, waiver_version, attendee_id, signed_by_user_id, signed_name, ip, user_agent, signed_at
stripe_events     id, event_id (unique), type, received_at, processed_at, payload(longtext)   -- idempotency log
```

WP users table: role `loft_member`; user meta `loft_stripe_customer_id`, `loft_phone`.
Custom post types: `loft_program` (with taxonomies `loft_age_group`, `loft_level`, `loft_tag`), `loft_waiver`.

## Key flows

### Purchase (subscription / season / pack / drop-in)
1. Member clicks "Buy" on a plan → `POST /loft/v1/checkout` (nonce + logged in).
2. Server ensures Stripe Customer exists (`loft_stripe_customer_id`), creates **Checkout Session**
   (`mode=subscription` for subscription/season, `mode=payment` for pack/dropin), with `metadata`
   {account_user_id, plan_id, attendee_id?}. Season plans add `subscription_data.cancel_at = season.ends_on`
   and a one-time registration fee line.
3. Redirect to Stripe. Success URL = account page `?loft_checkout=success`.
4. **Webhook** `checkout.session.completed` → create entitlement (subscription: status active, period end;
   credits: credits_total/remaining, expires_at = now + plan.credit_validity_days).
5. `invoice.paid` → extend `current_period_end`; `invoice.payment_failed` → status `past_due` + email;
   `customer.subscription.updated|deleted` → sync status; `cancelled` entitlements can no longer book.

### Booking a session
1. `POST /loft/v1/bookings` {session_id, attendee_id}.
2. Guards (all server-side): attendee belongs to user; attendee has a valid waiver signature for current waiver;
   session not cancelled, not in past, booking window open (`booking_opens_days_before`);
   attendee not already booked; account has a usable entitlement covering the program
   (active subscription in scope with weekly_limit not exceeded, else credits_remaining > 0 and not expired).
   If none, respond `402 needs_plan` with eligible plans so the UI can offer checkout.
3. If `booked_count < capacity` → status `booked`, consume credit if credit-based. Else → `waitlisted` with position.
4. Email confirmation (or waitlist notice).

### Cancellation / waitlist promotion
- Member cancels via `DELETE /loft/v1/bookings/{id}`. If ≥ `late_cancel_hours` before start → `cancelled`,
  credit refunded. Else `late_cancelled`, credit kept (policy configurable).
- On any freed seat, first waitlisted booking is promoted (status `booked`, credit consumed, email sent).
  Promotion can require confirmation within N hours (setting) — v1: auto-promote.

### Waivers
- Admin publishes a `loft_waiver` post; bumping post content increments version (post meta `_loft_version`).
- Booking requires a signature for the attendee on the current version within validity window.
- Signature page: `[loft_waiver attendee=…]` → typed full name + checkbox + optional drawn signature (canvas PNG, stored in uploads).
- Minors: signed_by_user_id must be the account holder; signed_name is the guardian's name.

### Attendance / check-in
- `[loft_checkin]` shortcode (capability `loft_manage_attendance`) shows today's sessions and rosters;
  tap to mark attended / no-show. No-shows optionally consume credit (setting).

### Reminders (WP-Cron, hourly)
- Class reminder email `reminder_hours_before` before each session to booked attendees' accounts.
- Credit expiry warning 7 days before. Subscription past_due nudge. SMS: `do_action('loft_send_sms', $to, $msg)` hook for a v2 Twilio adapter.

## Roles & capabilities
- `loft_member`: book/cancel own bookings, manage own attendees, sign waivers, buy plans.
- `loft_instructor` (role): `loft_manage_attendance` (check-in screen, rosters).
- Administrator: `loft_manage_studio` (settings, programs, plans, seasons, all bookings).

## REST API (`/wp-json/loft/v1/`)
```
GET    /schedule?from=&to=&group=&level=        public; sessions + spots left
GET    /programs                                 public
GET    /plans                                    public
GET    /me                                       member: attendees, entitlements, upcoming bookings, waiver status
POST   /attendees  PATCH /attendees/{id}         member
POST   /waivers/{attendee_id}/sign               member
POST   /bookings   DELETE /bookings/{id}         member
POST   /checkout                                 member → {url}
POST   /portal                                   member → Stripe customer portal {url}
POST   /stripe/webhook                           Stripe (signature verified)
GET    /admin/roster?session_id=                 attendance cap
POST   /admin/attendance                         attendance cap
```

## Frontend
Shortcodes (also registered as Elementor-friendly; each is a plain div the JS mounts into):
- `[loft_schedule group="kids" weeks="2"]` — schedule grid with filters, spots left, Book buttons.
- `[loft_account]` — tabs: Upcoming, Family/Attendees, Plans & Billing, Waivers.
- `[loft_plans]` — plan cards with Buy.
- `[loft_waiver]` — signing UI.
- `[loft_checkin]` — staff check-in (requires capability).
- `[loft_login]` / `[loft_register]` — optional; can reuse WP/Elementor forms.

Vanilla JS (no build step), one `assets/app.js` + `assets/app.css` with CSS variables for theming
(`--loft-accent`, etc.) so each studio brand is a few lines of CSS.

## Settings (Settings → Loft Studio)
Studio name, timezone (defaults to WP), currency, Stripe keys (test/live) + webhook secret, late-cancel hours,
booking window, no-show policy, credit validity days, reminder hours, email from/name, waiver validity days,
account page URL, schedule page URL, terms text.

## Multi-studio / product notes
- Zero hardcoded studio data; all seeded via settings & admin.
- Licensing hook: `loft_license_check` filter (no enforcement in v1).
- Updates: standard WP plugin updater hook stubbed (`Loft\Updater`) for a future Goldseam update server.

## Non-goals for v1
- Spectator/show tickets, POS/retail, payroll, native mobile app, multi-location, SMS sending (hook only).
