# Deployment

Loft runs on [Render](https://render.com), defined by the Blueprint in `render.yaml` at the repository root. Every merge to `main` deploys automatically.

## Services

| Render service | Type | What it runs |
|---|---|---|
| `loft-web` | Web service (Docker) | The site, behind Apache. Health check `/up`. Runs database migrations before each deploy goes live (`start migrate`). Custom domain `loftstudio.app`. |
| `loft-worker` | Background worker (Docker) | `queue:work` (3 tries, 30-second backoff, restarts hourly). Sends the queued emails. |
| `loft-housekeeping` | Cron job (Docker), `0 * * * *` | `loft:housekeeping`: reminders, credit-expiry warnings, expiring plans, nightly member snapshots and churn scores (after 2 AM studio time), birthday, win-back and at-risk automations (from 10 AM studio time), settling shop checkouts and card reader sales nobody finished (asks Stripe, so it needs `STRIPE_SECRET`), relaying domain events |
| `loft-db` | PostgreSQL 16 | The database. Only Render services can connect (`ipAllowList: []`). |

- **Shared image and settings:** all three app services build the same image from `web/Dockerfile` (`rootDir: web`) and share the `loft-shared` environment group.
- **Disk:** `loft-web` has a 1 GB persistent disk at `storage/app/private` for waiver signature drawings. A service with a disk can't run more than one instance.
- **Container:** the image is `php:8.3-apache` with the `pdo_pgsql`, `opcache`, `pcntl` and `zip` extensions (zip builds the signed-waiver download). `docker/start.sh` picks the role (`web`, `worker`, `housekeeping` or `migrate`). It also:
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
| `STRIPE_SECRET` | `loft-web` and `loft-housekeeping`, **set in the dashboard** | The same key on both. `sync: false` in the Blueprint. The secret key starts with `sk_`, not `pk_`. Without it on `loft-housekeeping`, shop checkouts and card reader sales nobody finished stay pending and keep their stock, and its logs show `Stripe is not configured. Set STRIPE_SECRET.` |
| `STRIPE_WEBHOOK_SECRET` | `loft-web`, **set in the dashboard** | `sync: false` in the Blueprint |
| `LOFT_PLATFORM_FEE_PERCENT` | `loft-web` | `0`: no platform fee |
| `STRIPE_BILLING_WEBHOOK_SECRET` | `loft-web`, **set in the dashboard** | The billing webhook's signing secret, for studios' Loft subscriptions ([Loft Subscription](Loft-Subscription#setting-it-up-once)). `sync: false` in the Blueprint |
| `LOFT_LEGAL_ENTITY`, `LOFT_LEGAL_STATE`, `LOFT_LEGAL_EMAIL` | Optional | Who operates Loft, whose law governs the terms, and where privacy requests go: `Goldseam Tech LLC`, `Wyoming`, `privacy@loftstudio.app` by default ([Legal](Legal)) |
| `LOFT_MONTHLY_PRICE_CENTS`, `LOFT_BILLING_CURRENCY`, `LOFT_TRIAL_DAYS`, `LOFT_BILLING_GRACE_DAYS`, `LOFT_BILLING_AUTOMATIC_TAX` | Optional, `loft-shared` group | The Loft subscription: `4900`, `usd`, `30` days, `7` days and `false` by default |
| `STRIPE_API_VERSION`, `STRIPE_V2_API_VERSION` | Optional | Default to `2024-06-20` and `2026-10-28.endive` ([Stripe](Stripe)) |
| `MUX_TOKEN_ID`, `MUX_TOKEN_SECRET`, `MUX_SIGNING_KEY_ID`, `MUX_SIGNING_KEY` | `loft-shared` group, **set in the dashboard** | On-demand video ([below](#video-mux)). In the group so the hourly job can follow uploads too. Without them, Admin → Videos says video isn't switched on |
| `LOFT_VIDEO_MINUTES_PER_STUDIO`, `LOFT_VIDEO_TOKEN_HOURS` | Optional | Stored video allowed per studio (default `600` minutes), and how long playback tokens last (default `6` hours, to the end of the hour) |

A Blueprint sync overwrites the keys it declares and leaves everything else as it is. The exception is `sync: false` keys: Render asks for them only when the Blueprint is first created and ignores them on every later sync. So a `sync: false` key added to `render.yaml` for a service that already exists has to be added by hand in that service's **Environment** page.

## Video (Mux)

Every studio's videos are on Loft's own Mux account ([decision 33](Decisions)); studios set nothing up.

1. In Mux, use a **Production** environment.
2. **Settings → Access Tokens:** a token with **Mux Video** read and write. Its ID and secret are `MUX_TOKEN_ID` and `MUX_TOKEN_SECRET`.
3. **Settings → Signing Keys:** a key for video. Its ID is `MUX_SIGNING_KEY_ID`; the private key, exactly as Mux shows it (base64, shown once), is `MUX_SIGNING_KEY`.
4. Add all four to the `loft-shared` group in Render's dashboard. The services redeploy.

There's no Mux webhook to set up: Loft asks Mux about videos still processing. Browsers upload straight to Mux, which only accepts uploads from `APP_URL` (`https://loftstudio.app`), so add videos from there, not from `loft-web.onrender.com`. Every asset is created with **signed** playback and **basic** quality (no encoding charge); Mux bills Loft for storage and streaming, which `LOFT_VIDEO_MINUTES_PER_STUDIO` caps. Mux Data is on without cookies.

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

1. Switch `STRIPE_SECRET` (on `loft-web` and `loft-housekeeping`) and `STRIPE_WEBHOOK_SECRET` to live-mode values. Create a separate live-mode webhook with the same events ([Stripe](Stripe)).
2. Test-mode and live-mode Stripe objects don't mix. Each studio reconnects Stripe, and plans are synced to Stripe again.
3. Create the billing webhook again in live mode ([Loft Subscription](Loft-Subscription#setting-it-up-once)) and switch `STRIPE_BILLING_WEBHOOK_SECRET`. Studios that subscribed in test mode subscribe again.
