# Loft — web application

Loft Studio as a hosted, multi-studio web app: any studio signs up, gets its own schedule at
`https://<your-domain>/<studio>`, connects its own Stripe account, and runs classes, memberships,
family accounts, waivers and check-in from a browser. It's a port of the WordPress plugin in the
repository root. The booking, billing and waiver rules are the same, rebuilt on Laravel and Postgres
for many studios at once.

- **Stack:** PHP 8.3+, Laravel 13, PostgreSQL 16 (SQLite works for local development), server-rendered Blade.
  There is no JavaScript build step. `public/css/app.css` and `public/js/app.js` are hand-written, and every page works without JS.
- **Payments:** Stripe Connect. The platform has one Stripe account, each studio connects its own, and money goes straight to the studio.
- **Domain:** nothing is hard-coded. The platform lives at whatever `APP_URL` says.

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
| `/{studio}` | Public schedule; `/classes/{id}`, `/plans`, `/join`, `/login` |
| `/{studio}/account…` | Members: upcoming classes, family, plans & billing, waivers, history, profile, deleting the account |
| `/{studio}/book/{session}` | Booking: choose who's coming → waiver → membership/credit check → checkout if needed |
| `/{studio}/admin…` | Staff: dashboard, check-in, sessions & rosters, programs, seasons, members, plans, payments, waivers, staff, settings |
| `/stripe/webhook` | Stripe Connect webhook (all studios) |

### Tenancy

- A **studio** is a tenant, and every row it owns carries `studio_id`.
- Routes resolve children through the studio (`Route::scopeBindings()` plus an explicit `{studio}` binding in `AppServiceProvider`). So `/a/admin/programs/{id}` can't load studio B's program; it returns 404.
- **Users are global.** One login can be a member of one studio and staff at another. The role in each studio lives in `studio_users` (`member < instructor < admin < owner`) and is enforced by the `studio.role:` middleware. `tests/Feature/TenancyTest.php` covers both.
- **Deleting an account** (Profile → Delete account) removes the user at every studio, with their family members, bookings, passes and signed waivers (`App\Services\AccountDeletion`):
  - Renewing memberships are cancelled in Stripe first, so the person isn't charged again. If Stripe can't be reached, nothing is deleted.
  - Seats they held in upcoming classes go to the waitlist.
  - Classes they taught stay, without an instructor.
  - Payment history stays in each studio's Stripe account.
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

The data model is the plugin's (`ARCHITECTURE.md` in the repo root), with `studio_id` added. Some names changed:
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
   - Events: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`, `account.updated`, `account.application.deauthorized`.

   Put its signing secret in `STRIPE_WEBHOOK_SECRET`.
4. Each studio connects from **Admin → Payments**. The app creates the studio's Customer Portal configuration on first use.
5. Optional: `LOFT_PLATFORM_FEE_PERCENT` takes an application fee on every studio payment.

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
| `loft-housekeeping` (cron job, hourly) | `loft:housekeeping`: class reminders, credit-expiry warnings, expiring credits |
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

There are 87 tests. They cover:
- the booking rules
- daylight-saving-safe session generation
- the Stripe client and webhooks, against faked HTTP
- tenancy and roles
- deleting an account
- proxy trust
- sending through Resend
- every member and admin page

They run on SQLite by default. To run them against Postgres:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=loft_test DB_USERNAME=postgres php vendor/bin/phpunit
```

CI (`.github/workflows/web.yml`) runs both, and builds and starts the Docker image.

## Not built yet

- **Studio subdomains and custom domains.** URLs are path-based today; a host-based resolver can go in front of the same routes.
- **Billing studios for the platform itself.** A subscription per studio, or the existing application fee.
- **Importing an existing WordPress plugin install.** Its tables map almost one-to-one.
- **A JSON API,** so the WordPress plugin or a mobile app can embed the schedule.
- **SMS reminders.** Listen to the same events the emails do.
- **Email verification, and a platform-operator admin.**
