# Changelog

Every change to the web app, newest first. Each links to its pull request. All were merged into `main` and deployed to Render automatically. Dates are UTC.

## In review

- **Try the demo** ([decision 44](Decisions), [Demo Studio](Demo-Studio))
  - **[loftstudio.app/demo](https://loftstudio.app/demo):** each visitor gets their own copy of a sample aerial and pole studio (63 members, a booked-up week, a month of sales), signed in as the owner, for a day. The home page's second button is now **Try the demo**.
  - **See it as a member:** switch to a parent with two kids to book, sign a waiver or see a check-in code, and back.
  - **Nothing real happens:** no emails, no payments, refunds or Stripe accounts, no video uploads; accounts made inside a demo are deleted with it. The Payments and Subscription pages explain.
  - **Instant:** two spares are built ahead on the worker; housekeeping deletes finished demos hourly.
  - **Counting:** `loft:signups` shows demos started and how many went on to start a studio.
  - **One sample studio:** the local seed and the demos are built by the same code; `SEED_BUSY=1` seeds the lived-in month.
  - **Privacy Policy:** demo studios are deleted after a day.
  - **Database:** `demo_runs`; `studios.demo_expires_at` and `demo_claimed_at`; `users.demo_expires_at` and `demo_studio_id`.
  - **Settings:** `LOFT_DEMO_HOURS`, `LOFT_DEMO_POOL`, `LOFT_DEMO_MAX_LIVE` (optional).

## 2026-11-11

- **Render: a Loft project** ([Deployment](Deployment), [#41](https://github.com/CyberpunkAidy/loft-studio/pull/41))
  - `loft-web`, `loft-worker`, `loft-housekeeping`, `loft-db` and the `loft-shared` group now sit in a **Loft** project with a **Production** environment, set in `render.yaml`. Nothing is recreated or restarted; settings are unchanged.

- **How studios find Loft, and referrals** ([decision 43](Decisions), [Referrals and Signups](Referrals), [#39](https://github.com/CyberpunkAidy/loft-studio/pull/39))
  - **"How did you hear about Loft?"** is now asked when a studio starts (required, with optional details), and the first page of the visit is saved with the studio: campaign tags (`utm_*`), the site that linked to Loft, and the landing page.
  - **Every studio has a referral link and code,** like `loftstudio.app/r/SKYLINE`, under **Admin → Subscription → Give a month, get a month**, with a Copy button and the studios it has brought in.
  - **Studios that join with a code get 60 days free** instead of 30. Loft's pages say who invited them, and the signup form fills in the code.
  - **The referrer gets a month off its Loft bill** once the new studio first pays (or 30 more trial days if it hasn't subscribed), with a thank-you email. Comped studios earn nothing more.
  - **Partner codes** for instructors and others: `php artisan loft:partner-code MAYA --name="Maya"`.
  - **Report:** `php artisan loft:signups` shows new studios by answer and by code, and how many have subscribed.
  - **Terms and Privacy Policy** (now dated November 11, 2026): a "Referrals" paragraph in section 4; the privacy policy covers the signup answer, where a visit began, and the referral cookie.
  - **Decision 43.**
  - **Database:** `referral_codes`; `studios.referral_code_id`, `referral_rewarded_at`, `referral_reward`, `referral_reward_cents`, `heard_from`, `heard_from_detail` and `signup_source`.
  - **Settings:** `LOFT_REFERRAL_BONUS_DAYS` (optional, `30` by default; `0` turns rewards off).

- **Security headers** ([decision 42](Decisions), [Architecture](Architecture), [#38](https://github.com/CyberpunkAidy/loft-studio/pull/38))
  - **Every response** now tells browsers: only Loft can show its pages in a frame; stay on HTTPS for a year, subdomains included; don't guess file types; give other sites only `loftstudio.app` as the referrer; no camera, microphone or location. Error pages, redirects, the API and the maintenance page included.
  - **Static files** (styles, scripts, images, fonts) are sent with `nosniff` by Apache.
  - **Decision 42.**

## 2026-11-10

- **Yearly checkout explains the free time** ([Loft Subscription](Loft-Subscription), [#37](https://github.com/CyberpunkAidy/loft-studio/pull/37))
  - Stripe Checkout shows the rest of Loft's trial as days free, which owners took for the yearly plan's 2 free months. A line by the pay button now says the free period is the trial and the 2 months are in the price; the Subscription page and the pricing page's yearly question say the same.

- **Studio logos** ([decision 41](Decisions), [#36](https://github.com/CyberpunkAidy/loft-studio/pull/36))
  - **Admin → Settings → Logo:** upload a PNG, JPEG or WebP (up to 2 MB), see how it looks in the header, replace or remove it. SVG isn't accepted.
  - **Every logo is redrawn:** decoded and drawn again so only the pixels are kept, shrunk to fit 960 × 240, and saved as PNG (JPEG for JPEG uploads).
  - **Where it shows:** the studio's pages, the admin sidebar, the check-in kiosk, studio emails, My studios, and the mobile app (the find-a-studio card and the schedule's header). A wide logo stands in for the name; a square one sits beside it. Without a logo, the initial shows as before; the favicon stays the initial.
  - **Served for good** from `/{studio}/logo/{hash}.png` (or `.jpg`), with no session or cookies; logos in older emails lead to the current one.
  - **API:** studios and `/me`'s studios carry `logo` (`url`, `width`, `height`) or `null`.
  - **Docker image:** now includes GD (PNG, JPEG, WebP); CI checks it.
  - **Home and pricing pages** now mention logos.
  - **Decision 41.**
  - **Database:** `studio_logos`; `studios.logo_hash`, `logo_type`, `logo_width` and `logo_height`.

- **Yearly plan, price lock and a new pricing page** ([Loft Subscription](Loft-Subscription), [#35](https://github.com/CyberpunkAidy/loft-studio/pull/35))
  - **Yearly billing:** studios choose $49 a month or $490 a year (two months free) when they subscribe. Monthly subscribers can **Switch to yearly** from Admin → Subscription, or switch either way in the billing portal; Stripe prorates it.
  - **Price lock:** a studio keeps the price it subscribed at for as long as it stays on that plan. The Subscription page shows the plan and amount it pays, and the terms (section 4) now promise the lock instead of 30 days' notice of a change.
  - **/pricing, rebuilt:** a monthly/yearly switch, the price lock, everything included by area, what else you pay (Stripe's card fees) with the savings calculator, a comparison with what studios often find elsewhere, nine questions (including one studio per location), and structured data for both prices.
  - **Home page:** the price card mentions the yearly plan and the price lock; the calculator notes the yearly price.
  - **Owner emails** offer both plans.
  - **Decision 40.**
  - **Database:** `studios.billing_interval` and `billing_amount_cents`.
  - **Settings:** `LOFT_YEARLY_PRICE_CENTS` (optional; ten months of the monthly price by default, `0` turns yearly off).

- **A new home page** ([Home Page](Home-Page), [#34](https://github.com/CyberpunkAidy/loft-studio/pull/34))
  - **loftstudio.app** now sells one flat price with everything included: "Your whole studio, one flat price." Real screenshots from a demo studio (the owner's dashboard, a parent's phone, booking a silk or hoop, the check-in roster, sales, members at risk, an import preview) replace the drawn schedule.
  - **For every class-based studio,** with a section on booking by apparatus for aerial, pole and reformer studios.
  - **A savings calculator:** a studio's current software bill and any fee on card sales, against a year of Loft. It names no other products, leaves Stripe's own fees out of both sides, and says so when Loft would cost more.
  - **Free help moving from another system**, and a support address, `hello@loftstudio.app` (`LOFT_SUPPORT_EMAIL`), also in the site footer as **Contact**.
  - **Questions** on the page: fees, moving members, apps, getting started, the trial, leaving, and support.
  - **Search and sharing:** meta descriptions on the home and pricing pages, a share image for links, and structured data for the price and the questions.
  - **Dashboard:** once every setup step is done, the Setup card becomes **Your schedule**, with the studio's link (now clickable, without `https://`).
  - **Decision 39.**
  - **Action needed:** forward hello@loftstudio.app to your inbox (Porkbun).

- **Terms of Service and Privacy Policy** ([Legal](Legal), [#33](https://github.com/CyberpunkAidy/loft-studio/pull/33))
  - **/terms and /privacy**, written from how Loft works: the studio subscription (prices, trial and grace from the billing settings), studios' responsibilities (their own Stripe accounts, classes, waivers, members' data, marketing), members' terms, data processing terms for studios (subprocessors, security, breaches, deletion, EU and UK transfer clauses), and what's collected, kept and shared. Goldseam Tech LLC, Wyoming law, privacy@loftstudio.app.
  - **Agreeing:** starting a studio needs a ticked box; joining a studio, signing up in the app, or choosing a password from an import invitation says it agrees. Who agreed, when, and to which version is recorded.
  - **Links** in the site, studio and admin footers, and in the mobile app (sign-up and Account).
  - **Decision 38.**
  - **Database:** `terms_accepted_at` and `terms_version` on `users` and `studios`.
  - **Action needed:** forward privacy@loftstudio.app to your inbox (Porkbun), and have a lawyer review both documents.
- **Data import from another system** ([Data Import](Data-Import), [#32](https://github.com/CyberpunkAidy/loft-studio/pull/32))
  - **Admin → Members → Import:** upload a CSV from Mindbody, Momence, Punchpass, WellnessLiving or a spreadsheet. Columns are matched by name (staff can change any), and a preview shows what will be imported and which rows are skipped and why. Imports run in the background and can be undone for 30 days.
  - **Members and families:** rows sharing an email, or a parent's email, become one family, with birthdays, medical notes, emergency contacts and marketing consent. Existing Loft accounts join as they are.
  - **Credits and memberships:** matched to the studio's plans by name. Packs keep the classes they have left; memberships run until their paid-through date. Nothing is recorded as a sale.
  - **Invitations when the studio chooses:** a studio-branded email with each member's family and plans, and a link to choose a password; reminders for those who haven't come in.
  - **Carrying memberships on:** a week before an imported membership ends, invited members are asked to start it in Loft; Checkout trials until the old end date, so the card is first charged when it ends.
  - **Imported people aren't new:** no "first class" email for their first class in Loft, and no "never visited" churn flag.
  - **Fix:** an API test that failed between midnight and 5 AM UTC.
  - **Decision 37.**
  - **Database:** `imports`; `import_id` on `users`, `studio_users`, `attendees` and `entitlements`; `studio_users.invited_at` and `invitation_accepted_at`; `member_snapshots.imported`.

## 2026-11-09

- **Studios pay Loft: $49 a month after a 30-day free trial** ([Loft Subscription](Loft-Subscription), [#31](https://github.com/CyberpunkAidy/loft-studio/pull/31))
  - **Trial:** new studios start a 30-day free trial with no card; staff see the days left at the top of the admin.
  - **Admin → Subscription:** owners subscribe through Stripe Checkout on Loft's own Stripe account. In the trial the card is first charged when it ends, and promotion codes work. **Manage billing** opens Stripe's billing portal: card, invoices, cancelling at the end of the month.
  - **If it lapses:** when the trial or subscription ends, or a payment fails, everything works for 7 more days with a warning, then the studio pauses. Staff see only the Subscription page, and members can't book, join waitlists, buy plans or order from the shop online (`studio_paused` in the API); automations wait. Nothing is deleted, and paying restores it all.
  - **Emails to owners,** from Loft: 3 days before the trial ends, when it lapses, and when the studio pauses.
  - **Pricing page** (`/pricing`), linked from the header and home page; the signup page shows the trial and price.
  - **Comps:** every studio already on Loft is free. `php artisan loft:billing <studio>` shows where a studio stands, comps it, ends a comp or sets its trial.
  - **Closing a studio** cancels its Loft subscription.
  - **Decision 36.**
  - **Database:** `studios.trial_ends_at`, `comped_at`, `comp_note` and `billing_*`.
  - **Action needed:** add the billing webhook in Stripe and `STRIPE_BILLING_WEBHOOK_SECRET` on `loft-web` ([setup](Loft-Subscription#setting-it-up-once)).
- **The mobile app, first version** (`mobile/`, [#30](https://github.com/CyberpunkAidy/loft-studio/pull/30))
  - **One Loft app for every studio**, for iOS and Android, built with Expo on the API. Members find their studio by its web address, then sign in, create an account or join; the app takes on the studio's color, and follows the phone's light or dark setting.
  - **Booking:** the schedule a week at a time in the studio's time zone; each class with a line per person on the account: book, join the waitlist, sign a waiver first, or buy a plan that covers it (the booking finishes once paid). Online or in the studio for hybrid classes; apparatus where there's a choice.
  - **My classes:** upcoming and past, cancelling, and **Join online** for online classes.
  - **Account:** rotating check-in codes per person, family and their waivers, adding someone, plans and purchases, buying a plan in an in-app browser (not plans with videos), switching studios, signing out, deleting the account on the website.
  - **Videos:** the library and player.
  - **Checks:** a type check and 21 tests on every change (`.github/workflows/mobile.yml`), and the web build. Not in the stores yet: that needs Apple and Google developer accounts ([Mobile App](Mobile-App)).
  - **Decision 35.**
- **API** ([#30](https://github.com/CyberpunkAidy/loft-studio/pull/30)): upcoming bookings now stay upcoming until the class ends, checked-in ones too, as on the website, so the app can rejoin an online class; past is what has ended.
- **The old email log is dropped** ([#29](https://github.com/CyberpunkAidy/loft-studio/pull/29))
  - **Database:** the `sent_notifications` table is dropped. It was replaced by `message_deliveries` when the event backbone went live ([#17](https://github.com/CyberpunkAidy/loft-studio/pull/17)), which copied its rows (class reminders and credit-expiry warnings already sent), and nothing has used it since. Rolling back brings the table back empty.
- **Refunds** ([#28](https://github.com/CyberpunkAidy/loft-studio/pull/28))
  - **Refund from Loft:** **Refund** on Sales, a member's page or a card reader receipt opens a page that says what the refund does, then gives back what's left of the payment through Stripe. Online sales (packs, drop-ins, membership payments, shop orders) and card reader sales.
  - **What a full refund takes back:** packs and drop-ins are cancelled (classes already booked stay), products go back into stock, and a shop order no longer waits to be picked up. For a membership or season payment, staff choose **Refund this payment only** or **Refund and end the membership now** (cancelled in Stripe at once).
  - **Refunds made in the Stripe Dashboard** reach Loft by `charge.refunded` and are recorded the same way; a full one takes back the same things, but never ends a membership. Partial refunds are recorded only.
  - **Totals** on Sales, the dashboard and Payments are what the studio kept; Sales shows refunds as their own figure, the CSV has a Refunded column and statuses (Partly refunded, Refunded), and the member's activity shows each refund. Members see refunds under Plans & billing; the API's orders have `refunded_cents`.
  - **Card reader sales** are refunded the same way, and now show as Refunded rather than Void.
  - **Decision 34.** The Stripe refunds gap on the roadmap is closed.
  - **Database:** `orders.refunded_cents`, `refunded_at` and `refunded_by_user_id`.
  - **Action needed:** add `charge.refunded` to the Stripe webhook's events.
- **Video and live classes** (roadmap step 10, [#27](https://github.com/CyberpunkAidy/loft-studio/pull/27))
  - **Online and hybrid classes:** programs and sessions say where a class happens: in the studio, online, or both. For a class that's both, members choose **In the studio** or **Online**; online spots are separate from the studio's seats (their own limit, no apparatus, no waitlist), and the schedule shows **Studio full · online open**. The choice carries through waivers and checkout.
  - **Joining:** people booked online get **Join online** under Upcoming from 15 minutes before class until it ends. It opens the studio's Zoom or Meet link, which isn't shown anywhere else, and checks them in. Booking emails say where. Rosters mark who's online; kiosks don't check them in. A class can't stop being hybrid while people hold online spots.
  - **Admin → Videos:** upload a file (straight to Mux) or add one from a link, follow it to **Ready**, preview it, choose who can watch (everyone with an account, or members whose plan includes videos), publish, delete. Each studio has 600 minutes of stored video.
  - **Videos for members:** a library page and a player (Mux Player, signed tokens, no tracking cookies). Locked videos say why and link to sign in, join, or the plans that include videos. Views are counted once a day and appear on the member's activity.
  - **Plans:** **Includes the video library**.
  - **API:** sessions say where they happen and how many online spots are left; bookings and checkouts take `online`; `POST …/bookings/{id}/join` returns the meeting link; `GET …/videos` and `POST …/videos/{id}/playback` ([API](API)).
  - **Decision 33**, including why the app won't sell plans that include videos. Roadmap step 10 is built.
  - **Database:** new tables `videos` and `video_views`; `delivery`, `online_capacity` and `meeting_url` on `programs` and `class_sessions`; `bookings.online_spot`; `plans.includes_videos`.
  - **Setup:** Mux keys in Render ([Deployment](Deployment#video-mux)).
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
