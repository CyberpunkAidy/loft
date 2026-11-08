# Loft

Studio software for dance, aerial and fitness studios. It covers class schedules and waitlists, memberships and class packs, family accounts, digital waivers, check-in, and payments that go straight into each studio's own Stripe account.

**Live at [loftstudio.app](https://loftstudio.app).**

## What's here

| Folder | What's in it |
|---|---|
| [`web/`](web/README.md) | The Loft web app: Laravel 13, PHP 8.3, PostgreSQL 16, server-rendered Blade. Deployed on Render. |
| [`docs/wiki/`](docs/wiki) | Source of the [project wiki](https://github.com/CyberpunkAidy/loft-studio/wiki), published on every merge to `main` |
| [`render.yaml`](render.yaml) | Render Blueprint: the web service, queue worker, hourly cron job and Postgres database |
| [`.github/workflows/`](.github/workflows) | CI for the web app, and the wiki publisher |
| [`plugin/`](plugin/README.md) | The original WordPress plugin (v0.1.1) the web app was ported from. Kept for reference; releases are paused. |

## Getting started

- **Run it locally:** [`web/README.md`](web/README.md#run-it-locally). `composer setup`, then `php artisan dev`, gives you a demo studio at http://localhost:8000/skyline.
- **Deploy and operate:** [wiki → Deployment](https://github.com/CyberpunkAidy/loft-studio/wiki/Deployment) and [Stripe](https://github.com/CyberpunkAidy/loft-studio/wiki/Stripe).
- **Contribute:** [wiki → Development](https://github.com/CyberpunkAidy/loft-studio/wiki/Development). Open a pull request into `main`; CI must pass; merges deploy automatically.

## Documentation

The [wiki](https://github.com/CyberpunkAidy/loft-studio/wiki) covers:
- architecture, features, deployment, Stripe, email, payments, and accounts and data;
- the design system and development;
- the decisions behind the build, the roadmap and the changelog.

## Status

- **Web app:** live. Stripe Connect is being set up in test mode.
- **Built from the [roadmap](https://github.com/CyberpunkAidy/loft-studio/wiki/Roadmap):** waiver hardening; rooms with apparatus capacity and waitlist limits; the event backbone (an event outbox, an email log, marketing consent, nightly member snapshots). **Next:** automated emails.
- **Mobile app:** planned, built with React Native (Expo) against a Laravel API.
- **WordPress plugin:** releases paused until the web app (and possibly the mobile app) is finished.
