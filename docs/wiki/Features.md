# Features

## Platform (loftstudio.app)

- **Home page:** what Loft does and costs, with real screenshots, a savings calculator, free help moving from another system, questions and answers, and a way to start a studio ([Home Page](Home-Page)). Every platform page's footer has a **Contact** address.
- **Pricing** (`/pricing`): $49 a month or $490 a year per studio (two months free), everything included, a 30-day free trial, the price locked while a studio stays subscribed, the full feature list, what studios pay Stripe, the savings calculator, a comparison with typical studio software, and questions ([Loft Subscription](Loft-Subscription)).
- **Start your studio** (`/signup`): creates the studio with its name, web address (slug), time zone and currency, and asks how they heard about Loft. The person who signs up becomes its owner, and the studio starts its 30-day free trial, or 60 days with a referral code ([Referrals and Signups](Referrals)).
- **Signing in:** one login for every studio someone belongs to. **My studios** (`/studios`) lists them, with links to each schedule, account and studio admin.
- **Password reset:** also used to invite new staff (see [Email](Email)). Changing or resetting a password signs the mobile app out.
- **Mobile API** (`/api/v1`): what the mobile app will use. Members sign in or register, see the schedule and their bookings, book and cancel (with the same rules and stable error codes), manage their family, sign waivers, see their plans and purchases, and buy plans through Stripe Checkout. See [API](API).

## Members (`/{studio}`)

- **Schedule:** two weeks at a time (configurable), filtered by age group and level. Each class shows its time, instructor, room, tags, drop-in price and spots left.
- **Booking:** choose who's coming (yourself or family members), then:
  1. Each person needs a signed, current waiver.
  2. The booking needs a plan that covers the class: a membership within its weekly limit, or a credit.
  3. If there's none, the member picks a plan, pays by Stripe Checkout, and the booking completes automatically after payment.
- **Online and hybrid classes:** a class can be in the studio, online, or both. For one that's both, members choose **In the studio** or **Online**: online spots are separate from the studio's seats (with their own limit, no apparatus and no waitlist), so a full studio can still have room online. People booked online see **Join online** under Upcoming from 15 minutes before class until it ends; it opens the studio's Zoom, Meet or other link, and joining checks them in. The link isn't shown anywhere else. The choice is kept through signing a waiver and paying at checkout.
- **Apparatus:** when a class uses more than one kind (say silks and lyra), members choose one, with the spots left on each, or "No preference". The choice is kept through signing a waiver and paying at checkout. The account page shows the spot, such as "Silks 3".
- **Waitlists:** a full class takes a waitlist spot, unless the class caps its waitlist and the cap is reached. When a seat frees up, the next person is booked automatically if their plan still covers it, and emailed.
- **Cancelling:**
  - From the account page, up to class start.
  - Inside the late-cancel window, the credit is kept by the studio, unless the studio's settings say otherwise.
- **My account tabs:**
  - **Upcoming** and **History:** classes booked and attended.
  - **Family:** add, edit or remove attendees; medical notes and emergency contacts.
  - **Plans & billing:** memberships and credits, their purchases (with order numbers, and shop orders to pick up), a link to Stripe's billing portal (cards, invoices, cancelling), and buying more.
  - **Check-in:** a QR code for each person on the account, to scan at the studio's kiosk; it changes every 30 seconds.
  - **Waivers:** every waiver in force, per attendee, and what each is for ("Only for Silks"). Signing, with an optional drawn signature, goes from one missing waiver to the next.
  - **Profile:** name, email, phone, password, news and offers from the studio (marketing email, opt-in), and **deleting the account** ([Accounts and Data](Accounts-and-Data)).
