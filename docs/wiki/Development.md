# Development

## Run it locally

```bash
cd web
composer setup        # install, .env, app key, SQLite database, migrations + demo studio
php artisan dev       # web server + queue worker + logs
```

Open http://localhost:8000/skyline. The demo accounts all use the password `password`:

| Email | Role |
|---|---|
| owner@example.com | Studio owner: everything under `/skyline/admin` |
| coach@example.com | Instructor: check-in only |
| parent@example.com | Member with two children and a membership |
| adult@example.com | Member with a class pack |

- **Reset the demo:** `php artisan migrate:fresh --seed`.
- **Emails locally** go to `storage/logs/laravel.log` (`MAIL_MAILER=log`, debug level).
- **Testing Stripe webhooks** with the Stripe CLI: `stripe listen --forward-connect-to localhost:8000/stripe/webhook`.

## Tests

```bash
cd web
php artisan test                      # SQLite
vendor/bin/pint                       # code style
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=loft_test DB_USERNAME=postgres php vendor/bin/phpunit   # Postgres
```

- **Size:** 134 tests (November 2026) in `tests/Feature`.
- **Stripe:** faked over HTTP; every Stripe call runs against fake responses.
- **Coverage:** booking rules, the orders ledger (online, membership invoices, in person, the Sales page and CSV), automations (first class, birthdays, win-back, the admin screens), the event outbox and relay, email logging and consent, nightly snapshots, rooms and apparatus (spots, item numbers, waitlist limits and promotion, admin screens), waiver rules (program waivers, versions, enforcement, overrides, download), session generation, the demo seeder, Stripe client and webhooks (including delayed payments), tenancy and roles, account deletion, payments recorded in person, branding colors, email designs, sending through Resend, and every member and admin page.

## CI (`.github/workflows/web.yml`)

Runs on pushes and pull requests that touch `web/`, `render.yaml` or the workflow itself:

| Job | Steps |
|---|---|
| `test` | Install, Pint, tests on SQLite, tests on Postgres 16, route cache |
| `docker` | Build the production image, start it and check `/up`, check that fonts allow cross-origin use (for email web fonts) |

A separate **Wiki** workflow publishes `docs/wiki/` to the GitHub wiki.

## Making a change

1. Work on a branch, and open a pull request into `main`.
2. Wait for CI to pass on both jobs.
3. Squash-merge. Render deploys `main` automatically ([Deployment](Deployment#releasing)).
4. Check the deploy, then delete the branch. Claude Code sessions can't delete branches, so use the **Delete branch** button on the merged pull request.
5. Update this wiki (`docs/wiki/`) and `web/README.md` when behavior changes.

## The WordPress plugin

`plugin/` holds the original WordPress plugin. It isn't released, deployed or tested in CI while releases are paused ([decision 19](Decisions)). Leave it unchanged unless releases resume. The release checklist and zip script are in git history (`RELEASING.md`, `bin/build-zip.sh`).

## Conventions

- **Tenancy:** every studio-owned table has `studio_id`. Child routes are scoped to the studio.
- **Time:** datetimes are stored in UTC. Use the studio's helpers (`local()`, `localNow()`, `parseLocal()`) for anything shown or entered.
- **Money:** in cents, formatted with `App\Support\Money`.
- **Rules and settings:**
  - Business rules live in `app/Services`. Controllers validate input, call a service and redirect.
  - A change worth remembering records a domain event (`DomainEvents::record`, `forBooking`, `forEntitlement`) inside the same transaction as the change ([Architecture](Architecture#the-outbox-domain_events)).
  - Studio email goes through `App\Services\Messages` with a dedupe key, never straight to `Mail`.
  - Studio settings are a JSON column with defaults in `Studio::DEFAULT_SETTINGS`.
- **Routes:** every route is named (route caching requires it).
- **Migrations:** run automatically before each deploy goes live. Keep them safe for the previous version of the code (add, don't drop or rename in the same release).
- **YAML in workflows:** a value containing `: ` must be quoted or written as a block (`run: |`), or GitHub rejects the whole file.

## Building the Docker image locally

```bash
docker build -t loft-web web
docker run -p 8080:8080 -e APP_KEY="$(head -c 32 /dev/urandom | base64)" -e APP_ENV=production -e SESSION_DRIVER=array -e CACHE_STORE=array loft-web
```
