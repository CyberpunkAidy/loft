# Features

## Platform (loftstudio.app)

- **Home page:** what Loft does, with a way to start a studio.
- **Start your studio** (`/signup`): creates the studio with its name, web address (slug), time zone and currency. The person who signs up becomes its owner.
- **Signing in:** one login for every studio someone belongs to. **My studios** (`/studios`) lists them, with links to each schedule, account and studio admin.
- **Password reset:** also used to invite new staff (see [Email](Email)).

## Members (`/{studio}`)

- **Schedule:** two weeks at a time (configurable), filtered by age group and level. Each class shows its time, instructor, tags, drop-in price and spots left.
- **Booking:** choose who's coming (yourself or family members), then:
  1. Each person needs a signed, current waiver.
  2. The booking needs a plan that covers the class: a membership within its weekly limit, or a credit.
  3. If there's none, the member picks a plan, pays by Stripe Checkout, and the booking completes automatically after payment.
- **Waitlists:** a full class takes a waitlist spot. When a seat frees up, the next person is booked automatically if their plan still covers it, and emailed.
- **Cancelling:**
  - From the account page, up to class start.
  - Inside the late-cancel window, the credit is kept by the studio, unless the studio's settings say otherwise.
- **My account tabs:**
  - **Upcoming** and **History:** classes booked and attended.
  - **Family:** add, edit or remove attendees; medical notes and emergency contacts.
  - **Plans & billing:** memberships and credits, a link to Stripe's billing portal (cards, invoices, cancelling), and buying more.
  - **Waivers:** every waiver in force, per attendee, and what each is for ("Only for Silks"). Signing, with an optional drawn signature, goes from one missing waiver to the next.
  - **Profile:** name, email, phone, password, and **deleting the account** ([Accounts and Data](Accounts-and-Data)).
- **Plans page:** every plan a studio sells, with checkout.

## Staff (`/{studio}/admin`)

| Area | What it does | Who |
|---|---|---|
| Dashboard | Members, active memberships, past-due, bookings in the last 7 days, waitlists; today's classes; a setup checklist | admin |
| Check-in | Today's classes (or any date), roster with waiver status and medical notes, mark attended or no-show | instructor+ |
| Sessions | Every class by date, rosters, capacity (raising it promotes the waitlist), instructor, notes, add someone (optionally comped), remove, cancel the class (emails everyone, returns credits) | admin |
| Programs | Weekly classes and one-off events; generate sessions across the season | admin |
| Seasons | Date ranges for weekly classes | admin |
| Members | Search; family, waivers, plans, upcoming and past classes; **Add a plan** (record a payment taken in person, or comp); void recorded payments | admin |
| Plans & pricing | Memberships, seasonal tuition, packs and drop-ins; sync each plan to Stripe | admin |
| Payments | Connect Stripe; payments recorded in person and this month's total | admin |
| Waivers | Several waivers, each for every class or only chosen classes, with its own validity. Text is versioned: once anyone has signed, changing it publishes a new version and everyone signs again. Turn waivers on or off. **Download signed waivers** as a ZIP | admin |
| Staff | Add instructors and admins (new people get an email to set a password); owners can make other owners | admin |
| Settings | Name, brand color, time zone, currency, email sender name and reply-to, booking policies | admin |

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

## Emails

See [Architecture](Architecture#events-and-email) for the full list. Every studio email is branded with the studio's name and color, and replies go to the studio's contact address.
