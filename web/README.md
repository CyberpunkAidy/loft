# Loft — web application

Loft Studio as a hosted, multi-studio web app: any studio signs up, gets its own schedule at
`https://<your-domain>/<studio>`, connects its own Stripe account, and runs classes, memberships,
family accounts, waivers and check-in from a browser. It's a port of the WordPress plugin in
[`plugin/`](../plugin/README.md), whose releases are paused while the web app is built. The booking, billing and
waiver rules are the same, rebuilt on Laravel and Postgres for many studios at once.

- **Stack:** PHP 8.3+, Laravel 13, PostgreSQL 16 (SQLite works for local development), server-rendered Blade.
  There is no JavaScript build step. `public/css/app.css` and `public/js/app.js` are hand-written, and every page works without JS.
- **Payments:** Stripe Connect. The platform has one Stripe account, each studio connects its own, and money goes straight to the studio.
- **Domain:** nothing is hard-coded. The platform lives at whatever `APP_URL` says. Production is **https://loftstudio.app** (Render).

## Documentation

This README is the quick reference. The [project wiki](https://github.com/CyberpunkAidy/loft-studio/wiki) has the rest. Its source is in [`docs/wiki/`](../docs/wiki), and the **Wiki** workflow publishes it on every merge.

| Page | Covers |
|---|---|
| [Architecture](https://github.com/CyberpunkAidy/loft-studio/wiki/Architecture) | Code layout, tenancy and roles, data model, services, events |
| [Features](https://github.com/CyberpunkAidy/loft-studio/wiki/Features) | What members, staff and owners can do; booking rules and their defaults |
| [Deployment](https://github.com/CyberpunkAidy/loft-studio/wiki/Deployment) | Render services, environment variables, DNS, releasing and rolling back |
| [Stripe](https://github.com/CyberpunkAidy/loft-studio/wiki/Stripe) | Connect setup checklist, API versions, webhook events, troubleshooting |
| [Email](https://github.com/CyberpunkAidy/loft-studio/wiki/Email) | Resend, templates, deliverability |
| [Payments](https://github.com/CyberpunkAidy/loft-studio/wiki/Payments) | Online, delayed (bank) and in-person payments |
| [Accounts and Data](https://github.com/CyberpunkAidy/loft-studio/wiki/Accounts-and-Data) | Deleting accounts, closing studios, where personal data lives |
| [Design System](https://github.com/CyberpunkAidy/loft-studio/wiki/Design-System) | Logo, colors, fonts, icons, components |
| [Development](https://github.com/CyberpunkAidy/loft-studio/wiki/Development) | Local setup, tests, CI, making a change |
| [Decisions](https://github.com/CyberpunkAidy/loft-studio/wiki/Decisions) | Why things are built this way; open questions |
| [Roadmap](https://github.com/CyberpunkAidy/loft-studio/wiki/Roadmap) | Built: waivers, rooms and apparatus capacity, the event backbone, automations, the orders ledger, merchandise, the point of sale with card readers, and the online shop. Next: mobile API, check-in kiosks, churn |
| [Changelog](https://github.com/CyberpunkAidy/loft-studio/wiki/Changelog) | Every pull request so far |

When behavior changes, update this README and `docs/wiki/` in the same pull request.

## Run it locally

```bash
composer setup        # install, .env, app key, SQLite database, migrations + demo studio
php artisan dev       # web server + queue worker + logs
```

Open http://localhost:8000/skyline. The demo studio has these accounts (all with password `password`):

| Email | Role |
|---|---|
| owner@example.com | Studio owner: everything under `/skyline/admin` |
| coach@example.com | Instructor: check-in only |
| parent@example.com | Member with two children and a membership |
| adult@example.com | Member with a class pack |

Reset the demo with `php artisan migrate:fresh --seed`. Emails go to `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## How it's put together

### URLs

| | |
|---|---|
| `/` | Marketing page, `/signup` (start a studio), `/login`, `/studios` (my studios) |
| `/{studio}` | Public schedule; `/classes/{id}`, `/plans`, `/shop` (and its cart; checkout for members), `/join`, `/login` |
| `/{studio}/account…` | Members: upcoming classes, family, plans & billing, waivers, history, profile, deleting the account |
| `/{studio}/book/{session}` | Booking: choose who's coming (and the apparatus, if the class uses several) → waiver → membership/credit check → checkout if needed |
| `/{studio}/admin…` | Staff: dashboard, check-in, sessions & rosters, programs, seasons, rooms, members, automations, plans, point of sale, products, sales, payments and card readers, waivers, staff, settings |
| `/stripe/webhook` | Stripe Connect webhook (all studios) |

### Look and feel

- **One stylesheet, no build step:** `public/css/app.css`. It's built on CSS custom properties (colors, radii, shadows) and keeps the same class names the views use.
- **Studio colors:** studio pages take the studio's brand color as `--accent`. `App\Support\Color` works out a readable label color for buttons (white or near-black) and a darkened version for links when the brand color is too light to read on white (4.5:1).
- **Logo:** `public/images/loft-logo.svg` (dark text), `loft-logo-light.svg` (white text) and `loft-mark.svg`. Pages render it with `<x-logo />`, where the wordmark takes the text color. Favicons are `public/favicon.svg`, `favicon.ico` and `apple-touch-icon.png`. Each studio gets a favicon of its initial in its own color.
- **Fonts:** Inter (text) and Bricolage Grotesque (headings), self-hosted in `public/fonts` under the SIL Open Font License. No requests go to font CDNs.
- **Emails:** every HTML email uses `resources/views/mail/layout.blade.php`, styled like the site. It's built from tables with inline styles so it renders the same in Gmail, Outlook and Apple Mail, and every email also has a plain-text version.
  - **Studio emails** show the studio's badge and color, with "Powered by Loft" at the bottom.
  - **Password resets and staff invitations** use a Loft-branded email (`mail/password`) instead of Laravel's default.
  - **Logo:** emails show `public/images/loft-logo-email.png`, because email apps don't display SVG.
  - **Fonts:** the web fonts load where the email app allows it, such as Apple Mail. For that, Apache sends `Access-Control-Allow-Origin: *` for `.woff2` files. Other apps use the system font.
- **Icons:** a subset of [Lucide](https://lucide.dev) (ISC license) in `resources/icons`, rendered inline with `<x-icon name="calendar-days" />`.

### Tenancy

- A **studio** is a tenant, and every row it owns carries `studio_id`.
- Routes resolve children through the studio (`Route::scopeBindings()` plus an explicit `{studio}` binding in `AppServiceProvider`). So `/a/admin/programs/{id}` can't load studio B's program; it returns 404.
- **Waivers** (`App\Services\Waivers`):
  - **Who signs what:** several can be in force at once. Each applies to every class or only to the programs it's attached to, such as an aerial liability waiver, and can set its own validity.
  - **Versions:** every published version's text is kept (`waiver_versions`). Each signature records the version, a SHA-256 hash of the exact text, the attendee's and signer's names, and how they're related. Once anyone has signed, changing the text publishes a new version, and everyone signs again.
  - **Where it's enforced:** at member booking, waitlist promotion (someone whose waiver lapsed keeps their place until they sign), staff booking and check-in.
  - **Overrides:** staff can let someone in without a signature by giving a reason, and the reason is recorded (`waiver_overrides`).
  - **Download:** admins can download every signature, the text of each version and the drawn signatures as a ZIP (Admin → Waivers), using `App\Services\WaiverDownload`.
- **Rooms and apparatus** (`App\Services\Bookings`, optional per studio):
  - **Rooms** have a capacity limit (`rooms.max_capacity`); a class can't be bigger than its room.
  - **Apparatus** is rigged in a room: how many, and the most people on each. A class uses some of it (`program_apparatus` is the default for new sessions; `session_apparatus` is what each session uses).
  - **Booking:** each booking takes a numbered spot on one apparatus ("Silks 3"). It's confirmed only while the class has room and the apparatus has a spot; otherwise it joins the waitlist, which can be capped (`class_sessions.waitlist_capacity`). Waitlist entries wait for the apparatus they asked for.
  - **Clashes:** classes sharing a room at the same time are flagged to staff (`App\Services\Rooms`), not blocked.
- **Events and email** (`App\Services\DomainEvents`, `App\Services\Messages`):
  - **Outbox:** every change worth remembering records a row in `domain_events` in the same transaction (bookings, check-ins with a first-visit flag, plans, payment failures, waivers, consent). The `RelayDomainEvents` job hands them to the handlers in `config('loft.event_handlers')`. It's also the member history shown to staff.
  - **Email log:** every studio email is logged in `message_deliveries` under a dedupe key, so it sends once; it's marked sent or failed.
  - **Consent:** marketing email needs opt-in per studio (join form or profile), with a signed one-click unsubscribe link.
  - **Snapshots:** housekeeping saves each member's activity nightly (`member_snapshots`), for churn scoring later.
  - **Automations** (`App\Services\Automations`, Admin → Automations): after a first class (from the outbox), birthdays and we-miss-you emails (from housekeeping). Marketing email: only to members who opted in, once each.
- **Orders ledger** (`App\Services\Orders`, Admin → Sales): every sale is an order with items: each paid one-time checkout, each paid membership invoice, each payment taken in person. Numbered per studio, recorded once each, with Stripe's actual amounts; CSV download per month.
- **Point of sale** (`App\Services\PointOfSale`, Admin → Point of sale and Products): products with variants and stock, and plans, in one cart for a member or a walk-in; sales tax on taxable products; paid in person or comped. Stock never goes below zero; voiding restocks and cancels plans.
- **Card readers** (`App\Services\CardReaders`, `App\Services\ReaderPayments`, Payments → Card readers): Stripe Terminal readers paired on the studio's account; the point of sale charges the cart on one, waits for the card on a page that refreshes itself, and records the sale when it's paid. Refunds go back to the card. Housekeeping settles sales left open.
- **Online shop** (`App\Services\Shop`, `/{studio}/shop`): the same products, paid through Stripe Checkout with the studio's sales tax; stock held for 30 minutes while the member pays; picked up at the front desk (Admin → Sales → To pick up). Housekeeping settles checkouts whose webhook never came.
- **Users are global.** One login can be a member of one studio and staff at another. The role in each studio lives in `studio_users` (`member < instructor < admin < owner`) and is enforced by the `studio.role:` middleware. `tests/Feature/TenancyTest.php` covers both.
- **Deleting an account** (Profile → Delete account) removes the user at every studio, with their family members, bookings and passes (`App\Services\AccountDeletion`). Signed waivers stay with each studio, unlinked from the account. Also:
  - Renewing memberships are cancelled in Stripe first, so the person isn't charged again. If Stripe can't be reached, nothing is deleted.
  - Seats they held in upcoming classes go to the waitlist.
  - Classes they taught stay, without an instructor.
  - Payment history stays in each studio's Stripe account. Payments the studio recorded in person stay in its records, no longer linked to the person.
  - A studio's only owner chooses what happens to it in the same form. They can hand it to one of its staff, who becomes the owner. Or they can close it: members' renewing memberships there are cancelled in Stripe, then the studio and everything in it is deleted. Members keep their accounts and other studios.
  - Owners can also make another staff member an owner at any time (Admin → Staff → Make owner). Only owners can do that.

### Where the plugin's code went

| Plugin | Web app |
|---|---|
| `Services\Booking` | `app/Services/Bookings.php` (book, cancel, waitlist promotion, check-in, cancel a class) |
| `Repos\Entitlements`, `Repos\Plans` scope rules | `app/Services/Entitlements.php` |
| `Repos\Sessions::generate_for_program` | `app/Services/SessionGenerator.php` |
| `Repos\Waivers` | `app/Services/Waivers.php` |
| `Stripe\Client`, `Stripe\Webhooks` | `app/Services/Stripe/StripeClient.php`, `StripeWebhooks.php` |
| `Cron` | `php artisan loft:housekeeping` (hourly, `routes/console.php`) |
| `Emails` + `do_action` hooks | Events in `app/Events`, emails in `app/Listeners/StudioEmails.php` |
| Shortcodes + `assets/app.js` | Blade views in `resources/views/studio` |
| wp-admin pages, CPT meta boxes | `app/Http/Controllers/Admin`, `resources/views/admin` |
| `Settings` option | `studios` columns + `studios.settings` JSON (defaults in `Studio::DEFAULT_SETTINGS`) |

The data model is the plugin's (`plugin/ARCHITECTURE.md`), with `studio_id` added. Some names changed:
- `sessions` became `class_sessions`, because Laravel uses `sessions` for logins.
- Programs and waivers are tables instead of WordPress posts.
- The three taxonomies are `categories` (`age_group`, `level`, `plan_tag`).

### Changes from the plugin's behavior

These are fixed here; the plugin still has them.

- **Seasonal tuition checkout:** the plugin sent `subscription_data[cancel_at]`, which Checkout doesn't accept, so Stripe rejected the request. The app sets `cancel_at` on the subscription after checkout completes.
- **Racing bookings:** booking and waitlist promotion lock the class row, so two people can't take the last seat. If another booking uses the last credit at the same moment, the app looks for another entitlement instead of booking for free.
- **Cross-scope checkout:** a booking finished after checkout re-checks that the plan bought covers that class. Before, buying any plan with a `session_id` could book a class outside the plan's scope.
- **Duplicate memberships:** if `invoice.paid` arrived before `checkout.session.completed`, the plugin created two memberships for one purchase. Now it creates one.
- **Webhook retries:** failed webhooks answer 500 so Stripe retries them. The plugin answered 200 and dropped them.
- **Waitlist re-checks:** before promoting someone, the app re-checks memberships (lapsed, past due, over the weekly limit) as well as credits.
- **Credit order:** packs that never expire are used last. Before, they were used first.
- **Season end time:** a season's end is midnight in the studio's timezone. Before, it was midnight UTC.
- **Comped memberships:** a comped membership expires at its end date.
- **Repeat failure emails:** "payment failed" sends once per lapse, not once per Stripe retry.
- **"Members only" (no drop-in):** this option is enforced at booking. The plugin only used it to hide the drop-in price.
- **Waitlist emails:** they no longer call every studio "in the air".

## Payments (Stripe Connect)

1. In the platform's Stripe account, enable **Connect** (Settings → Connect). The app creates studio accounts with Stripe's Accounts v2 API, set up like Standard accounts: each studio has the full Stripe Dashboard, pays Stripe's fees itself, and Stripe covers losses. A new account is in the platform's country.
2. Set `STRIPE_SECRET` to the platform's secret key (`sk_…`, not the publishable `pk_…` key).
3. Under **Developers → Webhooks**, add an endpoint for **events on connected accounts**:
   - URL: `https://<your-domain>/stripe/webhook`
   - API version: any; the latest is fine. The app takes only the object's ID from each webhook and fetches the object from the API in the version it pins (`2024-06-20`).
   - Payload style: snapshot. These are v1 events; Stripe sends them for Accounts v2 accounts too.
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`, `account.updated`, `account.application.deauthorized`.

   Put its signing secret in `STRIPE_WEBHOOK_SECRET`.
4. Each studio connects from **Admin → Payments**. The app creates the studio's Customer Portal configuration on first use.
5. Optional: `LOFT_PLATFORM_FEE_PERCENT` takes an application fee on every studio payment.

**Payment methods.** Each studio picks which payment methods checkout offers (cards, Apple Pay, Google Pay, bank debit and so on) in its own Stripe Dashboard, under Settings → Payment methods. The app doesn't restrict them.

Some methods take days to clear, bank debit (ACH) for example. For one-time purchases (packs and drop-ins):
- The member sees "your payment is processing" when they finish checkout.
- `checkout.session.async_payment_succeeded` grants the plan, and finishes the booking if they were booking a class.
- If the payment fails, `checkout.session.async_payment_failed` emails the member.

Memberships report the same thing through `invoice.paid` and `invoice.payment_failed`.

**Payments taken in person.** On a member's admin page, **Add a plan** records a payment taken at the front desk and gives the member that plan. The payment can be cash, check, a card terminal, a bank transfer, a payment app or other. The same form can also give a plan at no charge (a comp).
- **What's saved:** each record (`payments` table, `App\Services\OfflinePayments`) keeps the amount, the date, a note and who recorded it.
- **Receipt:** the member gets the usual purchase email with the amount and how they paid.
- **How long it lasts:**
  - A membership paid in person runs one month, unless you set a "valid until" date.
  - Packs follow their validity period.
  - Comps don't end, as before.
- **Mistakes:** admins can void a record. The plan it granted is cancelled and the record stays, marked void.
- **Totals:** Admin → Payments lists every record and the total taken in person this month.

To test locally, forward webhooks from connected accounts with:

```bash
stripe listen --forward-connect-to localhost:8000/stripe/webhook
```

Use card `4242 4242 4242 4242`. Test-mode and live-mode objects don't mix, so studios reconnect when you switch to live keys at launch.

## Deploy on Render

`render.yaml` in the repository root is a Render Blueprint. It creates:

| Render resource | What it runs |
|---|---|
| `loft-web` (web service) | Apache serving the app. Migrations run before each deploy, and `/up` is the health check. A 1 GB disk keeps waiver signature drawings. |
| `loft-worker` (background worker) | `queue:work`, which sends the queued emails |
| `loft-housekeeping` (cron job, hourly) | `loft:housekeeping`: class reminders, credit-expiry warnings, expiring credits, shop checkouts and card reader sales to settle |
| `loft-db` (Postgres 16) | The database |

All four are paid Render plans. Workers, cron jobs, disks and pre-deploy commands aren't available on Render's free tier.

1. In Render, choose **New → Blueprint**, connect this repository, and pick the branch to deploy.
2. Render asks for `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET`. Leave them blank to start, and add them later in `loft-web`'s environment. See [Payments](#payments-stripe-connect).
3. Deploy. The first build takes a few minutes. The site answers at `https://loft-web.onrender.com` straight away; open `/signup` there to create your studio.
4. **Domain: loftstudio.app.** `render.yaml` adds `loftstudio.app` to `loft-web`, and Render adds `www.loftstudio.app` and redirects it to the bare domain. At your DNS provider:
   - `loftstudio.app`: an `ALIAS` or `ANAME` record to `loft-web.onrender.com`. If your provider has neither, use an `A` record with `216.24.57.1`.
   - `www.loftstudio.app`: a `CNAME` record to `loft-web.onrender.com`.
   - Remove any `AAAA` records for these names.

   Render issues the HTTPS certificates once the records resolve; check `loft-web` → Settings → Custom Domains. `APP_URL` is already `https://loftstudio.app`.
5. **Mail, with Resend.** No email is sent until you turn it on; until then emails are only written to the log. The sender is preset to `hello@mail.loftstudio.app` (`MAIL_FROM_ADDRESS` in `render.yaml`).
   1. In [Resend](https://resend.com), add the domain `mail.loftstudio.app` and add the DNS records Resend shows at your DNS provider. Wait until Resend says it's verified. Using a subdomain keeps this email's reputation separate from any other mail on `loftstudio.app`.
   2. Create a Resend API key with sending access.
   3. In Render, open the **loft-shared** environment group and add:
      - `RESEND_API_KEY`: the key
      - `MAIL_MAILER`: `resend`
   4. Save. Render redeploys `loft-web`, `loft-worker` and `loft-housekeeping`.

   Studio emails use the studio's name as the sender name and its contact address as Reply-To. The key and `MAIL_MAILER` live only in the dashboard, not in `render.yaml`, so Blueprint syncs leave them alone.
6. **Stripe.** Point the Connect webhook at `https://loftstudio.app/stripe/webhook`.

How the image works:
- One image (`web/Dockerfile`, PHP 8.3 + Apache) serves every role. `web/docker/start.sh` picks the role: `web`, `worker`, `housekeeping` or `migrate`.
- `APP_KEY` is generated by Render. The start script adds the `base64:` prefix Laravel expects.
- `TRUSTED_PROXIES=*` makes the app trust Render's load balancer, so links are `https://`.
- The disk prevents zero-downtime deploys and scaling `loft-web` past one instance. To lift that, move signatures to S3-compatible storage.

Try the image locally:

```bash
docker build -t loft web
```

## Production elsewhere

- **Database:** Postgres (`DB_CONNECTION=pgsql`). Run `php artisan migrate --force` on deploy.
- **Queue:** emails are queued, so keep a worker running: `php artisan queue:work`.
- **Scheduler:** `* * * * * php artisan schedule:run` drives the hourly `loft:housekeeping` (reminders, expiry warnings, expiring credits).
- **Mail:** Resend is installed: set `MAIL_MAILER=resend` and `RESEND_API_KEY`. Laravel's SMTP, Postmark and SES mailers work too. `MAIL_FROM_ADDRESS` must be on a domain you've verified with the provider. Studio emails use the studio's name as the sender name and its contact address as Reply-To.
- **Caching:** `php artisan optimize` caches config, routes and views. Every route is named, so route caching works.
- **Storage:** waiver signature drawings are stored on the private `local` disk (`storage/app/private/signatures`). Back it up, or point the disk at S3.
- **HTTPS behind a load balancer:** set `TRUSTED_PROXIES=*`, or a list of the proxy's IPs. Leave it unset when nothing sits in front of the app.
- **Hosting:** the Docker image runs on Fly.io, Railway or any Docker host. Laravel Cloud and Forge work too.

## Tests

```bash
php artisan test
```

There are 147 tests. They cover:
- the booking rules
- rooms and apparatus: spots, item numbers, waitlist limits and promotion, and the admin screens
- the event backbone: events recorded with their changes, the relay and retries, the email log, consent and unsubscribing, nightly snapshots
- automations: first-class follow-ups through the outbox, birthdays (leap days too), win-back once per absence, and the admin screens
- the orders ledger: checkouts, membership invoices and renewals, in-person payments and voids, numbering, the Sales page and CSV
- merchandise and the point of sale: products and variants, stock that never goes negative, sales tax, walk-ins and comps, voids that restock
- the online shop: the cart, stock held while paying and released when not, Stripe Checkout with tax, paid orders numbered with a pickup receipt, bank payments that fail, the hourly sweep, pickups
- card readers: pairing and syncing with Stripe, charging the till's cart, declines and retries, cancelling (and paying at the same moment), offline and busy readers, refunds, the hourly sweep
- daylight-saving-safe session generation
- the Stripe client and webhooks, against faked HTTP
- tenancy and roles
- deleting an account
- proxy trust
- sending through Resend
- every member and admin page
- readable studio colors, the logo and the email designs
- payments recorded in person, and delayed (bank) payments
- waiver rules: program waivers, versions, enforcement, overrides and the download
- the demo seeder

They run on SQLite by default. To run them against Postgres:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=loft_test DB_USERNAME=postgres php vendor/bin/phpunit
```

CI (`.github/workflows/web.yml`) runs both, and builds and starts the Docker image.

## Not built yet

- **Studio subdomains and custom domains.** URLs are path-based today; a host-based resolver can go in front of the same routes.
- **Billing studios for the platform itself.** A subscription per studio, or the existing application fee.
- **Importing an existing WordPress plugin install.** Its tables map almost one-to-one.
- **A JSON API** for the planned mobile app (React Native with Expo). See the wiki's [Roadmap](https://github.com/CyberpunkAidy/loft-studio/wiki/Roadmap).
- **SMS reminders.** Listen to the same events the emails do.
- **Email verification, and a platform-operator admin.**
