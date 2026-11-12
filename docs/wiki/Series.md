# Series

A series is a run of one program's classes sold as one ([decision 47](Decisions)): an 8-week Silks Level 1, a 6-week intro to pole, a winter term. Members pay once and are booked into every class, with their spot held each week.

## Setting one up

**Admin → Series → New series** (or **New series** on a program's page):

| Field | Meaning |
|---|---|
| Program | A weekly program. Its classes on the dates below make up the series |
| Name | What members see, such as "Silks Level 1 · Winter" |
| First day, last day | The program's classes on these dates are the series' classes |
| Price | For the whole series, paid in full when signing up. 0 makes it free |
| People can join until class… | 1: only before it starts. 2: they can still join after missing the first class, and so on |
| Make-up classes | Cancelling a class in time gives a make-up credit (on by default) |
| Let others book open spots | Drop-ins with a membership, credit or drop-in (off by default) |
| Sign-ups are open | Untick to stop new sign-ups; staff can still add people |

- **One series per class:** two series of the same program can't overlap, since a class can only be in one.
- **Classes added later count:** classes the program gains on those dates, for example after its schedule changes, join the series when the program is saved.
- **Spots:** the fewest open spots in any class still to come, apparatus included, less anyone paying right now.

## Signing up

| Who | How |
|---|---|
| Members, on the website | The schedule lists **Series open for sign-up**, and a series class shows **Part of a series** instead of **Book**. The series page has the classes, the price and who's signing up; then Stripe Checkout |
| Members, in the app | The class screen links to the series; sign-up opens Stripe Checkout in the browser, as plans do |
| Staff | The series page: **Add someone**, paid at the desk (cash, card, check…) or free, with an optional note |

- **Same steps as booking:** the studio's injury and photo questions, then the program's waivers, then payment. The age limits apply to the first and last class.
- **Holding the spot:** an online sign-up holds the person's spot for 30 minutes while they pay. Coming back without paying, or letting Checkout expire, lets it go. The hourly housekeeping settles any Checkout the webhook missed.
- **Joining late:** until the class the studio set starts, for the classes left, at that share of the price. 7 of 8 classes costs 7/8 of it.
- **When it's paid:** they're booked into every class still to come, on the same apparatus each week where it has room. One email confirms it, instead of one per class. A class they'd already booked keeps that booking.
- **Free series:** signing up books them at once, without Checkout or an order.

## Missing a class

- **Make-ups:** cancelling a series class before the late-cancel window gives one make-up credit. It's good for any class, including another class in a series that takes no drop-ins, until two weeks after the series ends. Members see it under Plans & billing as "Make-up class (Silks Level 1)". A late cancel earns nothing.
- **Drop-ins:** with drop-ins off (the default), only the people in the series, and make-ups, can book its classes. Staff can still add anyone to a class.

## Taking someone out

- **Take out**, on the series page: their classes still to come are cancelled, without an email each, unused make-up credits from it end, and they get one email. Money back is a refund, separately.
- **Refunding or voiding the sale** does the same: a full refund (from Loft or the Stripe Dashboard) or a voided desk sale takes them out of the series.

## What staff see

- **Series list:** dates, classes, price, who's signed up, spots left, and whether sign-ups are open.
- **A series:** who's in it (with their photo answers), what they paid and how, the classes with their bookings, and **Add someone**.
- **Rosters:** series bookings show "Series" where other bookings show their plan.
- **Member page, Activity:** "Signed up for Silks Level 1 (8 classes)", "Taken out of Silks Level 1", and make-up credits.

## How it's stored

| Table | What |
|---|---|
| `series` | The run: program, name, dates, price, `join_until_class`, `makeups`, `allow_dropins`, `signups_open` |
| `series_enrollments` | One person in one series: `pending` (holding a spot while paying), `active`, `withdrawn` or `released` (the payment never came), with the classes and price |
| `class_sessions.series_id` | A class's series |
| `bookings.series_enrollment_id` | Bookings a series made (source `series`, no plan used) |
| `order_items.series_enrollment_id` | The sale (kind `series`), online or at the desk |
| `entitlements.series_enrollment_id` | Make-up credits |

Events: `series.enrolled`, `series.withdrawn`, and `entitlement.granted` with `source` `makeup`.

## In the API

- `GET /studios/{slug}/series` and `/series/{id}` (the classes, and with a token, who on the account is in it).
- `POST /studios/{slug}/series/{id}/signups` with `attendee_id`, which returns `checkout_url`, `checkout_id` and `return_url`, or `signed_up` for a free series. Check `GET …/checkouts/{checkout_id}` (`kind` `series`, `fulfilled`) when the browser closes.
- Classes carry `series` (`id`, `name`, `drop_ins`). Booking a series-only class answers `422 series_only` with `series_id`.
- Signing up can answer `questions_required`, `waiver_required`, `already_enrolled` (409), `full` or `series_closed`.

See [API](API).

## Where it lives

| What | Where |
|---|---|
| Rules: quotes, holding spots, Checkout, booking every class, taking out | `App\Services\SeriesSignups` |
| Series-only classes and make-ups | `Bookings::book()` and `cancel()`, `Entitlements::makeupFor()` and `grantMakeup()` |
| Models | `Series`, `SeriesEnrollment` |
| Staff pages | `Admin\SeriesController`, `admin/series/*` |
| Member pages | `Studio\SeriesController`, `studio/series.blade.php`, `studio/_series-card.blade.php` |
| App | `app/studio/[slug]/series/[id].tsx`, `Api\V1\SeriesController`, `SeriesResource` |
| Tests | `tests/Feature/SeriesTest.php` |
