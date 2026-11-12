# API

The JSON API for Loft's mobile app (roadmap step 7, [decision 29](Decisions)). It's version 1, at `https://loftstudio.app/api/v1`. It uses the same services, and so the same booking rules, as the website.

## Basics

- **Format:** JSON in and out. Send `Accept: application/json`.
- **Signing in:** a bearer token, `Authorization: Bearer <token>`. Tokens are made by signing in or registering, one per app install. Browser session cookies never sign in to the API.
- **Studios:** most endpoints are under `/studios/{slug}/…`, the studio's web address (for example `skyline`). They work only for that studio's records: another studio's booking ID is a 404.
- **Times:** UTC with a `Z` (`2026-11-10T00:00:00Z`). Classes also give the studio's local time with its offset (`starts_at_local: 2026-11-09T17:00:00-07:00`), and every studio has a `timezone`.
- **Money:** integer cents, with a `currency` (`usd`). Most also have a ready-made label (`price_label: "$110.00"`).
- **Lists:** single records are `{"data": {…}}`; lists are `{"data": […]}`. Long lists (sessions, bookings, orders) use cursor pages: follow `meta.next_cursor` with `?cursor=…` until it's `null`; `per_page` is up to 100.
- **Limits:** 120 requests a minute per member, or per address before signing in. Signing in and registering: 10 a minute per address. Going over returns `429 rate_limited` with `Retry-After`.

## Errors

Every error has the same shape:

```json
{ "error": { "code": "waiver_required", "message": "Leo needs to sign “Release” before booking.", "attendee_id": 12, "waiver_id": 3 } }
```

Use `code` to decide what to do. `message` is written for the member and can change.

