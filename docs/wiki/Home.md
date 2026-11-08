# Loft

Loft is studio software for dance, aerial and fitness studios. It covers class schedules, memberships and class packs, family accounts, digital waivers, check-in and payments.

The repository holds two products:

| | What it is | Where |
|---|---|---|
| **Loft web app** | The hosted service for many studios at once. Live at **https://loftstudio.app**. | `web/` |
| **Loft Studio plugin** | The original WordPress plugin (v0.1.1) for a single studio, which the web app was ported from. Kept for reference; **releases are paused** until the web app (and possibly the mobile app) is finished. | `plugin/` |

This wiki covers the web app. The plugin is documented in `plugin/README.md` and `plugin/ARCHITECTURE.md`.

## Status (November 2026)

- **Hosting and email:** live on Render at loftstudio.app. Email goes out through Resend from `hello@mail.loftstudio.app`.
- **Payments:** Stripe Connect is built (studios connect their own Stripe accounts, created through Accounts v2), as are payments recorded in person. Setup in Stripe test mode is under way; [Stripe](Stripe) lists the remaining steps.
- **Design:** the Loft logo and design system cover the site and the emails.
- **Tests:** 107 automated tests run on every change, on SQLite and Postgres, plus a check that the production Docker image builds and starts.
- **Direction (decided 2026-11-08):** Loft stays on Laravel, and a mobile app (React Native with Expo) will use a Laravel API. Waiver hardening (roadmap step 1) is built; next up are rooms and apparatus capacity ([Roadmap](Roadmap)).

## Pages

- [Architecture](Architecture): the stack, tenancy, data model, services and events.
- [Features](Features): what members, staff and studio owners can do, and the booking rules.
- [Deployment](Deployment): Render, DNS, environment variables, releasing and rolling back.
- [Stripe](Stripe): Connect setup, webhooks, API versions and troubleshooting.
- [Email](Email): Resend setup, the templates and troubleshooting.
- [Payments](Payments): online, delayed (bank) and in-person payments.
- [Accounts and Data](Accounts-and-Data): deleting accounts, closing studios and what's kept.
- [Design System](Design-System): logo, colors, fonts, icons and components.
- [Development](Development): running it locally, tests, CI and pull requests.
- [Decisions](Decisions): why things are built the way they are, and open questions.
- [Roadmap](Roadmap): what's next, with the planned schema.
- [Changelog](Changelog): every change shipped so far.

> This wiki is generated from `docs/wiki/` in the repository by the **Wiki** workflow. Edit the files there; changes made directly in the wiki are overwritten on the next publish.
