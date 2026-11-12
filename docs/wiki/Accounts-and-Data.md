# Accounts and Data

## Accounts

- **One login everywhere.** A person has one account (email and password) that works at every studio they belong to. Their role at each studio is separate ([Architecture](Architecture#tenancy)).
- **Family members** (`attendees`) belong to one account at one studio. A parent books, pays and signs waivers for their children. Attendees under `minor_age` (default 18) are signed for by the account holder.
- **Password resets** and staff invitations use the same Loft-branded email ([Email](Email)).

## Deleting an account

Members delete their own account under **My account → Profile → Delete account**, after entering their password. It's deleted at every studio (`App\Services\AccountDeletion`), in this order:

1. **Billing stops first.** Renewing memberships are cancelled in each studio's Stripe account, so nobody is charged again. Payments already made aren't refunded, and the form says so. If Stripe can't be reached, nothing is deleted and the member is asked to try again.
2. **The database removes their records:** studio memberships, family members, passes and memberships, and bookings.
3. **Waiver signatures stay** with each studio, unlinked from the account: the names, the version and hash of the exact text, and the drawing ([decision 20](Decisions)).
4. **They're signed out** on every device, including the mobile app (its tokens and push tokens are deleted), and their password-reset tokens are removed.
5. **Seats they held** in upcoming classes go to the waitlist.
6. **Their check-in tags are deleted** with their family members, and their scans in the check-in log are unlinked from them.
7. **Their nightly activity snapshots and churn risk scores are deleted.** Their activity history (`domain_events`) and email log (`message_deliveries`) are unlinked: they hold IDs and facts, no names or addresses, so what's left is anonymous.

What stays:
- **Classes they taught** stay, with no instructor.
- **Payments the studio recorded in person, and every order in the sales ledger,** stay in the studio's books, unlinked from the person.
- **Signed waivers** stay with each studio, unlinked from the person.
- **Payment history** stays in each studio's Stripe account. That data belongs to the studio.

## Studio owners

A studio must always have an owner.
- **Making another owner:** owners can make another staff member an owner at any time (Admin → Staff → **Make owner**). Only owners can do this.
- **Deleting an owner's account:** if the person is the **only** owner of a studio, the delete form asks what happens to it:
  - **Hand it over:** one of its admins or instructors becomes the owner. The studio carries on.
  - **Close it:** members' renewing memberships at that studio are cancelled in Stripe, then the studio is deleted with everything in it (classes, plans, bookings, signed waivers, recorded payments, signature drawings, its logo, and its videos, which are deleted from Mux too). Members keep their Loft accounts and their other studios, and the owner's Stripe account stays theirs. The form tells the owner to **download the signed waivers first** (Admin → Waivers).
- **Imported data:** a CSV upload is kept only until it's imported; drafts nobody imports are deleted after a week. Accounts an import created can be removed by undoing it within 30 days, unless they've been used ([Data Import](Data-Import#data-kept)).
- **Loft subscription:** a closing studio's subscription to Loft is cancelled straight away ([Loft Subscription](Loft-Subscription)).
- **After a close:** the owner lands on the Loft home page. Emails still queued for the closed studio are dropped.

## Terms and privacy

The [Privacy Policy](https://loftstudio.app/privacy) describes this page to members and studios; the [Terms of Service](https://loftstudio.app/terms) (section 15) make Loft the studios' processor for their members' data. When people and studios agreed, and to which version, is recorded ([Legal](Legal)).

## Where personal data lives

| Data | Where |
|---|---|
| Name, email, phone, password hash | `users` |
| Children's names, dates of birth, medical notes, emergency contacts, photo answers | `attendees` (per studio); each photo answer also in `domain_events` |
| Waiver signatures: typed name, IP, browser, drawing | `waiver_signatures`; drawings on the private disk |
| Marketing email consent: when, where, unsubscribed | `studio_users` (per studio) |
| Activity history and the email log | `domain_events`, `message_deliveries`: IDs and facts, no names or addresses |
| Nightly activity snapshots | `member_snapshots` (deleted with the account) |
| Videos watched | `video_views`: which video, who, which day (deleted with the account). Mux sees playback only by video, with no cookies and no member details |
| How a studio found Loft: its owner's answer, where the visit began, its referral code | `studios.heard_from`, `heard_from_detail`, `signup_source`, `referral_code_id` (deleted with the studio). A guest's visit is kept only in their session until they start a studio ([Referrals and Signups](Referrals)) |
| Demo studios and the accounts made in them | Deleted 24 hours after the demo starts, with everything in them. `demo_runs` keeps only a count ([Demo Studio](Demo-Studio)) |
| Card details | Never in Loft; only in Stripe |
| Studio customer records, charges, invoices | The studio's own Stripe account |

## Signed waivers are kept

[Decision 20](Decisions): signed waivers are the studio's liability records.
- **Deleted account:** its signatures stay, unlinked from the account (`attendee_id` and `signed_by_user_id` become empty). Each keeps the attendee's name, the signer's typed name, how they're related (self, guardian or account holder), the waiver version, a SHA-256 hash of the exact text, the IP address and browser, and the drawing.
- **Download:** admins can download every signature as a ZIP (Admin → Waivers). It contains `signatures.csv`, the text of every version and the drawings. Owners take a copy before closing a studio.
