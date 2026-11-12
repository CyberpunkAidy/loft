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
- **"See it as a member":** the banner switches to Priya Shah, a parent with two children and a membership, to book a class, sign a waiver or see a check-in code. Her son Leo was booked before the studio asked its [injury and photo questions](Injury-and-Photo-Questions), so booking him asks them first. "Back to the owner" switches back. Only the browser that started the demo can switch, because it holds the `loft_demo` cookie naming the demo and its two people.
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

Building a demo takes about 5,300 queries, about 5 seconds locally but about half a minute on Render's small database. So `LOFT_DEMO_POOL` (default 2) spares are built ahead on the worker, and a visitor's request never builds one:

- **A spare ready:** "Try the demo" claims it and gives it a fresh day. `RefillDemoPool` is queued to build a replacement. Each run builds one spare and queues itself again while the pool is short, so a run stays well inside the queue's 90-second retry window.
- **No spare ready:** the visitor goes to `/demo/wait`, "Setting up your studio…". That page asks `/demo/status` every few seconds and opens the demo by itself as soon as the worker has one.
  - Each check also asks for a build, so one that stalled starts again; only one waits in the queue at a time.
  - After two minutes the page says it's taking longer than usual.
- **Retries:** the job tries 3 times. A worker still on the previous release can't run it (the class isn't there yet), and that happens right after a deploy.
- **Unclaimed spares:** deleted after 12 hours, so their week of bookings stays current.
- **Only so far ahead:** a demo's classes run eight weeks ahead, not a whole season, to keep the build short.

With `LOFT_DEMO_POOL=0` (tests, local), the demo is built in the request instead.

## Limits and cleanup

- **Starting demos:** at most 10 starts an hour from one IP address (the `demo` rate limiter; the waiting page's own start counts too), and no more than `LOFT_DEMO_MAX_LIVE` (default 100) at once.
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
| Pages and switching | `DemoController` (`/demo`, `/demo/wait`, `/demo/status`, `/demo/switch`, `/demo/leave`), `platform/demo.blade.php`, `platform/demo-wait.blade.php`, `components/demo-banner.blade.php` |
| Starting, spares and cleanup | `App\Services\Demo\DemoStudios`, `App\Jobs\RefillDemoPool`, `loft:housekeeping` |
| The sample studio | `App\Services\Demo\SampleStudio` (also `DatabaseSeeder`) |
| Switched-off actions | `App\Http\Middleware\NotInDemo` (`demo.off`) |
| Settings | `config/loft.php` → `demo` |
| Tests | `tests/Feature/DemoStudiosTest.php` |
