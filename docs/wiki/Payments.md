# Payments

There are three ways money comes in. All of them end the same way: an **entitlement** (a membership or credits) on the member's account, and an **order** in the ledger ([below](#the-orders-ledger)).

## 1. Online, through Stripe Checkout

- **How it works:** a member picks a plan on the Plans page, or while booking, and pays on Stripe's hosted Checkout in the studio's own Stripe account ([Stripe](Stripe)).
- **Plan kinds:**
  - **Membership** (`subscription`): billed monthly, optionally limited to N classes a week.
  - **Seasonal tuition** (`season`): billed monthly until the season ends, with an optional registration fee.
  - **Class pack** (`pack`): N credits, valid for a set number of days.
  - **Drop-in** (`dropin`): one credit.
- **Fulfilment:** the plan is granted when Checkout completes, through the return page and the webhook, whichever arrives first. If the member was booking a class, the booking completes too.
- **Members manage their own billing** (cards, invoices, cancelling at the end of a period) in Stripe's billing portal, from Plans & billing.

## 2. Delayed payments (bank debit and similar)

- **Who controls payment methods:** each studio chooses them in its Stripe Dashboard (Settings → Payment methods): cards, Apple Pay, Google Pay, Link, bank debit (ACH) and others.
- **What happens with a slow method:** some methods take days to clear. For packs and drop-ins:
  1. The member finishes checkout and sees "Your payment is processing. Bank payments can take a few business days; we'll email you when it goes through."
  2. When it clears, `checkout.session.async_payment_succeeded` grants the plan and finishes the booking (if there was one), and the member gets the purchase email.
  3. If it fails, `checkout.session.async_payment_failed` sends "Your payment didn't go through" with a **Try again** link, mentioning the class if they were booking one.
- **Memberships** report the same outcomes through `invoice.paid` and `invoice.payment_failed`.

## 3. Payments taken in person

At the front desk (cash, a check, a card terminal, a bank transfer, a payment app such as Venmo or Zelle) or as a comp. On a member's admin page, **Add a plan** asks for:

| Field | Notes |
|---|---|
| Plan | Any of the studio's plans |
| Payment | No charge (comp), cash, check, card in person, bank transfer, payment app or other |
| Amount | Paid methods only; blank means the plan's price |
| Received on | Paid methods only; defaults to today in the studio's time zone |
| Note | Paid methods only; for example a check number |
| Valid until | Optional; overrides the defaults below |

- **How long it lasts:** a membership paid in person runs **one month** from the received date (to the end of that day, studio time). Packs follow their validity. Seasonal tuition ends with its season. Comps don't end, as before.
- **Receipt:** for paid methods the member gets the purchase email, with **Paid** (amount and method) and **Date** rows.
- **Record:** each one is saved in `payments` with the amount, currency, method, date, note and who recorded it (`App\Services\OfflinePayments`).
- **Void:** for mistakes. The record stays, marked void, with who voided it and when, and the plan it granted is cancelled. Comps are recorded too, so they can be voided as well.
- **Reporting:** the member's page lists their recorded payments. **Admin → Payments** lists every record, with the total taken in person this month (voids and comps excluded).
- **Deleted accounts:** if a member deletes their account, the record stays in the studio's books, unlinked from the person ([Accounts and Data](Accounts-and-Data)). Signed waivers are kept the same way.
- **Who:** admins and owners only.

## The orders ledger

Every sale is an **order** with **items** (`orders`, `order_items`, `App\Services\Orders`), however it was paid ([decision 25](Decisions)):

| Sale | Order |
|---|---|
| One-time online checkout (pack, drop-in) | One per paid Checkout Session, with what Stripe charged after promotion codes and tax |
| Membership or seasonal tuition | One per paid invoice: the first payment (with a season's registration fee as its own item) and every renewal |
| Taken in person, or comped | One per recorded payment (comps at no charge), dated the day received (noon, studio time, for earlier days). Voiding the payment voids the order |

- **Numbers:** per studio, from #1001.
- **Once each:** orders are keyed on the Stripe Checkout Session or invoice ID, so retried webhooks and the checkout return page can't record a sale twice.
- **History:** each order records an `order.paid` domain event (`order.voided` when voided), shown in the member's activity.
- **Admin → Sales:** a month at a time (studio time), with total, online and in-person sales, the number of sales and comps, every order with its items, how it was paid (online ones link to the payment in the studio's Stripe Dashboard), and **Download CSV** for the books. Voided sales are listed but left out of totals.
- **Elsewhere:** the dashboard shows sales this month; a member's admin page lists their purchases (in-person ones can be voided there); members see their purchases under **Plans & billing**.
- **History before the ledger:** payments recorded in person before November 2026 were copied in as orders. Earlier online sales weren't (their amounts are in Stripe, not Loft), so the online history starts with the ledger.

## Not supported yet

- **Merchandise and a shared cart:** products, one checkout for several items, a front-desk point of sale with Stripe Terminal. Planned next, on top of the orders ledger ([Roadmap](Roadmap)).
- **Stripe refunds:** refunds don't take back what they paid for, and don't change the order. Remove the plan by hand.
- **Other payment providers:** PayPal checkout, Square, or Loft collecting money and paying studios out. Deliberately not planned ([Decisions](Decisions)).