| Status | Code | Meaning, and extra fields |
|---|---|---|
| 401 | `unauthenticated` | No token, or it's expired or revoked: sign in again |
| 403 | `not_a_member` | Signed in, but not a member of this studio: offer to join |
| 403 | `forbidden` | Not theirs (someone else's booking) |
| 403 | `studio_paused` | The studio's Loft subscription has lapsed, so it isn't taking bookings or payments ([Loft Subscription](Loft-Subscription)). Plan purchases say so with `checkout_unavailable` |
| 404 | `not_found` | No such record, or not at this studio |
| 422 | `validation_failed` | `fields`: `{field: [messages]}` |
| 422 | `invalid_credentials` | Wrong email or password |
| 422 | `questions_required` | Answer the studio's questions about this person first: `attendee_id`, `questions` (`health`, `photos`) |
| 422 | `waiver_required` | Sign a waiver first: `attendee_id`, `waiver_id` |
| 422 | `needs_plan` | No membership or credits cover the class: `plans` they can buy |
| 422 | `full` | The class and its waitlist are full |
| 422 | `closed` | Booking hasn't opened, or has closed |
| 422 | `cancelled` | The class was cancelled |
| 422 | `age` | Too young or too old for the class |
| 422 | `apparatus_invalid`, `apparatus_full` | The chosen apparatus isn't in the class, or has no spots left |
| 422 | `online_full` | Every online spot in a hybrid class is taken (the studio may still have room) |
| 422 | `not_open_yet`, `ended`, `not_online`, `not_booked`, `no_link` | Joining an online class: too early (`opens_at`), over, a booking in the studio, a cancelled booking or class, or the studio hasn't added the link |
| 409 | `already_booked` | Already in the class |
| 422 | `waiver_not_in_force` | That waiver was replaced or switched off |
| 422 | `checkout_unavailable` | The studio can't take online payments, or the plan isn't for sale |
| 401 | `unauthenticated` (device routes) | The device token is wrong or the device was removed |
| 503 | `video_unavailable` | Videos can't play right now (video is switched off on the server) |
| 502 | `payment_provider_error` | Stripe didn't answer as expected: try again |
| 409 | `request_in_progress`, 422 `idempotency_key_reused`, 400 `invalid_idempotency_key` | See below |
| 429 | `rate_limited` | Wait for `Retry-After` seconds |
| 500 | `server_error` | Our fault: try again later |

## Retrying safely

Booking, adding someone to the account, signing a waiver and starting a checkout accept an `Idempotency-Key` header (any unique string up to 255 characters, such as a UUID). Send a new key for each attempt. If the connection drops, resend with the same key:
- the first successful response comes back again, with `Idempotent-Replayed: true`, and nothing happens twice;
- errors aren't kept, so a failed attempt can be retried with the same key;
- the same key with a different body is refused (`idempotency_key_reused`), and so is one still being handled (`request_in_progress`).

Keys last 24 hours.

## Endpoints

### Signing in

| | | |
|---|---|---|
| `POST /auth/tokens` | `email`, `password`, `device_name` | `201` `{token, token_type, user}` |
| `DELETE /auth/tokens/current` | optional `push_token` | `204`: this token stops working; that push token is dropped |
| `POST /studios/{slug}/register` | `first_name`, `last_name`, `email`, `password` (8+), `phone`?, `is_student`?, `marketing_email`?, `device_name` | `201` `{token, user}`: a new account, a member of that studio |
| `POST /studios/{slug}/join` | `marketing_email`? | `201` (or `200` if already a member): an existing account joins |

Tokens don't expire. They're revoked by signing out, when the password is changed or reset, and when the account is deleted. Tokens start with `loft_` (after the ID), so a leaked one is easy to spot.

### The member

| | | |
|---|---|---|
| `GET /me` | | Name, email, phone, and `studios` (slug, name, role, time zone, currency, colour) |
| `PATCH /me` | `first_name`, `last_name`, `phone` | Email and password are changed on the website |
| `POST /me/devices` | `platform` (`ios`, `android`), `token`, `name`?, `app_version`? | Registers this install's push token (`201`, or `200` when refreshed). A token seen on another account moves to this one. Sending notifications comes later |
| `DELETE /me/devices/{id}` | | `204` |

### A studio (no sign-in needed)

| | |
|---|---|
| `GET /studios/{slug}` | Name, time zone, currency, colour, `logo` (`url`, `width`, `height`, or `null`: show the initial), contact email, website, whether it takes online payments, booking policies (with `questions`: the questions it asks of each person, `health` and `photos`). The logo URL changes when the logo does, so it can be cached for good; `/me`'s studios carry `logo` too |
| `GET /studios/{slug}/plans` | What it sells, with prices, labels, credits, season dates and `can_buy_online` |
| `GET /studios/{slug}/sessions` | The schedule: `from` (studio date, default today), `days` (1–62, default 14), `program_id`, `age_group`, `level`. Each class has times, program, instructor, room, price, `spots_left`, waitlist, apparatus spots, `booking_opens_at`, and where it happens: `delivery` (`in_person`, `online` or `hybrid`), `online_capacity` and `online_spots_left` (a hybrid class's online spots; `null` when there's no limit). With a token, `mine` lists the member's bookings in it |
| `GET /studios/{slug}/sessions/{id}` | One class. With a member's token, `booking` says for each person on the account whether they can book, and if not, why (`reason` uses the error codes above, with `questions` to answer and `waiver_id` to sign) |
| `GET /studios/{slug}/videos` | The video library: title, description, length, `access` (`members` or `plan`), a signed `thumbnail_url`; with a token, `can_watch` and `locked_reason` (`sign_in`, `not_a_member`, `needs_plan`). `meta.plans` lists the plans that include videos |

### Members of the studio

| | | |
|---|---|---|
| `GET /studios/{slug}/bookings` | `when` = `upcoming` (default) or `past` | Their bookings, in class order. Upcoming means booked, waitlisted or checked in, until the class ends (so an online class can be joined again); past is everything that has ended. Each has `online` (they join online) and `join_opens_at` |
| `POST /studios/{slug}/sessions/{id}/bookings` | `attendee_id`, `apparatus_id`?, `online`? | `201` booked or waitlisted (`status`, `position`), with a `message`. `online: true` takes one of a hybrid class's online spots (ignored for other classes; everyone in an online class is online). Idempotent |
| `POST /studios/{slug}/bookings/{id}/join` | | `{url}`: the online class's meeting link, from 15 minutes before class until it ends. Checks them in. Otherwise `not_open_yet` (with `opens_at`), `ended`, `not_online`, `not_booked` or `no_link` |
| `DELETE /studios/{slug}/bookings/{id}` | | Cancelled, with `credit_refunded` (late cancellations may keep the credit) |
| `GET /studios/{slug}/attendees` | | The people on the account |
| `POST /studios/{slug}/attendees` | `first_name`, `last_name`, `dob`?, `is_self`?, `emergency_contact`?, `medical_notes`?, `has_health_notes`?, `photo_consent`? | `201`. Idempotent. Ask the studio's questions here (`policies.questions` on the studio): `has_health_notes` true needs `medical_notes`, false clears them; `photo_consent` is `public`, `studio` or `none`. They're optional here and required before booking. Each person has `has_health_notes` (null until answered), `health_answered_at`, `photo_consent`, `photo_consent_at`, `photo_consent_by_studio` and `photo_consent_note` (how the studio heard, when its staff recorded the answer; show it so the member can check), and `questions_needed` |
| `PATCH /studios/{slug}/attendees/{id}` | the same fields | Only what's sent changes |
| `DELETE /studios/{slug}/attendees/{id}` | | `204`: removed from the account (kept in the studio's records) |
| `GET /studios/{slug}/waivers` | | Waivers in force with their text, and for each person: `signed`, `expired`, `needed` |
| `POST /studios/{slug}/attendees/{id}/waiver-signatures` | `waiver_id`, `signed_name`, `agree` (true), `signature_png`? (a data URL) | `201`, with `next_waiver_id` to sign next (or `null`). A waitlist spot waiting on the waiver may go through. Idempotent |
| `GET /studios/{slug}/entitlements` | | Memberships and credits, current ones first |
| `GET /studios/{slug}/orders` | | Purchases, newest first. `status` is `paid`, `refunded` or `void`; `refunded_cents` and `refunded_at` show any refund (part of a sale can be refunded while it stays `paid`) |
| `POST /studios/{slug}/checkouts` | `plan_id`, and to finish a booking once paid: `session_id`, `attendee_id`, `apparatus_id`?, `online`? | `201` `{id, url, expires_at, return_url}`. Idempotent |
| `GET /studios/{slug}/checkouts/{id}` | | `status`, `payment_status`, `fulfilled`, the `entitlement` and `booking` it made, or `booking_error` |
| `GET /studios/{slug}/attendees/{id}/check-in-code` | | `{code, expires_at, refresh_in}`: show `code` as a QR code, and fetch a new one after `refresh_in` seconds |
| `POST /studios/{slug}/videos/{id}/playback` | | `{video_id, playback_id, tokens: {playback, thumbnail, storyboard}, stream_url, expires_at}` to play it ([below](#videos)). `needs_plan` (with `plans`) if their plan doesn't include videos. Counts as a view |

### Check-in devices

Kiosks and readers sign in with their own **device token** (`loftdev_…`, from Admin → Check-in devices), not a member's. Device tokens work only here, and member tokens don't work here.

| | | |
|---|---|---|
| `GET /devices/me` | | The device's name and its studio |
| `POST /devices/check-ins` | `credential`: `{kind: "qr" \| "nfc" \| "rfid", value}`, `occurred_at`? | Always `200` for a scan: `result` (`granted` or `denied`), `reason`, a `message` to show, the `attendee` and `booking`. Idempotent |

- **What a scan does:** a QR `value` is a member's check-in code; anything else is a tag's UID (any separators, any case). Whoever it is gets checked in to their class that starts within the next hour, or has started and not ended. Their waivers must be signed (a device can't override that).
- **Reasons:** `checked_in` and `already_checked_in` (granted); `unknown_credential`, `expired_code`, `no_booking`, `waitlisted`, `waiver_required` (denied).
- **Offline:** a device that lost its connection sends `occurred_at` (up to a day old) with each scan it kept, and the class is matched to that time. Use the same `Idempotency-Key` if a send is retried.
- **Codes:** `LOFT1.{attendee}.{time step}.{signature}`, signed by Loft and changing every 30 seconds. They're accepted for about a minute either side, so a screenshot soon stops working.

## Booking, step by step

1. `GET /studios/{slug}/sessions/{id}` with the token: each person's `can_book`, or why not.
2. `POST …/sessions/{id}/bookings` with an `Idempotency-Key`.
3. On `questions_required`: ask `error.questions` (see [Injury and Photo Questions](Injury-and-Photo-Questions)), save the answers with `PATCH …/attendees/{id}`, then book again with a new key.
4. On `waiver_required`: show the waiver (`GET …/waivers`), sign it (`POST …/attendees/{id}/waiver-signatures`), then book again with a new key.
5. On `needs_plan`: show `error.plans`, then buy one with the class attached (`POST …/checkouts` with `session_id` and `attendee_id`), so the booking finishes once paid.

## Online classes

- A class's `delivery` says where it happens. For a `hybrid` class, ask each person **In the studio** or **Online** and book with `online: true` for online. Online spots don't use apparatus and have no waitlist; `online_full` means try the studio instead.
- When `join_opens_at` has passed, show **Join**: `POST …/bookings/{id}/join` and open the `url` (Zoom or Meet usually opens its own app). Call it each time rather than keeping the link: it's only given out while the class is on, and joining is what checks them in.
- Kiosks and tags don't check in people booked online.

## Videos

Videos are on Mux, with signed playback ([decision 33](Decisions)):
1. `GET /studios/{slug}/videos` for the library. Show `locked_reason` on locked ones: sign in, join the studio, or one of `meta.plans`.
2. `POST …/videos/{id}/playback` when they press play.
3. Play it with Mux's player SDK (`playback_id` and `tokens.playback`; `tokens.thumbnail` and `tokens.storyboard` for the poster and scrubbing previews), or any HLS player with `stream_url`.
4. Tokens work until `expires_at` (a few hours). Ask again for a new one, not before each view.

## Buying a plan

Plans are paid on Stripe's own Checkout page, opened in an in-app browser (`ASWebAuthenticationSession`, Custom Tabs):
1. `POST /studios/{slug}/checkouts` returns the page's `url` and a `return_url`.
2. When the browser reaches `return_url` (with `?session_id=…` when paid, `?cancelled=1` when not), close it. That page just says to go back to the app.
3. `GET /studios/{slug}/checkouts/{id}` records the purchase straight away if Stripe's webhook hasn't yet (it's recorded once either way), and returns the plan and the booking it finished.
4. Bank payments can show `payment_status: unpaid` for a few days; the plan appears when they clear.

Classes, memberships and packs are services used in person, so they aren't Apple or Google in-app purchases. Video is digital content, so **the app plays videos members already have access to, and doesn't sell plans that include videos** (`includes_videos`). In the US the app may link to the studio's plans page on the website instead; elsewhere, check the stores' rules before showing even a link ([decision 33](Decisions)).

## Changes

Version 1 only grows: new endpoints and fields can appear, so ignore ones the app doesn't know. Anything that would break the app goes in `/api/v2`.
