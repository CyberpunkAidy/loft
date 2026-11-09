# Stripe

## How money flows

- **Stripe Connect.** Loft (the platform) has one Stripe account. Each studio connects its own Stripe account, created and onboarded through Stripe's Accounts v2 API. It's set up like the old "Standard" accounts:
  - the studio gets the full Stripe Dashboard;
  - Stripe collects its fees from the studio;
  - Stripe covers losses;
  - the account is created in the platform account's country.
- **Direct charges.** Every studio-level call carries the studio's account in the `Stripe-Account` header, so customers, products, prices, Checkout, subscriptions and invoices all live in the studio's account. Money goes straight to the studio, and the studio manages payouts, refunds and disputes in its own Stripe Dashboard.
- **Platform fee.** `LOFT_PLATFORM_FEE_PERCENT` adds an application fee to every charge. It's `0` today.
- **No Stripe SDK.** `App\Services\Stripe\StripeClient` is a thin REST client.

## Setup checklist

1. Platform account: turn on **Connect** (Settings → Connect).
2. Copy the **secret** key (`sk_test_…` in test mode) into Render as `STRIPE_SECRET`, on two services: `loft-web` → Environment, and `loft-housekeeping` → Environment (the hourly job asks Stripe about shop checkouts and card reader sales nobody finished). The publishable `pk_` key won't work.
3. Create the webhook (Developers → Webhooks → add destination):
   - **Events from:** Connected accounts
   - **Payload style:** Snapshot
   - **API version:** any; the latest is fine
   - **URL:** `https://loftstudio.app/stripe/webhook`
   - **Events:**
     - `checkout.session.completed`
     - `checkout.session.async_payment_succeeded`
     - `checkout.session.async_payment_failed`
     - `checkout.session.expired`
     - `charge.refunded`
     - `invoice.paid`
     - `invoice.payment_failed`
     - `customer.subscription.updated`
     - `customer.subscription.deleted`
     - `account.updated`
     - `account.application.deauthorized`
4. Put the webhook's signing secret into `STRIPE_WEBHOOK_SECRET`.
5. For studios' own Loft subscriptions, add a second webhook for events on **your account**, at `/stripe/billing-webhook`, with its secret in `STRIPE_BILLING_WEBHOOK_SECRET` ([Loft Subscription](Loft-Subscription#setting-it-up-once)).
6. In the studio: Admin → Payments → **Connect Stripe**, and complete Stripe's onboarding.
7. Create a plan (Admin → Plans & pricing), then **Create in Stripe**.
8. Make a test purchase with card `4242 4242 4242 4242`.

Status in November 2026: the platform keys, Connect and the webhook are being set up in test mode. The two `async_payment` events were added to the app on 2026-11-08 and must be added to the webhook as well. `checkout.session.expired` was added on 2026-11-09; until it's on the webhook, abandoned shop checkouts wait for the hourly job, which needs `STRIPE_SECRET` on `loft-housekeeping`.

## API versions

| Calls | Version | Setting |
|---|---|---|
| v1 (customers, products, prices, Checkout, subscriptions, invoices, billing portal, reading account status) | `2024-06-20` | `STRIPE_API_VERSION` |
| v2 (creating studio accounts, onboarding links) | `2026-10-28.endive` | `STRIPE_V2_API_VERSION` |

- **Webhooks can use any version.** Stripe doesn't offer old versions for new webhook destinations, and payload shapes change between versions. So the app reads only the object's ID from each webhook and fetches the object from the API in its pinned version (`StripeWebhooks::process`). Fetching also means events that arrive out of order still act on the latest state.
- **v1 works with v2 accounts.** Stripe documents that v1 endpoints accept v2 account IDs and return v1-shaped objects, so everything except account creation and onboarding stays on v1. v2 accounts still send v1 `account.updated` events to the Connect webhook.

## Webhook processing

`POST /stripe/webhook`:
1. Verifies the signature. Bad signatures get 400.
2. Records the event in `stripe_events`. A duplicate gets 200 and is skipped.
3. Processes the event. A failure gets 500, so Stripe retries; the unprocessed row lets the retry through.

| Event | What the app does |
|---|---|
| `checkout.session.completed` | Grants the plan if paid (or if it's a subscription) and finishes the booking it started from |
| `checkout.session.async_payment_succeeded` | Same, once a delayed payment (bank debit) clears |
| `checkout.session.async_payment_failed` | Emails the member that the payment didn't go through (one-time purchases) |
| `checkout.session.expired` | Releases a shop order the member walked away from and puts its stock back (plan checkouts hold nothing) |
| `charge.refunded` | Records the refund on its sale; a full one takes back what it paid for, except a renewing membership ([Payments](Payments#refunds)) |
| `invoice.paid` | Extends the membership period; restores a past-due membership; grants it if checkout hasn't arrived yet |
| `invoice.payment_failed` | Marks the membership past due (bookings pause) and emails once per lapse |
| `customer.subscription.updated` | Copies status, period end and scheduled cancellation |
| `customer.subscription.deleted` | Cancels the membership |
| `account.updated` | Refreshes the studio's "charges enabled" and "details submitted" |
| `account.application.deauthorized` | Disconnects the studio |

Returning from Checkout (`/{studio}/checkout/complete`) runs the same fulfilment immediately, so members don't wait for the webhook. Both paths are safe to run twice.

## Other behavior

- **Seasonal tuition** bills monthly. After checkout the app sets the subscription's `cancel_at` to the season's end; Checkout can't set it.
- **The billing portal** (cards, invoices, cancel at period end) gets a configuration created in each studio's account on first use.
- **Customers** are created per studio account, one per member, on their first purchase.

## Troubleshooting log

| Symptom | Cause | Fix |
|---|---|---|
| Stripe's webhook form doesn't offer API version `2024-06-20` | New webhook destinations only offer recent versions | Any version works since [#7](https://github.com/CyberpunkAidy/loft-studio/pull/7) |
| "This API call cannot be made with a publishable API key" | `STRIPE_SECRET` held the `pk_` key | Use the `sk_` secret key |
| "Stripe no longer recommends Accounts v1 for new Connect integrations…" | Stripe blocks v1 account creation for new platforms | Accounts v2 since [#8](https://github.com/CyberpunkAidy/loft-studio/pull/8) |
| A pack paid by bank debit never appears | `async_payment_succeeded` wasn't handled | Handled since [#13](https://github.com/CyberpunkAidy/loft-studio/pull/13); add the event to the webhook |
| A refund made in the Stripe Dashboard doesn't show in Sales, and the pack is still usable | `charge.refunded` isn't on the webhook | Add `charge.refunded` to the webhook's events. Refunds made before that aren't sent again: cancel those plans by hand |
| Shop orders or card reader sales stay pending and keep their stock; `loft-housekeeping`'s logs show "Stripe is not configured. Set STRIPE_SECRET." | `STRIPE_SECRET` was only on `loft-web`, so the hourly job couldn't ask Stripe | Add `STRIPE_SECRET` (the same key) to `loft-housekeeping` → Environment. A Blueprint sync won't add it, because it's `sync: false`. Also add `checkout.session.expired` to the webhook |

## Known gaps

- **Payment methods:** these are whatever each studio turns on in its own Stripe Dashboard (Settings → Payment methods). The app doesn't restrict them.
