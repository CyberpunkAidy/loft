# Payments

There are three ways money comes in, plus merchandise: sold at the front-desk [point of sale](#4-the-point-of-sale) or in the [online shop](#5-the-online-shop). All of them end the same way: an **entitlement** (a membership or credits) on the member's account, and an **order** in the ledger ([below](#the-orders-ledger)).

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
- **Reporting:** the member's page lists their recorded payments. **Admin → Payments** lists everything taken in person (these records and point-of-sale sales), with this month's total (voids and comps excluded).
- **Deleted accounts:** if a member deletes their account, the record stays in the studio's books, unlinked from the person ([Accounts and Data](Accounts-and-Data)). Signed waivers are kept the same way.
- **Who:** admins and owners only.

## 4. The point of sale

Admin → **Point of sale** is the front-desk till (`App\Services\PointOfSale`), for admins and owners:
- **What it sells:** products (Admin → **Products**: each with variants such as sizes, each variant with its own stock and optionally its own price) and plans, in one cart.
- **Who it's for:** a member (found by name or email), or a **walk-in** with no account. Plans need a member.
- **Paying:** cash, check, card on the studio's own terminal, transfer, payment app, other, or comp, with an optional note. Card readers connected to Stripe come later.
- **Sales tax:** the studio's rate (Settings → Merchandise, to three decimals, such as 8.875%) is added to products marked taxable. Plans aren't taxed. The studio is responsible for the right rate.
- **What happens:** in one transaction, stock is taken (it never goes below zero: if another sale took the last one, the sale is refused), plans are granted as if paid in person, and one order records everything, with tax per line. The member gets a receipt email; the till shows a printable receipt.
- **Voiding:** from the receipt, Sales or the member's page. The order is marked void, plans it added are cancelled, and products go back into stock.
- **The cart** lives in the staff member's session, one per studio. Every button is a plain form, so it works without JavaScript.

## 5. The online shop

**Shop** on the studio site (`/{studio}/shop`, `App\Services\Shop`) sells the same products online, to pick up at the front desk ([decision 27](Decisions)). The link appears once the studio has a product for sale:
- **Browsing and the cart:** anyone can browse and fill a cart (kept in their browser session). Checking out needs a member account; signing in comes back to the cart. Sold-out variants can't be chosen, and low stock is shown.
- **Checkout:** Stripe Checkout on the studio's connected account, one line per product with the studio's sales tax rate on taxable ones (mirrored as a Stripe Tax Rate on the studio's account, made once per rate). Plans aren't sold here: they have their own Checkout on the Plans page.
- **Holding stock:** starting checkout takes the stock (never below zero, as at the till) and creates a **pending** order with no number yet. The Checkout Session expires after 30 minutes, Stripe's shortest. Coming back from Checkout without paying closes the session and puts the stock back; so does starting another checkout.
- **Paid:** the webhook (or the return page, whichever comes first) gives the order its number and Stripe's amounts, and emails a receipt telling the member to pick it up. It waits under **Admin → Sales → To pick up** (also on the dashboard) until staff mark it **picked up**. Members see "Ready to pick up" under Plans & billing.
- **Not paid:** an abandoned or expired checkout, or a bank payment that fails later, releases the order (expired or failed) and restocks. A failed bank payment emails the member that the order was cancelled.
- **Missed webhooks:** the hourly housekeeping job asks Stripe about any pending order whose Checkout should have finished: paid ones are fulfilled, expired ones released, failed bank payments released; ones still clearing are left. No extra webhook events are needed.
- **Refunds:** in the Stripe Dashboard, as for other online sales; restock by hand in Products.

## The orders ledger

Every sale is an **order** with **items** (`orders`, `order_items`, `App\Services\Orders`), however it was paid ([decision 25](Decisions)):

| Sale | Order |
|---|---|
| One-time online checkout (pack, drop-in) | One per paid Checkout Session, with what Stripe charged after promotion codes and tax |
| Membership or seasonal tuition | One per paid invoice: the first payment (with a season's registration fee as its own item) and every renewal |
| Taken in person, or comped | One per recorded payment (comps at no charge), dated the day received (noon, studio time, for earlier days). Voiding the payment voids the order |
| Point of sale | One per sale, with an item per product variant (with its tax) and per plan; a walk-in has no account on it |
| Online shop | One per paid shop Checkout, with an item per product variant (with its tax), waiting to be picked up. Pending until paid: it has no number and isn't counted anywhere |

- **Numbers:** per studio, from #1001, given when paid, so paid orders are numbered without gaps.
- **Once each:** orders are keyed on the Stripe Checkout Session or invoice ID, so retried webhooks and the checkout return page can't record a sale twice.
- **History:** each order records an `order.paid` domain event (`order.voided` when voided), shown in the member's activity.
- **Admin → Sales:** a month at a time (studio time), with total, online and in-person sales, the number of sales and comps, every order with its items, how it was paid (online ones link to the payment in the studio's Stripe Dashboard), and **Download CSV** for the books. Voided sales are listed but left out of totals.
- **Elsewhere:** the dashboard shows sales this month; a member's admin page lists their purchases (in-person ones can be voided there); members see their purchases under **Plans & billing**.
- **History before the ledger:** payments recorded in person before November 2026 were copied in as orders. Earlier online sales weren't (their amounts are in Stripe, not Loft), so the online history starts with the ledger.

## Not supported yet

- **Card readers:** Stripe Terminal at the point of sale. Planned next ([Roadmap](Roadmap)).
- **Shipping:** shop orders are picked up at the studio.
- **Stripe refunds:** refunds don't take back what they paid for, and don't change the order. Remove the plan by hand.
- **Other payment providers:** PayPal checkout, Square, or Loft collecting money and paying studios out. Deliberately not planned ([Decisions](Decisions)).
