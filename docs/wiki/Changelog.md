# Changelog

Every change to the web app, newest first. Each links to its pull request. All were merged into `main` and deployed to Render automatically. Dates are UTC.

## In review

- **Video and live classes** (roadmap step 10)
  - **Online and hybrid classes:** programs and sessions say where a class happens: in the studio, online, or both. For a class that's both, members choose **In the studio** or **Online**; online spots are separate from the studio's seats (their own limit, no apparatus, no waitlist), and the schedule shows **Studio full · online open**. The choice carries through waivers and checkout.
  - **Joining:** people booked online get **Join online** under Upcoming from 15 minutes before class until it ends. It opens the studio's Zoom or Meet link, which isn't shown anywhere else, and checks them in. Booking emails say where. Rosters mark who's online; kiosks don't check them in. A class can't stop being hybrid while people hold online spots.
  - **Admin → Videos:** upload a file (straight to Mux) or add one from a link, follow it to **Ready**, preview it, choose who can watch (everyone with an account, or members whose plan includes videos), publish, delete. Each studio has 600 minutes of stored video.
  - **Videos for members:** a library page and a player (Mux Player, signed tokens, no tracking cookies). Locked videos say why and link to sign in, join, or the plans that include videos. Views are counted once a day and appear on the member's activity.
  - **Plans:** **Includes the video library**.
  - **API:** sessions say where they happen and how many online spots are left; bookings and checkouts take `online`; `POST …/bookings/{id}/join` returns the meeting link; `GET …/videos` and `POST …/videos/{id}/playback` ([API](API)).
  - **Decision 33**, including why the app won't sell plans that include videos. Roadmap step 10 is built.
  - **Database:** new tables `videos` and `video_views`; `delivery`, `online_capacity` and `meeting_url` on `programs` and `class_sessions`; `bookings.online_spot`; `plans.includes_videos`.
  - **Setup:** Mux keys in Render ([Deployment](Deployment#video-mux)).

## 2026-11-09

- **Abandoned shop checkouts are released reliably** ([#26](https://github.com/CyberpunkAidy/loft-studio/pull/26))
  - **The hourly job gets the Stripe key:** `render.yaml` gave `STRIPE_SECRET` only to `loft-web`, so `loft-housekeeping` couldn't ask Stripe about shop checkouts or card reader sales nobody finished. Those would have stayed pending and kept their stock for good. It's now declared on `loft-housekeeping` too.
  - **`checkout.session.expired`** releases a shop order the member walked away from, and puts its stock back, as soon as Stripe expires the session. The hourly sweep still settles anything a webhook missed.
  - **Decision 32**, which revises decision 27.
  - **Action needed:** add `STRIPE_SECRET` to `loft-housekeeping` → Environment in Render, with the same key as `loft-web`. A Blueprint sync won't add it. Then add `checkout.session.expired` to the Stripe webhook.
- **Churn scoring** (roadmap step 9, [#25](https://github.com/CyberpunkAidy/loft-studio/pull/25))
  - **Risk scores each night:** right after the member snapshot, every member gets a churn risk (0 to 100: high, medium, low, or lapsed) from rules a studio would recognise (time since their last class, coming less, nothing booked, a failed payment, no plan left), with the reasons in plain words and the model version (`rules-v1`).
  - **Members → At risk of leaving:** high and medium, riskiest first, and a risk column for everyone. Each member's page shows their score, why, and the nights before. The dashboard counts members at high risk.
  - **At risk of leaving automation:** a check-in email the day a member turns high; at most once a month, not right after a win-back email; marketing, so opted-in members only.
  - **Training export:** `loft:churn-export` writes the snapshots with pseudonymous members and a 30-day churn label, for a trained model later.
  - **Decision 31.** Roadmap step 9 is built with rules; the demo studio is scored when seeded.
  - **Database:** new table `member_risk_scores`.
- **Contactless check-in** (roadmap step 8, [#24](https://github.com/CyberpunkAidy/loft-studio/pull/24))
  - **Admin → Check-in devices:** add kiosks and readers (each gets a device code, shown once), remove them, and see every scan, allowed or not, and why.
  - **The kiosk** (`/{studio}/kiosk`): any browser, set up once with a device code. Works with USB or Bluetooth NFC readers and QR scanners that type what they read. Shows a big welcome, or why not and to see the front desk.
  - **Tags:** give a card or fob to anyone on a member's account from their admin page; take it back.
  - **Check-in codes:** **My account → Check-in** shows a QR code for each person, changing every 30 seconds; the API gives the app the same codes.
  - **The rule:** the person's class from an hour before it starts until it ends, through the front desk's check-in (waivers enforced). Device API: `POST /api/v1/devices/check-ins` ([API](API)).
  - **Decision 30.** Roadmap step 8 is complete.
  - **Database:** new tables `access_devices`, `access_credentials` and `access_events`.
- **Mobile API, v1** (roadmap step 7, [#23](https://github.com/CyberpunkAidy/loft-studio/pull/23))
  - **`/api/v1`** for the mobile app, documented on the new [API](API) page: sign in or register (Sanctum tokens, one per install), the member and their studios, push tokens.
  - **Per studio:** its details, plans and schedule (public; with a token, the member's own bookings), a class's booking options per person, booking and cancelling, the family, waivers and signing them, memberships and credits, purchases, and buying a plan through Stripe Checkout (finishing a booking once paid).
  - **Errors** in one shape with stable codes (`waiver_required`, `needs_plan`, `full`…); `Idempotency-Key` on booking, adding people, signing and checkout; cursor pages; UTC times plus the studio's local time; rate limits.
  - **Tokens** are revoked when the password is changed or reset and when the account is deleted. A website session never signs in to the API.
  - **Services first:** the booking options, waiver order, plan checkout and joining moved out of the web controllers (`BookingOptions`, `Waivers::nextToSign`, `PlanCheckout`, `Memberships`), so the website and the app share them.
  - **Decision 29.** Roadmap step 7 is complete.
  - **Database:** new tables `personal_access_tokens` (Sanctum) and `user_devices`.
- **Card readers** (roadmap step 6, last part, [#22](https://github.com/CyberpunkAidy/loft-studio/pull/22))
  - **Payments → Card readers:** the studio's address for Stripe once, then pair Stripe Terminal readers (WisePOS E, S700) by code; model, serial number and online status; readers added or removed in the Stripe Dashboard are picked up.
  - **Point of sale:** **Charge on Front desk** sends the cart to a reader. A waiting page refreshes itself until the card goes through (order numbered, plans granted, receipt sent), is declined (try again or cancel) or is cancelled (stock back, nothing charged). Offline or busy readers refuse the sale straight away.
  - **Refund** for card reader sales (receipt, Sales, member page): refunded to the card in Stripe, then voided.
  - **Housekeeping** settles reader sales left open for 15 minutes. Test mode can tap a test card on a simulated reader.
  - **Decision 28.** Roadmap step 6 is complete.
  - **Database:** new table `card_readers`; `studios.stripe_terminal_location_id`; `orders.card_reader_id`.
- **Online shop** (roadmap step 6, second part, [#21](https://github.com/CyberpunkAidy/loft-studio/pull/21))
  - **Shop** on the studio site: products by variant, a cart for anyone, checkout for members through Stripe Checkout with the studio's sales tax; picked up at the front desk.
  - **Stock is held while members pay** (30 minutes): coming back without paying, abandoning checkout or a failed bank payment puts it back. Paid orders get their number when paid.
  - **Pickups:** Admin → Sales → To pick up (and a dashboard count) lists paid shop orders; staff mark each one picked up. Members see "Ready to pick up" and get a pickup receipt by email.
  - **Housekeeping** settles shop checkouts whose webhook never arrived, by asking Stripe.
  - **Decision 27.** The demo studio has an online order waiting to be picked up.
  - **Database:** `orders.number` and `paid_at` can be empty while an order is pending; `orders.fulfillment_status`, `picked_up_at`, `picked_up_by_user_id`; `studios.stripe_tax_rate_id` and `stripe_tax_rate_milli`.
- **Merchandise and the point of sale** (roadmap step 6, first part, [#20](https://github.com/CyberpunkAidy/loft-studio/pull/20))
  - **Admin → Products:** merchandise with variants (sizes, colours), each with its own stock and optionally its own price; taxable or not; low stock flagged; hidden instead of deleted once sold.
  - **Admin → Point of sale:** products and plans in one cart, for a member or a walk-in; paid in person (cash, check, card terminal…) or comped, with a note. Stock never goes below zero; plans are granted as in-person payments are; one order records it, with tax per line. Receipt email and a printable receipt.
  - **Sales tax:** Settings → Merchandise, to three decimals; on taxable products only.
  - **Voiding** an in-person sale (receipt, Sales, member page) cancels its plans and restocks its products. Admin → Payments now lists every in-person sale.
  - **Database:** new tables `products` and `product_variants`; `order_items.product_variant_id` and `tax_cents`; `orders.walk_in` and `note`.
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
