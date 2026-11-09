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
| `app/Models` | Eloquent models: `Studio`, `StudioUser`, `User`, `Season`, `Category`, `Room`, `Apparatus`, `Program`, `ClassSession`, `Attendee`, `Plan`, `Entitlement`, `Booking`, `Waiver`, `WaiverVersion`, `WaiverSignature`, `WaiverOverride`, `Payment`, `StripeEvent`, `DomainEvent`, `MessageDelivery`, `MemberSnapshot`, `Automation`, `Order`, `OrderItem`, `Product`, `ProductVariant` |
| `app/Services` | The business rules: `Bookings`, `BookingOptions`, `Entitlements`, `SessionGenerator`, `Rooms`, `Waivers`, `WaiverDownload`, `AccountDeletion`, `OfflinePayments`, `PlanCheckout`, `Memberships`, `DomainEvents`, `Messages`, `Consent`, `MemberSnapshots`, `Automations`, `AutomationTriggers`, `Orders`, `PointOfSale`, `CardReaders`, `ReaderPayments`, `Shop`, and `Stripe/StripeClient` and `Stripe/StripeWebhooks` |
| `app/Http/Controllers` | `Admin/*` (staff), `Studio/*` (members and the public), `Auth/*`, `Api/V1/*` (the mobile app's API, `routes/api.php`), `PlatformController`, `StripeWebhookController` |
| `app/Http/Resources/Api` | The API's JSON shapes; `app/Http/ApiErrors.php` renders every API error with a stable code ([API](API)) |
| `app/Events`, `app/Listeners` | In-process events and the `StudioEmails` listener that sends member emails |
| `app/Contracts`, `app/Jobs` | `DomainEventHandler` (consumers of the outbox), and the `RelayDomainEvents` and `SendAutomatedMessage` jobs |
| `app/Mail` | `StudioMessage`, the queued mailable behind every studio email |
| `app/Support` | Helpers: `Color` (readable brand colors), `Icon`, `Money`, `Search`, `Timezones`, `ReturnPath`, `BookingException`, `ApparatusSlot` (one apparatus's spots in a session), `ApparatusAllocation` (the apparatus fields on the program and session forms), `Csv` (spreadsheet-safe CSV), `CheckoutException` (a point-of-sale sale that can't go through) |
| `app/Console/Commands` | `loft:housekeeping`, run hourly |
| `resources/views` | Blade templates: `layouts`, `platform`, `auth`, `studio`, `admin`, `mail`, `components` |
| `resources/icons` | A subset of Lucide icons (ISC license) |
| `database/migrations` | The schema |
| `docker/` | Apache config, PHP config and `start.sh` (the container's entry point) |
| `tests/Feature` | The test suite |

Elsewhere in the repository:
- `render.yaml`: the Render Blueprint.
- `.github/workflows/`: CI (`web.yml`) and the wiki publisher (`wiki.yml`).
- `docs/wiki/`: this wiki.
- `plugin/`: the original WordPress plugin, kept for reference; releases are paused.

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
| `studio_users` | A user's role at a studio, their Stripe customer ID on that studio's account, and marketing email consent (when, where, and when they unsubscribed) |
| `seasons` | Date ranges that weekly classes run across |
| `categories` | Age groups, levels and plan tags |
| `rooms` | A studio's rooms, each with a capacity limit (`max_capacity`) |
| `apparatus` | What's rigged in a room: how many (`quantity`) and the most people on each (`max_per_item`) |
| `programs` | A class or one-off event: weekday and time, duration, capacity, age limits, price, instructor, and the room and waitlist limit for new sessions |
| `class_sessions` | Each dated occurrence of a program, with its own capacity, waitlist limit (`waitlist_capacity`, null = none), room, instructor and status |
| `program_apparatus`, `session_apparatus` | The apparatus a class uses (`quantity`, `max_per_item`): a program's default, copied to each new session, which can change it |
| `attendees` | The people who come to class: the account holder and their children, with date of birth, medical notes and emergency contact |
| `plans` | What a studio sells: `subscription` (monthly), `season` (monthly until the season ends), `pack` (credits) or `dropin` |
| `entitlements` | What an account holds: a live membership or a credit balance, with scope and weekly limits |
| `bookings` | An attendee in a session: `booked`, `waitlisted` (with position), `cancelled`, `late_cancelled`, `no_show` or `attended`. With apparatus: which one (`apparatus_id`; for a waitlist entry, the one asked for, or null for any) and which item (`apparatus_item`, "Silks 3") |
| `waivers`, `waiver_versions`, `program_waiver` | Waivers (for every class or for attached programs, with optional validity), and the exact text of every published version |
| `waiver_signatures` | Who signed which version for whom: names, relationship, a hash of the text, IP address, browser, optional drawing. Kept, unlinked, when accounts are deleted |
| `waiver_overrides` | Staff letting someone into a class without a signature, and why |
| `orders`, `order_items` | The ledger: every sale (online checkout, membership invoice, in person, point of sale, online shop), numbered per studio when paid (shop orders are pending, unnumbered, while the member pays), with its items (plans, products with their tax), amounts, how it was paid, Stripe IDs, for in-person sales a note or a walk-in flag, and for shop orders whether they've been picked up ([Payments](Payments#the-orders-ledger)) |
| `products`, `product_variants` | Merchandise, and its sizes or colours, each with its own stock (null = not tracked) and optional price |
| `personal_access_tokens`, `user_devices` | The mobile app's sign-ins (Sanctum tokens, one per install) and each install's push token |
| `card_readers` | The studio's Stripe Terminal readers (Stripe's reader ID, a name, model and serial number); the Terminal Location is `studios.stripe_terminal_location_id`. A sale charged on one records it in `orders.card_reader_id` |
| `payments` | Payments staff recorded by hand, and comps, each linked to its order ([Payments](Payments)) |
| `stripe_events` | Every Stripe webhook received, for deduplication and retries |
| `domain_events` | The outbox and history: every booking, check-in, plan, payment failure, waiver signature and consent change, with IDs and facts only ([below](#events-and-email)) |
| `message_deliveries` | Every studio email: its kind, transactional or marketing, status (queued, sent, failed, suppressed), the automation that sent it (if any), and a unique dedupe key |
| `member_snapshots` | Each member's activity as of each night, for churn scoring later |
| `automations` | Each studio's automated emails (one per trigger: first class, birthday, we miss you): text, timing, on or off |
| `sent_notifications` | Replaced by `message_deliveries`, which it was copied into. No longer written; to be dropped in a later release |

All datetimes are stored in UTC and shown in the studio's time zone. Money is stored in cents.

## Services

- **`BookingOptions`**, **`PlanCheckout`** and **`Memberships`:** what each person can do in a class, buying a plan (with a booking to finish), and joining a studio. The website's controllers and the API both call them, so the rules are the same on both ([decision 29](Decisions)).
- **`Bookings`:** books, waitlists, cancels and promotes. Every seat change locks the session row, so two people can't take the last seat. A booking needs room in the class and, when the class uses apparatus, a spot on the apparatus; waitlists can be capped. Promotion re-checks the waiting person's plan, waiver and apparatus before giving them the seat. Staff can move a booking to another apparatus.
- **`Entitlements`:** finds the plan that can pay for a class (scope, weekly limit, credits), uses and refunds credits, and grants plans.
- **`SessionGenerator`:** creates a program's sessions across its season, with the program's room, apparatus and waitlist limit. It's daylight-saving safe. It can also copy those settings to upcoming sessions.
- **`Rooms`:** room clashes for staff: other classes in the same room at the same time, and apparatus used by more classes at once than are rigged. A warning, not a booking rule.
- **`Waivers`:** which waivers a class needs, whether an attendee has signed them, signing a version, overrides, and saving text without editing a signed version.
- **`WaiverDownload`:** a studio's signed waivers as a ZIP.
- **`StripeClient`:** the Stripe REST calls: v1 for most things, v2 for creating and onboarding studio accounts.
- **`StripeWebhooks`:** verifies, deduplicates and processes Stripe events, and fulfils checkouts.
- **`AccountDeletion`:** deletes a user everywhere, and hands over or closes studios they solely own.
- **`OfflinePayments`:** records and voids payments taken in person.
- **`Orders`:** the ledger. Records an order for each paid one-time checkout, each paid membership invoice, each payment taken in person and each point-of-sale sale; voids in-person sales (cancelling their plans and restocking their products).
- **`PointOfSale`:** the front-desk till: prices the cart (with sales tax), then in one transaction takes stock, grants plans and records the order; emails the receipt.
- **`CardReaders`** and **`ReaderPayments`:** Stripe Terminal at the point of sale. Readers are paired on the studio's account; a sale sent to one holds its stock in a pending order with a card-present PaymentIntent, and is recorded (numbered, plans granted, receipt) when the front desk's waiting page or the hourly sweep finds it paid. Refunds go back to the card, then void the sale.
- **`Shop`:** the online shop: holds stock in a pending order, opens a 30-minute Stripe Checkout on the studio's account, marks the order paid (webhook or return page) and emails a pickup receipt, or releases it and restocks. Housekeeping settles checkouts whose webhook never came.
- **`DomainEvents`:** the outbox. Records events inside the caller's transaction, and relays them to handlers.
- **`Messages`:** sends and logs every studio email, once per dedupe key; marketing email only with consent.
- **`Consent`:** marketing email opt-in and opt-out, recorded with its source.
- **`MemberSnapshots`:** each night's member snapshot.
- **`Automations`:** sends the automated emails: first-class follow-ups from the outbox (through `AutomationTriggers`, the registered handler), birthdays and win-backs from housekeeping ([Email](Email#automations)).

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

Every email goes through `App\Services\Messages`, which logs it in `message_deliveries` under a dedupe key (such as `reminder:booking:42`), so it goes out once however often the code that sends it runs. The delivery is marked `sent` when the mailer sends it (with Resend's message ID) and `failed` if the queue gives up.

The hourly `loft:housekeeping` command sends class reminders and credit-expiry warnings, expires plans past their end date, takes each studio's nightly member snapshots, sends birthday and win-back automations, settles online shop checkouts whose webhook never came and card reader sales left open (by asking Stripe), and relays any domain events still waiting.

### The outbox (`domain_events`)

The Laravel events above are in-process: they trigger emails, and nothing remembers them. Alongside them, each change records a **domain event** in the **same database transaction** as the change itself, so the event exists exactly when the change does.

| Type | Recorded when |
|---|---|
| `member.joined` | Someone joins a studio |
| `booking.booked`, `booking.waitlisted`, `booking.promoted` | A booking is made, waitlisted, or moved in from the waitlist |
| `booking.cancelled` | A booking is cancelled: `by` member, staff, studio (class cancelled) or waitlist (no plan covered it), with `late` |
| `booking.attended`, `booking.no_show`, `booking.check_in_undone` | Check-in. `attended` carries `first_visit`: the attendee's first class at the studio |
| `session.cancelled` | The studio cancels a class |
| `entitlement.granted` | A plan is bought or given: `source` stripe, cash, check, card, transfer, app, other, comp or staff |
| `entitlement.cancel_scheduled`, `entitlement.cancel_unscheduled` | A member sets their membership to end, or changes their mind |
| `entitlement.ended` | A plan ends: `reason` cancelled, expired or voided |
| `payment.failed`, `checkout.failed` | A renewal fails (once per lapse), or a delayed checkout payment fails |
| `waiver.signed` | A waiver is signed (the waiver, version and relationship) |
| `order.paid`, `order.voided` | A sale is recorded in the ledger (number, channel, method, total, items), or voided |
| `consent.email_marketing_granted`, `consent.email_marketing_withdrawn` | Marketing consent changes (`source`: join, profile or unsubscribe_link) |

- **What's in an event:** the studio, the account and attendee it's about, the type, when, and a payload of IDs and facts: never names or email addresses. Deleting an account unlinks its events, which stay as anonymous history.
- **Relaying:** after the transaction commits, the `RelayDomainEvents` job hands new events to each handler in `config('loft.event_handlers')` (classes implementing `App\Contracts\DomainEventHandler`). Each event is relayed in its own transaction, with its row locked (`FOR UPDATE SKIP LOCKED` on Postgres). A handler that throws is retried by later relays, up to 5 times, and the error is kept on the event (`relay_attempts`, `relay_error`). Housekeeping sweeps up anything left.
- **Handlers:** delivery is at least once and not strictly ordered, so handlers must be idempotent. One is registered: `AutomationTriggers`, which turns a first visit into the first-class follow-up ([Email](Email#automations)). With none, events are kept as history and marked relayed.
- **Reading it:** each member's page in the admin shows their recent activity from these events.
- **Adding a type:** add it to `DomainEvent::TYPES` (unknown types are refused), and record it inside the transaction that makes the change.

### Member snapshots

Each night, after 2 AM in the studio's time zone, housekeeping saves every member's activity into `member_snapshots` (`App\Services\MemberSnapshots`). The counts cover classes before that night's midnight: days since the last visit, visits in the last 28 days and the 28 before that, no-shows and late cancels, upcoming bookings, whether they hold a membership, credits left, failed payments in the last 90 days, family size and how long they've been a member. Staff aren't included. Snapshots are kept so churn scoring can later look at what was known on each date; they're deleted with the account.

## Security notes

- **Stripe webhooks:** checked against the signing secret. Each event is processed once (`stripe_events`); a failure returns 500 so Stripe retries.
- **Forms:** use CSRF tokens. Destructive actions ask for confirmation.
- **Proxy:** the app trusts Render's proxy (`TRUSTED_PROXIES=*`), so it sees HTTPS and the real client IP. Session cookies are secure-only in production.
- **Waiver signatures:** the drawn signature images are stored on a private disk, not in the web root.
- **The API** takes bearer tokens only (Sanctum's session guard is off), so a signed-in browser can't be used against it and it needs no CSRF tokens. Tokens are stored hashed, revoked on password change or reset and account deletion, and rate-limited; studio routes are scoped like the website's ([API](API)).
