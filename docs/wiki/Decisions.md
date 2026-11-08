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
| 17 | 2026-11-08 | **Keep Laravel.** Build the mobile app with React Native (Expo) against a Laravel JSON API | The proposed move to Next.js and Node would be a rewrite of a live, tested product, for nothing the roadmap needs. See [the comparison](#tech-stack-nextjs-and-node) |
| 18 | 2026-11-08 | **From the proposed Prisma schema, adopt rooms, apparatus owned by a room, and waitlist limits;** keep Loft's own schema for everything else (option A) | Rooms with a fire-code limit, and apparatus rigged in a room, model aerial studios better than the roadmap's first sketch. Loft already covers the rest more fully. See [the review](#a-proposed-prisma-schema) |
| 19 | 2026-11-08 | **Pause WordPress plugin releases.** Move the plugin to `plugin/` for reference, and remove the release checklist and zip script | Work is focused on the web app and the mobile app. The repository's root is now about the web app. Tags `v0.1.0` and `v0.1.1` remain, and the release tooling can be restored from git history |
| 20 | 2026-11-08 | **Keep signed waivers.** When an account is deleted, its waiver signatures stay with the studio, unlinked from the account, with the signer's and attendee's names and the exact text signed. A studio's owner can download every signed waiver, for example before closing the studio | Studios may need signed waivers as evidence of liability long after a member leaves; once a studio is deleted, nobody could reach records kept inside Loft, so the owner takes a copy instead. Built with roadmap step 1 |

## Considered alternatives

### Tech stack: Next.js and Node

A stack was proposed on 2026-11-08: Next.js (React, TypeScript, Tailwind) for web, React Native (Expo) for mobile, a Node.js API (Next.js routes or NestJS), PostgreSQL with Prisma or Drizzle, Stripe Connect, Twilio and SendGrid.

**Decided: keep Laravel** (decision 17). Here's how it compared:

| Proposed | In Loft | Outcome |
|---|---|---|
| Next.js + TypeScript + Tailwind for web | Laravel Blade and a hand-written design system | Kept Blade. Public pages are already server-rendered and SEO-friendly. Revisit a React front end only if the dashboard needs heavy in-browser interactivity. |
| React Native (Expo) for mobile | No app or API yet | **Adopted** for the app, talking to a Laravel JSON API (`/api/v1`, Sanctum tokens). Share TypeScript types generated from an OpenAPI description of the API, rather than sharing server code. |
| Node.js API (Next.js routes, NestJS, Express) | Laravel 13 (PHP 8.3) | Kept Laravel. Booking, payment and waiver rules run on the server either way. |
| PostgreSQL with JSONB | PostgreSQL 16 | Same. JSONB is planned for event payloads, automation rules and churn features. |
| Prisma or Drizzle | Eloquent and Laravel migrations | Kept Eloquent |
| Stripe Connect, including Terminal | Stripe Connect (Accounts v2, direct charges) | Same. Stripe Terminal is planned for the point of sale. |
| Twilio for SMS | Not yet | **Adopted** for SMS. Start US A2P 10DLC registration early; it takes weeks. |
| SendGrid for marketing email | Resend for all email | Kept Resend. It already sends everything, and it offers broadcasts for marketing email. |

### A proposed Prisma schema

A Prisma schema was proposed on 2026-11-08, with `Studio`, `User` (one studio, one role), `Waiver` and `UserWaiver`, `Membership`, `Room`, `Apparatus`, `Class` and `Booking`.

**Decided: option A** (decision 18). Its rooms and room-based apparatus are folded into Loft's Laravel schema (see [Roadmap](Roadmap)); Loft's own model covers the rest.

| Proposed model | Loft | What differs |
|---|---|---|
| `Studio` | `studios` | Loft also stores the slug, currency, brand color, settings and Stripe account |
| `User` (one studio, one role) | `users` (one login everywhere) + `studio_users` (a role per studio) | Loft supports several studios per person, and an admin role |
| *(none)* | `attendees` | Family members; the proposal couldn't book a child |
| `Waiver`, `UserWaiver` | `waivers` (versioned), `waiver_signatures` | Loft records the version signed, the guardian who signed, the typed name, the drawing, the IP address and the expiry |
| `Membership` | `plans` (what's sold) + `entitlements` (what's held) | Loft has status, weekly limits, class scope, Stripe IDs and seasonal tuition |
| `Room` | none | **Adopted** |
| `Apparatus` (in a room) | none | **Adopted**, plus a per-class allocation and an optional item number |
| `Class` | `programs` + `class_sessions` | Loft separates the recurring class from its dated sessions; `waitlistCapacity` **adopted** |
| `Booking` | `bookings` | Loft records the attendee, late-cancel and no-show statuses, waitlist position, the plan that paid, the credit used, and timestamps |
| `User.churnRiskScore` | planned `member_risk_scores` | A separate table keeps history, the model version and the reasons |

Problems found in the proposal, for the record:
- **It wouldn't validate.** Most relations are declared on one side only, and the enums are missing.
- **Studio separation:** `studioId` is missing from `Membership` and `Booking`, and emails aren't unique per studio.
- **One user, one studio:** no admin role.
- **Waivers:** no versioning, so editing a waiver changes what people appear to have signed.
- **Bookings:** don't record what paid for them, which breaks credit refunds and late-cancel and no-show policies.
- **Overbooking:** there's no locking, and a booking can't point at one particular silk.
- **Money:** there's no money model for a point of sale or checkout.

## Open questions

None at the moment. The last one, about keeping signed waivers, was settled by decision 20.
