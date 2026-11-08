# Payments

There are three ways money comes in. All of them end the same way: an **entitlement** (a membership or credits) on the member's account.

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
- **Deleted accounts:** if a member deletes their account, the record stays in the studio's books, unlinked from the person ([Accounts and Data](Accounts-and-Data)).
- **Who:** admins and owners only.

## Not supported yet

- **Merchandise and a shared cart:** products, one checkout for several items, a front-desk point of sale with Stripe Terminal. Planned ([Roadmap](Roadmap)).
- **Stripe refunds:** refunds don't take back what they paid for. Remove it by hand.
- **Other payment providers:** PayPal checkout, Square, or Loft collecting money and paying studios out. Deliberately not planned ([Decisions](Decisions)).
