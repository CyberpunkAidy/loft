# Architecture

## Stack

| Layer | Choice |
|---|---|
| Language and framework | PHP 8.3, Laravel 13 |
| Pages | Server-rendered Blade templates; one hand-written stylesheet (`public/css/app.css`) and one small script (`public/js/app.js`); no JavaScript build step |
| Database | PostgreSQL 16 in production; SQLite for local development and tests |
| Background work | Laravel queue on the database (a worker service), plus an hourly scheduled command |
| Payments | Stripe Connect through a thin REST client (no Stripe SDK) |
| Email | Resend, through Laravel's `resend` mailer |
| Hosting | Render: a Docker image (`php:8.3-apache`) used by three services, plus a managed Postgres database |

## Code layout (`web/`)

| Path | What's there |
|---|---|
| `app/Models` | Eloquent models: `Studio`, `StudioUser`, `User`, `Season`, `Category`, `Program`, `ClassSession`, `Attendee`, `Plan`, `Entitlement`, `Booking`, `Waiver`, `WaiverSignature`, `Payment`, `StripeEvent`, `SentNotification` |
| `app/Services` | The business rules: `Bookings`, `Entitlements`, `SessionGenerator`, `Waivers`, `AccountDeletion`, `OfflinePayments`, and `Stripe/StripeClient` and `Stripe/StripeWebhooks` |
| `app/Http/Controllers` | `Admin/*` (staff), `Studio/*` (members and the public), `Auth/*`, `PlatformController`, `StripeWebhookController` |
| `app/Events`, `app/Listeners` | Domain events and the `StudioEmails` listener that sends member emails |
| `app/Mail` | `StudioMessage`, the queued mailable behind every studio email |
| `app/Support` | Helpers: `Color` (readable brand colors), `Icon`, `Money`, `Search`, `Timezones`, `ReturnPath`, `BookingException` |
| `app/Console/Commands` | `loft:housekeeping`, run hourly |
| `resources/views` | Blade templates: `layouts`, `platform`, `auth`, `studio`, `admin`, `mail`, `components` |
| `resources/icons` | A subset of Lucide icons (ISC license) |
| `database/migrations` | The schema |
| `docker/` | Apache config, PHP config and `start.sh` (the container's entry point) |
| `tests/Feature` | The test suite |

The repository root also holds `render.yaml` (the Render Blueprint) and `.github/workflows/web.yml` (CI).

## Tenancy

- **A studio is a tenant.** Every studio-owned row carries `studio_id`.
- **URLs are path-based:** `/{studio}/…`, where `{studio}` is the studio's slug. Some slugs are reserved for the platform (`config/loft.php`).
- **Child records resolve through the studio.** Routes use `scopeBindings()` plus an explicit `{studio}` binding (`AppServiceProvider`), so `/a/admin/programs/{id}` can't load studio B's program; it returns 404.
- **Users are global.** One login works at every studio. A user's role in each studio lives in `studio_users`, ordered `member < instructor < admin < owner`, and the `studio.role:` middleware enforces it.

| Role | Can do |
|---|---|
| member | Book, manage family, buy plans, sign waivers |
| instructor | Check-in |
| admin | Everything in the studio admin |
| owner | Everything, can't be removed, can make other owners |

## Data model

| Table | Holds |
|---|---|
| `studios` | Name, slug, time zone, currency, brand color, contact email, settings (JSON), Stripe account state |
| `studio_users` | A user's role at a studio, and their Stripe customer ID on that studio's account |
| `seasons` | Date ranges that weekly classes run across |
| `categories` | Age groups, levels and plan tags |
| `programs` | A class or one-off event: weekday and time, duration, capacity, age limits, price, instructor |
| `class_sessions` | Each dated occurrence of a program, with its own capacity, instructor and status |
| `attendees` | The people who come to class: the account holder and their children, with date of birth, medical notes and emergency contact |
| `plans` | What a studio sells: `subscription` (monthly), `season` (monthly until the season ends), `pack` (credits) or `dropin` |
| `entitlements` | What an account holds: a live membership or a credit balance, with scope and weekly limits |
| `bookings` | An attendee in a session: `booked`, `waitlisted` (with position), `cancelled`, `late_cancelled`, `no_show` or `attended` |
| `waivers`, `waiver_signatures` | Versioned waiver text, and who signed which version for whom (with an optional drawn signature) |
| `payments` | Payments staff recorded by hand, and comps ([Payments](Payments)) |
| `stripe_events` | Every Stripe webhook received, for deduplication and retries |
| `sent_notifications` | Makes reminder and expiry emails send once |

All datetimes are stored in UTC and shown in the studio's time zone. Money is stored in cents.

## Services

- **`Bookings`:** books, waitlists, cancels and promotes. Every seat change locks the session row, so two people can't take the last seat. Promotion re-checks the waiting person's plan before giving them the seat.
- **`Entitlements`:** finds the plan that can pay for a class (scope, weekly limit, credits), uses and refunds credits, and grants plans.
- **`SessionGenerator`:** creates a program's sessions across its season. It's daylight-saving safe.
- **`Waivers`:** whether an attendee's waiver is signed and still valid, and signing.
- **`StripeClient`:** the Stripe REST calls: v1 for most things, v2 for creating and onboarding studio accounts.
- **`StripeWebhooks`:** verifies, deduplicates and processes Stripe events, and fulfils checkouts.
- **`AccountDeletion`:** deletes a user everywhere, and hands over or closes studios they solely own.
- **`OfflinePayments`:** records and voids payments taken in person.

Controllers stay thin and call these services. The planned mobile API will call the same services ([Roadmap](Roadmap)).

## Events and email

Services dispatch Laravel events. The `StudioEmails` listener turns them into queued, studio-branded emails:

| Event | Email |
|---|---|
| `BookingCreated` | "You're booked!" or "You're on the waitlist" |
| `BookingCancelled` | "Booking cancelled" |
| `WaitlistPromoted` | "A spot opened up — you're in!" |
| `SessionBookingCancelled` | "Class cancelled" |
| `EntitlementGranted` | "Thanks for your purchase" (a receipt, for payments recorded in person) |
| `PaymentFailed` | "Payment failed — action needed" |
| `CheckoutPaymentFailed` | "Your payment didn't go through" |
| `MemberJoined` | "Welcome!" |

The hourly `loft:housekeeping` command sends class reminders and credit-expiry warnings, and expires plans past their end date. `sent_notifications` makes sure each of those sends once.

These events are in-memory only, and nothing keeps a history of them. The [Roadmap](Roadmap) replaces that with a durable event log (an outbox), which marketing automations and churn scoring will build on.

## Security notes

- **Stripe webhooks:** checked against the signing secret. Each event is processed once (`stripe_events`); a failure returns 500 so Stripe retries.
- **Forms:** use CSRF tokens. Destructive actions ask for confirmation.
- **Proxy:** the app trusts Render's proxy (`TRUSTED_PROXIES=*`), so it sees HTTPS and the real client IP. Session cookies are secure-only in production.
- **Waiver signatures:** the drawn signature images are stored on a private disk, not in the web root.
