# Loft Studio

Studio software for dance, aerial and fitness studios. It covers class schedules and waitlists, memberships and class packs, family accounts, digital waivers, check-in, and payments that go straight into each studio's own Stripe account.

**Live at [loftstudio.app](https://loftstudio.app).**

## What's here

| Folder | What's in it |
|---|---|
| [`web/`](web/README.md) | The Loft web app: Laravel 13, PHP 8.3, PostgreSQL 16, server-rendered Blade. Deployed on Render. |
| [`mobile/`](mobile/README.md) | The Loft app for iOS and Android: Expo (React Native, TypeScript) on the web app's API. |
| [`docs/wiki/`](docs/wiki) | Source of the [project wiki](https://github.com/CyberpunkAidy/loft-studio/wiki), published on every merge to `main` |
| [`render.yaml`](render.yaml) | Render Blueprint: the web service, queue worker, hourly cron job and Postgres database |
| [`.github/workflows/`](.github/workflows) | CI for the web app and the mobile app, and the wiki publisher |
| [`plugin/`](plugin/README.md) | The original WordPress plugin (v0.1.1) the web app was ported from. Kept for reference; releases are paused. |

## Getting started

- **Run it locally:** [`web/README.md`](web/README.md#run-it-locally). `composer setup`, then `php artisan dev`, gives you a demo studio at http://localhost:8000/skyline.
- **The mobile app:** [`mobile/README.md`](mobile/README.md). `npm install`, then `npm start`, and open it in Expo Go.
- **Deploy and operate:** [wiki → Deployment](https://github.com/CyberpunkAidy/loft-studio/wiki/Deployment) and [Stripe](https://github.com/CyberpunkAidy/loft-studio/wiki/Stripe).
- **Contribute:** [wiki → Development](https://github.com/CyberpunkAidy/loft-studio/wiki/Development). Open a pull request into `main`; CI must pass; merges deploy automatically.

## Documentation

The [wiki](https://github.com/CyberpunkAidy/loft-studio/wiki) covers:
- architecture, features, deployment, Stripe, email, payments, and accounts and data;
- the design system and development;
- the decisions behind the build, the roadmap and the changelog.

## Status

- **Web app:** live. Stripe Connect is being set up in test mode. Studios pay Loft $49 a month after a 30-day free trial ([Loft Subscription](https://github.com/CyberpunkAidy/loft-studio/wiki/Loft-Subscription)).
- **Built from the [roadmap](https://github.com/CyberpunkAidy/loft-studio/wiki/Roadmap):** waiver hardening; rooms with apparatus capacity and waitlist limits; the event backbone (an event outbox, an email log, marketing consent, nightly member snapshots); automated emails (after a first class, birthdays, we miss you); an orders ledger of every sale; merchandise, a front-desk point of sale with Stripe Terminal card readers, an online shop with pickup at the studio; a JSON API for the mobile app (v1); contactless check-in at a kiosk with tags or QR codes; churn risk scoring with an at-risk email; and online and hybrid classes joined through the studio's meeting link, with an on-demand video library on Mux. All ten roadmap steps are built; what's left in each is on the roadmap.
- **Mobile app:** the first version is built in `mobile/` (Expo, on the web app's API): booking, check-in codes, videos and buying plans, for every studio in one app. Not in the stores yet.
- **WordPress plugin:** releases paused until the web app (and possibly the mobile app) is finished.
