# Decisions

Why things are built the way they are. Newest at the bottom. Open questions are at the end.

| # | Date | Decision | Why |
|---|---|---|---|
| 1 | 2026-11-07 | Build the hosted product as a **Laravel port of the WordPress plugin**, in `web/`, and keep the plugin as it is | Reuses the plugin's proven booking, billing and waiver rules; PHP like the plugin; the plugin keeps serving single-studio sites |
| 2 | 2026-11-07 | **Path-based tenancy** (`/{studio}`) with **global users** and a role per studio | One login across studios; one domain and certificate; studios can't see each other's rows (scoped bindings) |
| 3 | 2026-11-07 | **Server-rendered Blade**, one hand-written stylesheet, no JavaScript build | Fast pages, SEO-friendly public schedules, nothing to compile, few moving parts |
| 4 | 2026-11-07 | **Host on Render** with Docker and a Blueprint | Netlify was tried first, but it can't run PHP. Laravel Cloud was considered; Render was chosen for one Blueprint covering web, worker, cron and Postgres |
| 5 | 2026-11-07 | **Resend** for email, sending from `mail.loftstudio.app` | Laravel has a built-in Resend mailer; a sending subdomain keeps the main domain's reputation separate |
| 6 | 2026-11-07 | Domain **loftstudio.app** (registered at Porkbun) for the site and email | loft.com was considered at the start; loftstudio.app was chosen |
| 7 | 2026-11-07 | **Stripe Connect with direct charges** into each studio's own account | Money goes straight to the studio; the studio handles refunds, disputes and payouts; an optional platform fee; Loft is never the merchant of record |
| 8 | 2026-11-07 | **Webhooks fetch objects by ID** in a pinned API version ([#7](https://github.com/CyberpunkAidy/loft-studio/pull/7)) | Stripe no longer offers old versions for new webhooks, and payload shapes change between versions; fetching also handles events arriving out of order |
| 9 | 2026-11-08 | **Accounts v2** to create and onboard studio accounts, everything else on v1 ([#8](https://github.com/CyberpunkAidy/loft-studio/pull/8)) | Stripe blocks v1 account creation for new platforms; v1 endpoints accept v2 accounts |
| 10 | 2026-11-08 | **Account deletion** cascades through the database, and cancels renewing Stripe memberships first ([#9](https://github.com/CyberpunkAidy/loft-studio/pull/9)) | A deleted person must never be charged again; nothing is deleted if Stripe can't be reached |
| 11 | 2026-11-08 | **A studio's only owner** hands the studio over or closes it when deleting their account; owners can make other owners ([#10](https://github.com/CyberpunkAidy/loft-studio/pull/10)) | A studio always has an owner, and the owner decides, rather than Loft guessing |
| 12 | 2026-11-08 | **Own logo and design system**: self-hosted fonts, Lucide icons, readable studio colors ([#11](https://github.com/CyberpunkAidy/loft-studio/pull/11)) | A distinct brand; no font CDN; any brand color stays accessible |
| 13 | 2026-11-08 | **Emails as table-based HTML** with a PNG logo ([#12](https://github.com/CyberpunkAidy/loft-studio/pull/12)) | Renders the same in Gmail, Outlook and Apple Mail |
| 14 | 2026-11-08 | **Payment options:** use the methods each studio turns on in Stripe, and record in-person payments in Loft; **no** PayPal or Square integration, and no platform-held funds ([#13](https://github.com/CyberpunkAidy/loft-studio/pull/13)) | Covers how studios are actually paid without a second processor; holding funds would make Loft liable for refunds, disputes and losses |
| 15 | 2026-11-08 | **Comps are recorded** like payments, with an amount of 0, and can be voided | An audit trail of who gave what, and a way to undo mistakes |
| 16 | 2026-11-08 | **Docs live in `docs/wiki/`** and are published to the GitHub wiki by a workflow | Docs are reviewed in pull requests with the code; Claude Code sessions can't push to the wiki directly |

## Open questions

### Keeping signed waivers when accounts are deleted

- **Today:** deleting an account, or closing a studio, deletes the waivers signed there.
- **Proposed:** keep signature records, unlinked from the account but with the signer's name and a hash of the exact text signed. Studios may need them for liability. See [Roadmap](Roadmap), step 1.
- **Status:** awaiting a decision.

### Tech stack: a proposal to move to Next.js and Node

A stack was proposed on 2026-11-08: Next.js (React, TypeScript, Tailwind) for web, React Native (Expo) for mobile, a Node.js API (Next.js routes or NestJS), PostgreSQL with Prisma or Drizzle, Stripe Connect, Twilio and SendGrid.

The product is already built, live and tested on Laravel. Here's how the proposal compares:

| Proposed | In Loft today | Recommendation |
|---|---|---|
| Next.js + TypeScript + Tailwind for web | Laravel Blade and a hand-written design system | **Keep.** Public pages are already server-rendered and SEO-friendly. Revisit a React front end only if the dashboard needs heavy in-browser interactivity. |
| React Native (Expo) for mobile | No app or API yet | **Adopt for the app**, talking to a Laravel JSON API (`/api/v1`, Sanctum tokens). Share TypeScript types generated from an OpenAPI description of the API, rather than sharing server code. |
| Node.js API (Next.js routes, NestJS, Express) | Laravel 13 (PHP 8.3) | **Keep Laravel.** A rewrite would take months and add nothing the roadmap needs. Booking, payment and waiver rules must run on the server either way. |
| PostgreSQL with JSONB | PostgreSQL 16 | Same. JSONB is planned for event payloads, automation rules and churn features. |
| Prisma or Drizzle | Eloquent and Laravel migrations | **Keep Eloquent.** |
| Stripe Connect, including Terminal | Stripe Connect (Accounts v2, direct charges) | Same. Add Stripe Terminal for the point of sale. |
| Twilio for SMS | Not yet | **Adopt** when SMS is built. Start US A2P 10DLC registration early; it takes weeks. |
| SendGrid for marketing email | Resend for all email | **Keep Resend.** It already sends everything, and it also offers broadcasts for marketing email. |

**Status:** awaiting a decision. The recommendation is to keep Laravel and build the mobile app against a Laravel API.
