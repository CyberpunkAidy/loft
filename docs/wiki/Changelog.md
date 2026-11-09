# Changelog

Every change to the web app, newest first. Each links to its pull request. All were merged into `main` and deployed to Render automatically. Dates are UTC.

## In review

- **Merchandise and the point of sale** (roadmap step 6, first part)
  - **Admin → Products:** merchandise with variants (sizes, colours), each with its own stock and optionally its own price; taxable or not; low stock flagged; hidden instead of deleted once sold.
  - **Admin → Point of sale:** products and plans in one cart, for a member or a walk-in; paid in person (cash, check, card terminal…) or comped, with a note. Stock never goes below zero; plans are granted as in-person payments are; one order records it, with tax per line. Receipt email and a printable receipt.
  - **Sales tax:** Settings → Merchandise, to three decimals; on taxable products only.
  - **Voiding** an in-person sale (receipt, Sales, member page) cancels its plans and restocks its products. Admin → Payments now lists every in-person sale.
  - **Database:** new tables `products` and `product_variants`; `order_items.product_variant_id` and `tax_cents`; `orders.walk_in` and `note`.

## 2026-11-09

- **Orders ledger** (roadmap step 5, [#19](https://github.com/CyberpunkAidy/loft-studio/pull/19))
  - **Every sale is an order** with items: each paid one-time checkout, each paid membership invoice (renewals and season registration fees included), and each payment taken in person (comps at no charge). Numbered per studio from #1001, recorded once each, with Stripe's actual amounts.
  - **Admin → Sales:** month by month, with totals (online and in person), every order and how it was paid, links to Stripe, and a CSV download. The dashboard shows sales this month.
  - **Members:** their purchases on their admin page (in-person ones can be voided there) and under Plans & billing.
  - **History:** `order.paid` and `order.voided` domain events. Payments already recorded in person become orders; earlier online sales stay in Stripe.
  - **Decision 24:** SMS will use each studio's own Twilio account.
  - **Database:** new tables `orders` and `order_items`; `payments.order_id`.

## 2026-11-08

- **Automations** (roadmap step 4, [#18](https://github.com/CyberpunkAidy/loft-studio/pull/18))
  - **Admin → Automations:** three emails that go out by themselves: after someone's first class (hours after check-in), birthdays (members and their children, from 10 AM studio time), and "we miss you" (days after a member's last class, if nothing is booked). Each is off until turned on.
  - **Editing:** subject and text with placeholders, a preview with sample names, and **Send me a test**.
  - **Marketing:** they go only to members who opted in, with the unsubscribe link, and each goes out once (dedupe keys). The delivery log records which automation sent each email.
  - **Outbox:** the first-class follow-up is the event relay's first consumer (`AutomationTriggers`).
  - **Database:** new table `automations`; `message_deliveries.automation_id`.

- **Event backbone** (roadmap step 3, [#17](https://github.com/CyberpunkAidy/loft-studio/pull/17))
  - **Outbox and history:** every booking, check-in, plan, payment failure, waiver signature and consent change records a domain event in the same transaction as the change. A check-in records `booking.attended`, flagged when it's the attendee's first class. A relay hands events to handlers (none yet; automations come next).
  - **Member activity:** each member's admin page shows their recent activity and the emails sent to them.
  - **Email log:** every studio email is logged in `message_deliveries` with a dedupe key, so it goes out once, and is marked sent (with Resend's ID) or failed. Replaces `sent_notifications`, whose rows are copied over.
  - **Consent:** members opt in to a studio's news and offers when joining or in their profile. Marketing email carries a signed one-click unsubscribe link and `List-Unsubscribe` headers, and isn't sent without consent.
  - **Snapshots:** each night after 2 AM studio time, every member's activity is saved to `member_snapshots` for churn scoring later.
  - **Database:** new tables `domain_events`, `message_deliveries` and `member_snapshots`; consent columns on `studio_users`. `sent_notifications` is no longer written (dropped in a later release).

- **Rooms, apparatus capacity and waitlist limits** (roadmap step 2, [#16](https://github.com/CyberpunkAidy/loft-studio/pull/16))
  - **Rooms:** Admin → Rooms. Each room has a capacity limit, and classes in it can't be bigger.
  - **Apparatus:** what's rigged in each room (how many, and the most people on each). A program chooses the apparatus its sessions use, and each session can change it.
  - **Booking:** each booking takes a numbered spot ("Silks 3"). Members choose the apparatus, with spots left on each, or "No preference"; the choice survives signing a waiver and paying at checkout. Staff add people on a chosen apparatus and move them between apparatus.
  - **Waitlists:** can be capped per class (0 = no waitlist). Entries wait for the apparatus they asked for.
  - **Apply to upcoming sessions:** a program's capacity, waitlist limit, room and apparatus can be copied to its existing sessions.
  - **Room clashes:** the session page warns when another class is in the room at the same time, or apparatus is over-used.
  - **Fixed:** the demo seeder (`composer setup`, `migrate:fresh --seed`) failed after waiver hardening; a test now runs it.
  - **Database:** new tables `rooms`, `apparatus`, `program_apparatus` and `session_apparatus`; room and waitlist columns on `programs` and `class_sessions`; apparatus columns on `bookings`.
- **Waiver hardening** (roadmap step 1, [#15](https://github.com/CyberpunkAidy/loft-studio/pull/15))
  - **Waivers:** several can be in force at once, for every class or for chosen classes only (such as an aerial liability waiver), each with its own validity.
  - **Versions:** every published version's text is kept. Signatures record the version, a hash of the exact text, the attendee's and signer's names, and how they're related. Once anyone has signed, changing the text publishes a new version.
  - **Enforced everywhere:** member booking, waitlist promotion (a lapsed waiver keeps its place until signed), staff booking and check-in. Staff can let someone in by giving a reason, which is recorded.
  - **Kept and downloadable:** signed waivers stay when accounts are deleted. Admins can download every signature as a ZIP.
  - **Database:** new tables `waiver_versions`, `program_waiver` and `waiver_overrides`, plus new signature columns. Existing signatures are backfilled.
- **Documentation and repository layout** ([#14](https://github.com/CyberpunkAidy/loft-studio/pull/14))
  - The project wiki (`docs/wiki/`, published by the Wiki workflow), and READMEs updated to match.
  - The WordPress plugin moved to `plugin/`. Its releases are paused, and the release checklist, zip script and `.gitattributes` zip rules are removed.
  - Decisions recorded:
    - keep Laravel, and build the mobile app with React Native against a Laravel API;
    - fold rooms and room-based apparatus into the roadmap (option A);
    - keep signed waivers.
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