- **Joining:** an optional, unticked box to get the studio's news and offers. Every marketing email has a one-click **Unsubscribe** link ([Email](Email#marketing-email-and-consent)).
- **Plans page:** every plan a studio sells, with checkout. Plans that include the video library say so.
- **Videos** (once the studio has published some): recorded classes and tutorials, playing in the page (Mux Player). Anyone can browse; watching needs an account at the studio, and some videos need a current plan that includes videos (a membership or season that's active, or a pack that hasn't expired or run out). Locked videos say why, with a link to sign in, join, or the plans that include them.
- **Shop** (once the studio has products for sale): products with their sizes or options, sold-out and low-stock labels, and a cart anyone can fill. Members check out through Stripe Checkout (sales tax added), and their items are held for 30 minutes while they pay. Paid orders are picked up at the front desk; Plans & billing shows "Ready to pick up" until then ([Payments](Payments#5-the-online-shop)).

- **Check-in kiosk** (`/{studio}/kiosk`): a browser at the door, set up once with a device code. Members tap a tag or scan their check-in code and are checked in to their class (from an hour before it starts until it ends), or told why not.

## Staff (`/{studio}/admin`)

| Area | What it does | Who |
|---|---|---|
| Dashboard | Members, members at high risk of leaving, sales this month, shop orders to pick up, active memberships, past-due, bookings in the last 7 days, waitlists; today's classes; a setup checklist, which becomes the studio's schedule link once every step is done | admin |
| Check-in | Today's classes (or any date), roster with waiver status, apparatus spot and medical notes, mark attended or no-show | instructor+ |
| Check-in devices | Kiosks and readers members check themselves in at (each with a device code, shown once), and every scan with its result. Tags (cards, fobs) are given out on a member's page | admin |
| Sessions | Every class by date, rosters (people booked online are marked), capacity (raising it promotes the waitlist), waitlist limit, where it happens (in the studio, online, or both, with online spots and the meeting link), room and apparatus, instructor, notes, add someone (optionally comped, on a chosen apparatus), move someone to another apparatus, remove, cancel the class (emails everyone, returns credits). Warns when another class is in the same room at the same time, or the room's apparatus is over-used | admin |
| Programs | Weekly classes and one-off events; generate sessions across the season. Room, apparatus, waitlist limit and where it happens (with online spots and the meeting link) for new sessions, and **Apply to upcoming sessions** to update existing ones. A class can't stop being in the studio and online while people hold online spots | admin |
| Videos | The on-demand library: upload a file (straight to Mux, Loft's video host) or add one from a link; it shows **Processing** until it's ready. Title, description, who can watch (everyone with an account, or members whose plan includes videos), in the library or not; preview, views and viewers; delete (removed from Mux too). Each studio has an allowance of stored minutes (600 unless Loft changes it) | admin |
| Seasons | Date ranges for weekly classes | admin |
| Rooms | Each room's capacity limit (such as its fire-code limit) and the apparatus rigged in it: how many, and the most people on each | admin |
| Automations | Emails that go out by themselves: **after a first class** (a set number of hours after check-in), **birthdays** (members and their children, from 10 AM), **we miss you** (a set number of days after the last class, if nothing is booked), and **at risk of leaving** (the day a member's churn risk turns high). Edit the subject and text with placeholders, see a preview, send yourself a test, turn each on or off; see how many went out | admin |
| Members | Search; **At risk of leaving** (members with a high or medium churn risk, riskiest first, with a risk column for everyone); family, waivers, plans, upcoming and past classes; **Risk of leaving** (the latest score, its reasons and the nights before); **Activity** (bookings, check-ins, plans, payment problems, waivers, consent, newest first); **Emails** (each email and whether it was sent, and marketing consent); **Purchases** (every order, online or in person); **Add a plan** (record a payment taken in person, or comp); void payments taken in person | admin |
| Import | Members → Import: members and families, then credits and memberships, from another system's CSV export. Columns matched by name and checked in a preview; undo for 30 days; invitations sent when staff choose, and reminders ([Data Import](Data-Import)) | admin |
| Plans & pricing | Memberships, seasonal tuition, packs and drop-ins; sync each plan to Stripe; **Includes the video library** | admin |
| Point of sale | The front-desk till: products and plans in one cart, for a member or a walk-in; sales tax on products; charged on a Stripe card reader, or paid another way in person, or comped; stock taken; receipt email and printable receipt; void (restocks, cancels plans); card reader sales are refunded to the card | admin |
| Products | Merchandise with variants (sizes, colours), each with its own stock and optionally its own price; taxable or not; hidden instead of deleted once sold; low stock flagged | admin |
| Sales | The orders ledger: every sale (online checkouts, each membership payment, in person, the online shop), a month at a time, with what the studio kept (refunds taken off), links to Stripe and a CSV download. **Refund** any sale paid through Stripe: a page says what it takes back (packs, drop-ins, products; for a membership payment, staff choose whether the membership ends), and refunds made in the Stripe Dashboard show here too. **To pick up:** paid shop orders waiting at the front desk, each marked picked up when collected | admin |
| Payments | Connect Stripe; everything taken in person (point of sale and plans added from a member's page) and this month's total | admin |
| Card readers | Payments → Card readers: the studio's address for Stripe, pairing Stripe Terminal readers by code, their model, serial number and online status, removing them | admin |
| Waivers | Several waivers, each for every class or only chosen classes, with its own validity. Text is versioned: once anyone has signed, changing it publishes a new version and everyone signs again. Turn waivers on or off. **Download signed waivers** as a ZIP | admin |
| Staff | Add instructors and admins (new people get an email to set a password); owners can make other owners | admin |
| Settings | **Logo** (PNG, JPEG or WebP, with a preview of the header; replace or remove), name, brand color, time zone, currency, email sender name and reply-to, booking policies, sales tax on products | admin |
| Subscription | The studio's Loft subscription: the free trial and days left, choosing monthly or yearly and subscribing through Stripe Checkout, the plan and locked price the studio pays, the billing portal (card, invoices, switching plans, cancelling) and **Switch to yearly**, and what happens if it lapses. The only page left while the studio is paused. Banners elsewhere in the admin during the trial and before a pause. **Give a month, get a month:** the studio's referral link and code, with the studios it has brought in ([Referrals and Signups](Referrals)) | owners subscribe; all staff see it; owners and admins see the referral card |

## Booking rules (studio settings)

| Setting | Default | Meaning |
|---|---|---|
| `booking_opens_days_before` | 30 | How far ahead members can book |
| `booking_closes_minutes_before` | 0 | When online booking closes before class (0 = at start) |
| `late_cancel_hours` | 12 | Cancelling within this many hours of class is a late cancel |
| `late_cancel_keeps_credit` | false | Whether a late cancel still returns the credit |
| `no_show_consumes_credit` | true | Whether a no-show uses the credit |
| `credit_validity_days` | 90 | How long pack credits last, unless the plan says otherwise |
| `waiver_validity_days` | 365 | How long a signature stays valid (0 = forever) |
| `reminder_hours_before` | 24 | When the class reminder email goes out |
| `waitlist_auto_promote` | true | Whether freed seats go to the waitlist automatically |
| `minor_age` | 18 | Under this age, a parent or guardian signs the waiver |
| `schedule_weeks` | 2 | Weeks shown on the public schedule |

Other rules:
- **Members only:** a program can be "members only", in which case only memberships and seasonal tuition can book it, not packs or drop-ins.
- **Age limits:** a program's age limits are checked against each attendee's date of birth.
- **Plan scope:** a plan's scope (its plan tags) decides which programs it covers.
- **Waivers are needed everywhere:** member booking, waitlist promotion, staff booking and check-in all need every waiver the class requires. That means the waivers for every class plus any attached to its program.
  - **Staff overrides:** staff can add or check in someone who hasn't signed by giving a reason, which is recorded.
  - **Waitlist:** someone whose waiver has lapsed keeps their waitlist place, and moves in when they sign if a seat is free.
- **Staff bookings:** they skip the booking window.
- **Rooms and apparatus** (optional; a studio without rooms books as before):
  - A class can't be bigger than its room's capacity, or use more apparatus than the room has, or put more people on an item than the rig allows.
  - When a class uses apparatus, every booking takes a spot on one, numbered ("Silks 3", the lowest free). A booking is confirmed only while the class has room *and* that apparatus has a spot. "No preference" takes the apparatus with the most spots free.
  - Waitlist entries keep the apparatus they asked for, and move in only when a spot on it opens. Entries with no preference take any free spot.
  - Shrinking a room's apparatus cuts back the classes using it. Removing apparatus takes it off every class; people booked on it keep their places.
- **Online and hybrid classes:**
  - An online class's capacity is its online spots. A class in the studio and online keeps its capacity for the studio, and has its own online spots (empty for no limit). Online spots have no apparatus and no waitlist; cancelling one doesn't move anyone in from the studio's waitlist.
  - The meeting link is shown only to people booked online, from 15 minutes before class until it ends. Joining checks them in (it counts as attendance, as at the door). Kiosks and tags don't check people in to online spots.
  - Anyone with the link can pass it on, so studios should use their meeting service's passcode or waiting room.
- **Waitlist limit:** per session (a program sets it for new sessions). Empty means no limit; 0 means no waitlist. When it's reached, members see the class as full, and staff are told to raise the capacity or the limit.

## Emails

See [Architecture](Architecture#events-and-email) for the full list. Every studio email is branded with the studio's name and color, and replies go to the studio's contact address.
