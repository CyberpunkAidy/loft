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
| `/{studio}/account…` | Members: upcoming classes, family, plans & billing, waivers, history, profile |
| `/{studio}/book/{session}` | Booking: choose who's coming → waiver → membership/credit check → checkout if needed |
| `/{studio}/admin…` | Staff: dashboard, check-in, sessions & rosters, programs, seasons, members, plans, payments, waivers, staff, settings |
| `/stripe/webhook` | Stripe Connect webhook (all studios) |

### Tenancy

- A **studio** is a tenant, and every row it owns carries `studio_id`.
- Routes resolve children through the studio (`Route::scopeBindings()` plus an explicit `{studio}` binding in `AppServiceProvider`). So `/a/admin/programs/{id}` can't load studio B's program; it returns 404.
- **Users are global.** One login can be a member of one studio and staff at another. The role in each studio lives in `studio_users` (`member < instructor < admin < owner`) and is enforced by the `studio.role:` middleware. `tests/Feature/TenancyTest.php` covers both.

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

1. In the platform's Stripe account, enable **Connect** (Settings → Connect). Standard accounts are used, so each studio has its own Stripe dashboard.
2. Set `STRIPE_SECRET` to the platform's secret key.
3. Under **Developers → Webhooks**, add an endpoint for **events on connected accounts**:
   - URL: `https://<your-domain>/stripe/webhook`
   - API version: `2024-06-20`, the version the client pins.
   - Events: `checkout.session.completed`, `invoice.paid`, `invoice.payment_failed`, `customer.subscription.updated`, `customer.subscription.deleted`, `account.updated`, `account.application.deauthorized`.

   Put its signing secret in `STRIPE_WEBHOOK_SECRET`.
4. Each studio connects from **Admin → Payments**. The app creates the studio's Customer Portal configuration on first use.
5. Optional: `LOFT_PLATFORM_FEE_PERCENT` takes an application fee on every studio payment.

To test locally, forward webhooks from connected accounts with:

```bash
stripe listen --forward-connect-to localhost:8000/stripe/webhook
```

Use card `4242 4242 4242 4242`. Test-mode and live-mode objects don't mix, so studios reconnect when you switch to live keys at launch.

## Production

- **Database:** Postgres (`DB_CONNECTION=pgsql`). Run `php artisan migrate --force` on deploy.
- **Queue:** emails are queued, so keep a worker running: `php artisan queue:work`.
- **Scheduler:** `* * * * * php artisan schedule:run` drives the hourly `loft:housekeeping` (reminders, expiry warnings, expiring credits).
- **Mail:** set a real mailer (Postmark, SES, Resend…). `MAIL_FROM_ADDRESS` must be a domain you've verified. Studio emails use the studio's name as the sender name and its contact address as Reply-To.
- **Caching:** `php artisan optimize` caches config, routes and views. Every route is named, so route caching works.
- **Storage:** waiver signature drawings are stored on the private `local` disk (`storage/app/private/signatures`). Back it up, or point the disk at S3.
- **Hosting:** Laravel Cloud, Forge or any PHP 8.3 host works.

## Tests

```bash
php artisan test
```

There are 74 tests. They cover:
- the booking rules
- daylight-saving-safe session generation
- the Stripe client and webhooks, against faked HTTP
- tenancy and roles
- every member and admin page

They run on SQLite by default. To run them against Postgres:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=loft_test DB_USERNAME=postgres php vendor/bin/phpunit
```

CI (`.github/workflows/web.yml`) runs both.

## Not built yet

- **Studio subdomains and custom domains.** URLs are path-based today; a host-based resolver can go in front of the same routes.
- **Billing studios for the platform itself.** A subscription per studio, or the existing application fee.
- **Importing an existing WordPress plugin install.** Its tables map almost one-to-one.
- **A JSON API,** so the WordPress plugin or a mobile app can embed the schedule.
- **SMS reminders.** Listen to the same events the emails do.
- **Email verification, and a platform-operator admin.**
