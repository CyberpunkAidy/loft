# Loft

Loft is studio software for dance, aerial and fitness studios. It covers class schedules, memberships and class packs, family accounts, digital waivers, check-in and payments.

The repository holds three products:

| | What it is | Where |
|---|---|---|
| **Loft web app** | The hosted service for many studios at once. Live at **https://loftstudio.app**. | `web/` |
| **Loft app** | The mobile app for members, for iOS and Android: one app for every studio. Not in the stores yet. | `mobile/` |
| **Loft Studio plugin** | The original WordPress plugin (v0.1.1) for a single studio, which the web app was ported from. Kept for reference; **releases are paused** until the web app (and possibly the mobile app) is finished. | `plugin/` |

This wiki covers the web app and the mobile app. The plugin is documented in `plugin/README.md` and `plugin/ARCHITECTURE.md`.

## Status (November 2026)

- **Hosting and email:** live on Render at loftstudio.app. Email goes out through Resend from `hello@mail.loftstudio.app`.
- **Payments:** Stripe Connect is built (studios connect their own Stripe accounts, created through Accounts v2), as are payments recorded in person. Setup in Stripe test mode is under way; [Stripe](Stripe) lists the remaining steps.
- **Design:** the Loft logo and design system cover the site and the emails.
- **Tests:** the web app's automated tests run on every change, on SQLite and Postgres, plus a check that the production Docker image builds and starts; the mobile app's are type-checked and tested too.
- **Loft subscription:** $49 a month per studio after a 30-day free trial, billed on Loft's own Stripe account ([Loft Subscription](Loft-Subscription)). Studios that were already on Loft are free.
- **Data import:** studios bring members, families, credits and memberships over from another system as CSV files, and invite members when they're ready ([Data Import](Data-Import)).
- **Legal:** Terms of Service (with data processing terms) and a Privacy Policy at loftstudio.app/terms and /privacy, to be reviewed by a lawyer ([Legal](Legal)).
- **Home page:** loftstudio.app sells one flat price with everything included, with real screenshots, a savings calculator, free help moving from another system, and search and share tags ([Home Page](Home-Page)).
- **Roadmap:** all ten steps are built ([Roadmap](Roadmap)). The mobile app's first version is built on the API ([Mobile App](Mobile-App)); it isn't in the stores yet.

## Pages

- [Architecture](Architecture): the stack, tenancy, data model, services and events.
- [Features](Features): what members, staff and studio owners can do, and the booking rules.
- [Deployment](Deployment): Render, DNS, environment variables, releasing and rolling back.
- [Stripe](Stripe): Connect setup, webhooks, API versions and troubleshooting.
- [Email](Email): Resend setup, the templates and troubleshooting.
- [Payments](Payments): online, delayed (bank) and in-person payments.
- [Loft Subscription](Loft-Subscription): what studios pay Loft: the trial, subscribing, grace and pausing, comps, and the Stripe setup.
- [Legal](Legal): the Terms of Service and Privacy Policy: what they cover, where they live, acceptance records and how to change them.
- [Home Page](Home-Page): the marketing page: what it says, the promises it makes, search and share tags, and how to retake its screenshots.
- [Data Import](Data-Import): moving a studio's members, families, credits and memberships over from another system, and inviting them.
- [API](API): the mobile app's JSON API (v1): tokens, errors, endpoints.
- [Mobile App](Mobile-App): the Loft app for iOS and Android: what it does, how it's built, getting it into the stores.
- [Accounts and Data](Accounts-and-Data): deleting accounts, closing studios and what's kept.
- [Design System](Design-System): logo, colors, fonts, icons and components.
- [Development](Development): running it locally, tests, CI and pull requests.
- [Decisions](Decisions): why things are built the way they are, and open questions.
- [Roadmap](Roadmap): what's next, with the planned schema.
- [Changelog](Changelog): every change shipped so far.

> This wiki is generated from `docs/wiki/` in the repository by the **Wiki** workflow. Edit the files there; changes made directly in the wiki are overwritten on the next publish.
