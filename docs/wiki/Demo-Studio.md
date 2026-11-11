# Demo studio

"Try the demo" gives each visitor a private copy of a sample studio for a day, signed in as its owner ([decision 44](Decisions)). It's at [loftstudio.app/demo](https://loftstudio.app/demo), linked from the home page. That link is also the one to send studio owners who want to look before they sign up.

## What the visitor gets

- **Skyline Aerial Arts,** an aerial and pole studio with a lived-in month:
  - rooms with silks, lyra and poles;
  - 14 classes and a workshop;
  - plans, a waiver, products and automations;
  - 63 members, some with children, most on a plan;
  - the coming week booked up, with waitlists;
  - six weeks of attendance (so a few members show as at risk of leaving);
  - this month's sales.
- **Signed in as the owner,** Olivia Owens, with every admin page to click through and change.
- **"See it as a member":** the banner switches to Priya Shah, a parent with two children and a membership, to book a class, sign a waiver or see a check-in code. "Back to the owner" switches back. Only the browser that started the demo can switch, because it holds the `loft_demo` cookie naming the demo and its two people.
- **"Start your own studio":** signs out of the demo and opens a blank signup form.

A banner on every page of a demo says what it is and when it's deleted. The pages carry `noindex`.

## What a demo can't do

| What | How |
|---|---|
| Send email | `Messages` logs every email from a demo studio as suppressed and sends nothing. Staff invitations skip the password-set email, and password resets for demo accounts send nothing. |
| Take or refund payments, or open Stripe accounts | The `demo.off` middleware sends these back with a note: connecting Stripe, card readers, member checkout, the shop checkout and refunds. The Payments page explains instead. |
| Subscribe to Loft | Demos are comped. The Subscription page says what it would show in a real studio, and the referral card is hidden. |
| Upload video to Mux | `demo.off:video` on uploads. |

Every account made inside a demo is marked with it and deleted with it (`users.demo_studio_id` and `demo_expires_at`, via `User::madeIn()`): the sample people, staff added there, imported members, and anyone joining its public page. People who already had a Loft account and are added to a demo keep their account. The sample people's addresses end in `.example`, a domain reserved so that nothing can be delivered there.

## Spares, so it starts instantly

Building a demo takes a few seconds (about 6,000 queries). So `LOFT_DEMO_POOL` (default 2) spares are built ahead:
- "Try the demo" claims one, gives it a fresh day, and queues `RefillDemoPool` on the worker to build the next.
- A spare nobody takes is deleted after 12 hours, so its week of bookings stays current.
- If none is ready, the demo is built on the spot while the button says "Setting up your studio…".

## Limits and cleanup

- **Starting demos:** at most 6 an hour from one IP address (the `demo` rate limiter), and no more than `LOFT_DEMO_MAX_LIVE` (default 100) at once.
- **Hourly housekeeping:**
  - deletes demos (and spares) whose time is up, with everything in them, every account made in them, and their sessions;
  - then builds spares back up to the pool size.
- **How long a demo lasts:** `LOFT_DEMO_HOURS`, 24 by default.

## Counting

`demo_runs` keeps one row per demo started. It has nothing about the visitor, only whether that browser went on to start a real studio. The real studio's `signup_source` gets `tried_demo`.

`php artisan loft:signups` shows "Demos started: N, and M of those browsers went on to start a studio", and marks studios that tried the demo first. Demo studios themselves never appear in the report.

## The same sample studio locally

`App\Services\Demo\SampleStudio` builds both the demo and the local seed:
- `php artisan migrate:fresh --seed` gives the plain studio, with the sign-ins in [Development](Development).
- `SEED_BUSY=1 php artisan migrate:fresh --seed` gives the lived-in month a demo has.

Change the sample studio there, and both follow.

## Where it lives

| What | Where |
|---|---|
| Pages and switching | `DemoController` (`/demo`, `/demo/switch`, `/demo/leave`), `platform/demo.blade.php`, `components/demo-banner.blade.php` |
| Starting, spares and cleanup | `App\Services\Demo\DemoStudios`, `App\Jobs\RefillDemoPool`, `loft:housekeeping` |
| The sample studio | `App\Services\Demo\SampleStudio` (also `DatabaseSeeder`) |
| Switched-off actions | `App\Http\Middleware\NotInDemo` (`demo.off`) |
| Settings | `config/loft.php` → `demo` |
| Tests | `tests/Feature/DemoStudiosTest.php` |
