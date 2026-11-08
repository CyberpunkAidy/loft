# Accounts and Data

## Accounts

- **One login everywhere.** A person has one account (email and password) that works at every studio they belong to. Their role at each studio is separate ([Architecture](Architecture#tenancy)).
- **Family members** (`attendees`) belong to one account at one studio. A parent books, pays and signs waivers for their children. Attendees under `minor_age` (default 18) are signed for by the account holder.
- **Password resets** and staff invitations use the same Loft-branded email ([Email](Email)).

## Deleting an account

Members delete their own account under **My account → Profile → Delete account**, after entering their password. It's deleted at every studio (`App\Services\AccountDeletion`), in this order:

1. **Billing stops first.** Renewing memberships are cancelled in each studio's Stripe account, so nobody is charged again. Payments already made aren't refunded, and the form says so. If Stripe can't be reached, nothing is deleted and the member is asked to try again.
2. **The database removes their records:** studio memberships, family members, passes and memberships, bookings, and waiver signatures.
3. **Signature drawings** are removed from disk.
4. **They're signed out** on every device, and their password-reset tokens are removed.
5. **Seats they held** in upcoming classes go to the waitlist.

What stays:
- **Classes they taught** stay, with no instructor.
- **Payments the studio recorded in person** stay in the studio's books, unlinked from the person.
- **Payment history** stays in each studio's Stripe account. That data belongs to the studio.

## Studio owners

A studio must always have an owner.
- **Making another owner:** owners can make another staff member an owner at any time (Admin → Staff → **Make owner**). Only owners can do this.
- **Deleting an owner's account:** if the person is the **only** owner of a studio, the delete form asks what happens to it:
  - **Hand it over:** one of its admins or instructors becomes the owner. The studio carries on.
  - **Close it:** members' renewing memberships at that studio are cancelled in Stripe, then the studio is deleted with everything in it (classes, plans, bookings, waivers, recorded payments, signature drawings). Members keep their Loft accounts and their other studios, and the owner's Stripe account stays theirs.
- **After a close:** the owner lands on the Loft home page. Emails still queued for the closed studio are dropped.

## Where personal data lives

| Data | Where |
|---|---|
| Name, email, phone, password hash | `users` |
| Children's names, dates of birth, medical notes, emergency contacts | `attendees` (per studio) |
| Waiver signatures: typed name, IP, browser, drawing | `waiver_signatures`; drawings on the private disk |
| Card details | Never in Loft; only in Stripe |
| Studio customer records, charges, invoices | The studio's own Stripe account |

## Signed waivers are kept (decided, being built)

[Decision 20](Decisions), 2026-11-08: signed waivers are kept as the studio's liability records.
- **Deleted account:** its waiver signatures stay with the studio, unlinked from the account, with the signer's and attendee's names and the exact text signed.
- **Closing a studio:** the owner can download every signed waiver first.

**Until roadmap step 1 ships**, deleting an account still deletes its signatures, as described above.
