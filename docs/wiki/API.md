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
| 404 | `not_found` | No such record, or not at this studio |
| 422 | `validation_failed` | `fields`: `{field: [messages]}` |
| 422 | `invalid_credentials` | Wrong email or password |
| 422 | `waiver_required` | Sign a waiver first: `attendee_id`, `waiver_id` |
| 422 | `needs_plan` | No membership or credits cover the class: `plans` they can buy |
| 422 | `full` | The class and its waitlist are full |
| 422 | `closed` | Booking hasn't opened, or has closed |
| 422 | `cancelled` | The class was cancelled |
| 422 | `age` | Too young or too old for the class |
| 422 | `apparatus_invalid`, `apparatus_full` | The chosen apparatus isn't in the class, or has no spots left |
| 409 | `already_booked` | Already in the class |
| 422 | `waiver_not_in_force` | That waiver was replaced or switched off |
| 422 | `checkout_unavailable` | The studio can't take online payments, or the plan isn't for sale |
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
| `GET /studios/{slug}` | Name, time zone, currency, colour, contact email, website, whether it takes online payments, booking policies |
| `GET /studios/{slug}/plans` | What it sells, with prices, labels, credits, season dates and `can_buy_online` |
| `GET /studios/{slug}/sessions` | The schedule: `from` (studio date, default today), `days` (1–62, default 14), `program_id`, `age_group`, `level`. Each class has times, program, instructor, room, price, `spots_left`, waitlist, apparatus spots, `booking_opens_at`; with a token, `mine` lists the member's bookings in it |
| `GET /studios/{slug}/sessions/{id}` | One class. With a member's token, `booking` says for each person on the account whether they can book, and if not, why (`reason` uses the error codes above, with `waiver_id` to sign) |

### Members of the studio

| | | |
|---|---|---|
| `GET /studios/{slug}/bookings` | `when` = `upcoming` (default) or `past` | Their bookings, in class order |
| `POST /studios/{slug}/sessions/{id}/bookings` | `attendee_id`, `apparatus_id`? | `201` booked or waitlisted (`status`, `position`), with a `message`. Idempotent |
| `DELETE /studios/{slug}/bookings/{id}` | | Cancelled, with `credit_refunded` (late cancellations may keep the credit) |
| `GET /studios/{slug}/attendees` | | The people on the account |
| `POST /studios/{slug}/attendees` | `first_name`, `last_name`, `dob`?, `is_self`?, `emergency_contact`?, `medical_notes`? | `201`. Idempotent |
| `PATCH /studios/{slug}/attendees/{id}` | the same fields | |
| `DELETE /studios/{slug}/attendees/{id}` | | `204`: removed from the account (kept in the studio's records) |
| `GET /studios/{slug}/waivers` | | Waivers in force with their text, and for each person: `signed`, `expired`, `needed` |
| `POST /studios/{slug}/attendees/{id}/waiver-signatures` | `waiver_id`, `signed_name`, `agree` (true), `signature_png`? (a data URL) | `201`, with `next_waiver_id` to sign next (or `null`). A waitlist spot waiting on the waiver may go through. Idempotent |
| `GET /studios/{slug}/entitlements` | | Memberships and credits, current ones first |
| `GET /studios/{slug}/orders` | | Purchases, newest first |
| `POST /studios/{slug}/checkouts` | `plan_id`, and to finish a booking once paid: `session_id`, `attendee_id`, `apparatus_id`? | `201` `{id, url, expires_at, return_url}`. Idempotent |
| `GET /studios/{slug}/checkouts/{id}` | | `status`, `payment_status`, `fulfilled`, the `entitlement` and `booking` it made, or `booking_error` |

## Booking, step by step

1. `GET /studios/{slug}/sessions/{id}` with the token: each person's `can_book`, or why not.
2. `POST …/sessions/{id}/bookings` with an `Idempotency-Key`.
3. On `waiver_required`: show the waiver (`GET …/waivers`), sign it (`POST …/attendees/{id}/waiver-signatures`), then book again with a new key.
4. On `needs_plan`: show `error.plans`, then buy one with the class attached (`POST …/checkouts` with `session_id` and `attendee_id`), so the booking finishes once paid.

## Buying a plan

Plans are paid on Stripe's own Checkout page, opened in an in-app browser (`ASWebAuthenticationSession`, Custom Tabs):
1. `POST /studios/{slug}/checkouts` returns the page's `url` and a `return_url`.
2. When the browser reaches `return_url` (with `?session_id=…` when paid, `?cancelled=1` when not), close it. That page just says to go back to the app.
3. `GET /studios/{slug}/checkouts/{id}` records the purchase straight away if Stripe's webhook hasn't yet (it's recorded once either way), and returns the plan and the booking it finished.
4. Bank payments can show `payment_status: unpaid` for a few days; the plan appears when they clear.

Classes, memberships and packs are services used in person, so they aren't Apple or Google in-app purchases. Selling on-demand video would be: check the stores' current rules first ([Roadmap](Roadmap#api-for-a-native-mobile-app)).

## Changes

Version 1 only grows: new endpoints and fields can appear, so ignore ones the app doesn't know. Anything that would break the app goes in `/api/v2`.
