# Loft subscription

What studios pay Loft ([decisions 36 and 40](Decisions)). It's separate from what members pay studios, which goes to each studio's own Stripe account ([Payments](Payments)). Loft's subscription is billed on **Loft's own Stripe account** (the platform account, `STRIPE_SECRET`).

## How it works

- **Price:** $49 a month, or $490 a year (two months free), per studio, with every feature and no limits on members, staff, classes or bookings. The [pricing page](https://loftstudio.app/pricing), the home page and the signup page show it.
- **Price lock:** a subscription keeps the price it started at for as long as it continues on the same plan. A new price applies only to subscriptions started, or switched to the other plan, after the change. The pricing page, the Subscription page and the terms (section 4) all promise this, and billing works that way: each subscription stays on its own Stripe price.
- **Free trial:** 30 days from signup, with no card. Staff see **Free trial: N days left** at the top of the admin, amber in the last week.
- **Subscribing:** on **Admin → Subscription**, owners choose **Monthly** or **Yearly**, then **Subscribe** opens Stripe Checkout. Stripe shows the rest of Loft's trial as days free, which isn't the yearly plan's saving, so a line by Checkout's pay button (and a note on the Subscription page) says which is which: *"Your Loft free trial carries on until November 22, so your card isn't charged until then. After that it's $490 a year, which is 2 months free compared with paying monthly."* During the trial the card is saved and first charged when the trial ends; with less than two days of trial left, or after it, it's charged straight away. Checkout accepts promotion codes, made in Stripe under **Product catalog → Coupons**.
- **Managing it:** **Manage billing** opens Stripe's billing portal, where owners change the card, download invoices, switch between monthly and yearly, or cancel. Monthly subscribers also get **Switch to yearly**, which opens the portal straight on choosing a plan; Stripe charges the year at once, less what's left of the month. Cancelling ends the subscription at the end of the month or year already paid for.
- **What the Subscription page shows:** the plan and amount the studio actually pays, from Stripe, with the price lock. A studio on an older price sees that price, not today's.
- **Grace:** when the trial ends without a subscription, the subscription ends, or a payment fails, everything keeps working for 7 more days. Staff see a warning with the date it pauses, and owners get an email.
- **Paused:** after the 7 days:
  - **Staff** see only the Subscription page.
  - **Members** can't book, join waitlists, buy plans or order from the shop, on the website or in the app (error `studio_paused`). The schedule says **Booking paused**, and every studio page carries a notice.
  - **Automations** wait.
  - **Still working:** members can cancel what they booked, and keep their memberships and credits. Memberships on the studio's own Stripe account keep renewing. Check-in kiosks and class reminders carry on.
  - **Nothing is deleted.** Subscribing, or a card that works again, restores everything straight away.
- **Comped:** free for good. Every studio on Loft when subscriptions began was comped. Others can be comped with `loft:billing` ([below](#comps-and-trials)).

### Emails to owners

From "Loft", to every owner of the studio, each once:

| When | Email |
|---|---|
| 3 days before the trial ends | Your Loft trial ends *date* |
| The trial ended, the subscription ended, or a payment failed | Your Loft trial has ended / Your Loft subscription has ended / Your Loft payment didn’t go through, with the date the studio pauses |
| The studio pauses | *Studio* is paused on Loft |

Stripe can also send its own receipts and failed-payment emails from Loft's account (**Settings → Billing → Customer emails**).

## Setting it up (once)

1. **The billing webhook.** In the Stripe Dashboard, in the same mode as `loft-web`'s `STRIPE_SECRET` (test or live), go to **Developers → Webhooks → Add destination** and choose **Your account**, not Connected accounts. It's a separate endpoint from the Connect one ([Stripe](Stripe)), because Stripe sends events on Loft's own account only to endpoints on it.
   - **Payload style:** Snapshot; **API version:** any
   - **URL:** `https://loftstudio.app/stripe/billing-webhook`
   - **Events:**
     - `checkout.session.completed`
     - `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`
     - `invoice.paid`, `invoice.payment_failed`
2. **Its signing secret.** Add it to Render as `STRIPE_BILLING_WEBHOOK_SECRET` on `loft-web` (**Environment**). It's `sync: false` in the Blueprint, so a sync won't add it.
3. **Nothing else.** The Stripe product (**Loft**) and its monthly and yearly prices are created the first time they're needed, and so is the billing portal's setup, including switching between the two prices. Nothing needs setting up under **Settings → Billing → Customer portal**.

Without the webhook, a subscription still shows when the owner comes back from Checkout, and the hourly job catches renewals and failed payments. A cancellation from the billing portal, though, would only show once the month ended.

**Optional extras:**
- **Sales tax:** set up Stripe Tax on Loft's account, then set `LOFT_BILLING_AUTOMATIC_TAX=true` on `loft-web`. Checkout then asks for the studio's address and adds tax.
- **Changing the price:** set `LOFT_MONTHLY_PRICE_CENTS` (for example `5900`) in the `loft-shared` group, which both the website and the hourly emails read. The yearly price follows at ten months unless you set `LOFT_YEARLY_PRICE_CENTS` too (`0` turns the yearly plan off). New subscriptions use the new prices, and existing ones keep theirs. **Don't move existing subscribers to a higher price in Stripe:** the price lock promises they keep theirs. `LOFT_TRIAL_DAYS` and `LOFT_BILLING_GRACE_DAYS` change the trial and the grace period the same way.
- **After changing a price,** the billing portal gets a new setup with the new prices the next time an owner opens it.

## Comps and trials

There's no Loft-wide admin screen. In Render, open **`loft-web` → Shell** and run:

```bash
php artisan loft:billing skyline
```

That shows where the studio stands. The options:

| Command | What it does |
|---|---|
| `php artisan loft:billing skyline --comp --note="Pilot studio"` | Free from now on |
| `php artisan loft:billing skyline --end-comp` | Pays from now on, after a fresh 30-day trial (`--trial-days=0` skips it) |
| `php artisan loft:billing skyline --trial-days=14` | The trial ends 14 days from now: to extend it, or start a new one |

Comping a studio that already pays doesn't cancel its Stripe subscription; the command says so. Cancel it in the Stripe Dashboard.

Closing a studio (its last owner deleting their account, [Accounts and Data](Accounts-and-Data)) cancels its Loft subscription straight away.

## How it's built

- **The `studios` table:**
  - `trial_ends_at`;
  - `comped_at` and `comp_note`;
  - a copy of the Stripe subscription: `billing_customer_id`, `billing_subscription_id`, `billing_status`, `billing_interval` (`month` or `year`), `billing_amount_cents` (what Stripe charges for the plan), `billing_period_ends_at`, `billing_cancels_at`, `billing_payment_failed_at` and `billing_ended_at`.
- **`App\Services\StudioBilling`:**
  - **State:** `state()` works out comped, subscribed, trial, grace or paused (`App\Support\BillingState`), from those columns and the time.
  - **Subscribing:** `checkoutUrl()` (with the interval), `complete()` and `portalUrl()` (with `switch` to open on choosing a plan). `planPrices()` finds or creates today's monthly and yearly prices.
  - **Labels:** `priceLabel()`, `yearlyPriceLabel()`, `yearlySaving()` ("2 months free", or "save $88" when it isn't whole months), `offerLabel()` and `planLabel()` for what a subscribed studio pays.
  - **Staying in step with Stripe:** `sync()` copies a Stripe subscription onto the studio. It's safe to repeat and to receive out of order, and a second subscription never replaces one that's still running. `sweep()` runs hourly and asks Stripe about failing subscriptions and live ones past their renewal date.
  - **Owner emails:** `remind()` runs hourly and sends them (`App\Mail\LoftNotice`), logged in `message_deliveries` with dedupe keys.
- **`App\Services\Stripe\StudioBillingWebhooks`:** `POST /stripe/billing-webhook`. It logs each event in `stripe_events`, fetches the subscription and calls `sync()`.
- **The pause:**
  - `studio.active` (`EnsureStudioActive`) sends every admin page to the Subscription page.
  - `Bookings::assertOpen`, `PlanCheckout::start` and `Shop::checkout` refuse new bookings and purchases.
  - `Automations` skips the studio.
- **The prices:** found by their lookup keys, `loft_studio_monthly_<currency>_<cents>` and `loft_studio_yearly_<currency>_<cents>`, or created on the product `loft_studio`.
- **The billing portal:** one portal configuration per pair of prices, created through the API and cached, with plan switching (`subscription_update`, prorated) between them.
- **Operator command:** `loft:billing` (`App\Console\Commands\StudioBillingCommand`).
- **The pricing page:** `/pricing` (`platform/pricing.blade.php`): a monthly/yearly switch made of two radio buttons and CSS, so it works without JavaScript; the price lock; everything included; card fees and the savings calculator (`platform/_calculator.blade.php`, shared with the home page); a comparison with "often elsewhere" that names no products; questions; and JSON-LD with both offers.
- **Tests:** `tests/Feature/StudioBillingTest.php` and `tests/Feature/PricingPageTest.php`.
