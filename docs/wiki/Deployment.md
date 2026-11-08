# Deployment

Loft runs on [Render](https://render.com), defined by the Blueprint in `render.yaml` at the repository root. Every merge to `main` deploys automatically.

## Services

| Render service | Type | What it runs |
|---|---|---|
| `loft-web` | Web service (Docker) | The site, behind Apache. Health check `/up`. Runs database migrations before each deploy goes live (`start migrate`). Custom domain `loftstudio.app`. |
| `loft-worker` | Background worker (Docker) | `queue:work` (3 tries, 30-second backoff, restarts hourly). Sends the queued emails. |
| `loft-housekeeping` | Cron job (Docker), `0 * * * *` | `loft:housekeeping`: reminders, credit-expiry warnings, expiring plans |
| `loft-db` | PostgreSQL 16 | The database. Only Render services can connect (`ipAllowList: []`). |

- **Shared image and settings:** all three app services build the same image from `web/Dockerfile` (`rootDir: web`) and share the `loft-shared` environment group.
- **Disk:** `loft-web` has a 1 GB persistent disk at `storage/app/private` for waiver signature drawings. A service with a disk can't run more than one instance.
- **Container:** the image is `php:8.3-apache`. `docker/start.sh` picks the role (`web`, `worker`, `housekeeping` or `migrate`). It also:
  - adds Laravel's `base64:` prefix to the `APP_KEY` Render generates;
  - falls back to `RENDER_EXTERNAL_URL` for `APP_URL`;
  - caches config, routes and views.

## Environment variables

Values aren't recorded here. Secrets live only in Render's dashboard.

| Variable | Where | Notes |
|---|---|---|
| `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `LOG_CHANNEL`, `LOG_LEVEL`, `DB_CONNECTION`, `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`, `TRUSTED_PROXIES`, `MAIL_FROM_ADDRESS` | `loft-shared` group (Blueprint) | `APP_URL=https://loftstudio.app`, `MAIL_FROM_ADDRESS=hello@mail.loftstudio.app` |
| `APP_KEY` | `loft-shared` group | Generated once by Render |
| `DB_URL` | Each service (Blueprint) | From the `loft-db` connection string |
| `MAIL_MAILER`, `RESEND_API_KEY` | `loft-shared` group, **set in the dashboard** | `MAIL_MAILER=resend`. Left out of the Blueprint so a sync never overwrites them. |
| `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | `loft-web`, **set in the dashboard** | `sync: false` in the Blueprint. The secret key starts with `sk_`, not `pk_`. |
| `LOFT_PLATFORM_FEE_PERCENT` | `loft-web` | `0`: no platform fee |
| `STRIPE_API_VERSION`, `STRIPE_V2_API_VERSION` | Optional | Default to `2024-06-20` and `2026-10-28.endive` ([Stripe](Stripe)) |

A Blueprint sync overwrites the keys it declares and leaves everything else as it is.

## Domain and DNS (Porkbun)

| Record | Name | Value | Purpose |
|---|---|---|---|
| `ALIAS` | `@` | `loft-web.onrender.com` | The site at loftstudio.app |
| `CNAME` | `www` | `loft-web.onrender.com` | Render redirects www to the bare domain |
| `TXT` | `_dmarc` | `v=DMARC1; p=none;` | DMARC policy (monitor only for now) |
| Resend's records | under `mail` | as shown in Resend | Sending domain `mail.loftstudio.app` (verified) |

- **TLS:** Render issues the certificates.
- **Checking DNS:** if a record looks missing right after you add it, an earlier lookup may still be cached. A "not found" answer can be cached for up to 30 minutes (the zone's SOA minimum). Check through another resolver before changing anything.

## Releasing

1. Merge a pull request into `main`, once CI is green.
2. Render builds the image and deploys `loft-web`, `loft-worker` and `loft-housekeeping` from that commit.
3. `loft-web` runs `start migrate` before the new version takes traffic. If a migration fails, the deploy stops and the old version keeps running.
4. Check the release:
   - Each service's latest deploy shows **Live** on the same commit.
   - `curl https://loftstudio.app/up` returns 200.
   - The logs since the deploy show no `production.ERROR`.

**Rolling back:** in Render, open the service, go to **Events** or **Deploys**, and roll back to the previous deploy. Database migrations aren't undone by a rollback, so new migrations should be safe for the previous version of the code (add columns and tables; don't drop or rename in the same release).

## Logs

The app logs to stderr, which Render captures per service. Search for `production.ERROR` for exceptions. Apache's access log is in the same stream.

## Going live with real payments

1. Switch `STRIPE_SECRET` and `STRIPE_WEBHOOK_SECRET` to live-mode values. Create a separate live-mode webhook with the same events ([Stripe](Stripe)).
2. Test-mode and live-mode Stripe objects don't mix. Each studio reconnects Stripe, and plans are synced to Stripe again.
