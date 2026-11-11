# Referrals and signups

How studios find Loft, and how they bring each other ([decision 43](Decisions)).

## How did you hear about Loft?

The form for starting a studio asks **"How did you hear about Loft?"**. It's required, with an optional box for details. The answers:

| Saved as | Shown as |
|---|---|
| `search` | Searching online |
| `instagram` | Instagram |
| `facebook` | Facebook |
| `social` | Another social site (TikTok, YouTube…) |
| `studio` | Another studio owner |
| `instructor` | An instructor or teacher |
| `group` | Reddit or an online group |
| `review` | A review or comparison site |
| `event` | An event, workshop or competition |
| `loft` | A message from Loft |
| `other` | Somewhere else |

They're stored on `studios.heard_from` and `heard_from_detail`. The list is `App\Support\SignupSource::HEARD_FROM`; add answers at the end, and don't rename the saved keys.

### Where the visit began

The first page a guest opens in a visit records, in their session only:
- **Campaign tags:** `utm_source`, `utm_medium`, `utm_campaign`, `utm_content` and `utm_term`.
- **The site that linked to Loft:** just its name, like `l.instagram.com`.
- **The page they landed on.**

If they start a studio in that visit, it's saved on `studios.signup_source`. Nothing is kept for visitors who don't sign up, and there's no tracking cookie.

**Tag the links you post** so you can tell your channels apart. For example:
- `loftstudio.app/?utm_source=instagram&utm_medium=bio` for your Instagram bio;
- `loftstudio.app/pricing?utm_source=newsletter&utm_campaign=december` for an email.

## Referral codes

Every studio has a code made from its address, like `SKYLINE` for `skyline-aerial`. If a code is taken, it gets a number: `SKYLINE2`. Owners and admins find their link and code under **Admin → Subscription → Give a month, get a month**, with a Copy button and the studios they've brought in.

- **The link** `loftstudio.app/r/SKYLINE` goes to the home page. It keeps the code in a cookie for 30 days, and every page of the Loft site shows "Skyline Aerial Arts invited you to Loft: your first 60 days are free."
- **On the signup form,** the code is filled in under **Have a referral code?**, and the answer is preset to "Another studio owner".
- **Typing a code works too.** Any case and spacing are fine, and so is pasting the whole link.
- **Refused codes:** an unknown or turned-off code is refused. So is a studio's own code when one of its owners or admins starts another studio. A member or instructor of that studio opening their own is a real referral and is accepted.

### The rewards

| Who | What | When |
|---|---|---|
| **The new studio** | 30 more days of free trial: 60 in all | At signup |
| **The studio whose code it was** | A credit worth one month of its plan on its Loft bill: its locked monthly price, or a twelfth of a yearly one | When the new studio first pays Loft: an `invoice.paid` webhook for more than $0 (the $0 invoice that starts a trial doesn't count) |
| …if it hasn't subscribed yet | 30 more days of its own free trial, from today if it had run out | The same |
| …if Loft is free for it (comped) | Nothing more | |
| **A partner** | Nothing automatic. Reward them yourself; `loft:signups` shows who they brought in | |

- **How a credit works:** it's a negative balance on the studio's Stripe customer, so Stripe takes it off its next invoices.
- **Once only:** each referred studio earns its referrer one reward. A retried webhook doesn't credit twice: the Stripe call has an idempotency key, and the reward is recorded in the same transaction. If Stripe errors, nothing is recorded and Stripe's retry tries again.
- **Thank-you email:** the referrer's owners get one ("You've earned a free month of Loft") with a link to their referrals.
- **What's recorded:** the referred studio stores `referral_rewarded_at`, `referral_reward` (`credit`, `trial`, `none` or `partner`) and `referral_reward_cents`.

`LOFT_REFERRAL_BONUS_DAYS` (default `30`) sets both the new studio's extra days and the trial days a referrer earns. **`0` turns referral rewards off:** codes are still accepted and recorded for the report, but nobody gets extra days or credit, and the invite, the signup hint, the Subscription card and the terms' "Referrals" paragraph disappear. The terms (section 4, "Referrals") describe all of this; change them with it.

## Partner codes

For instructors, partners or an event: anyone who recommends Loft but isn't a studio. Run these in Render (**`loft-web` → Shell**):

| Command | What it does |
|---|---|
| `php artisan loft:partner-code MAYA --name="Maya (aerial instructor)"` | Makes the code and prints its link |
| `php artisan loft:partner-code` | Lists partner codes, how many studios each brought, and whether they work |
| `php artisan loft:partner-code MAYA --disable` | Stops a code working (a studio's too, if it's being misused) |
| `php artisan loft:partner-code MAYA --enable` | Lets it work again |

Codes are 3 to 20 letters and numbers.

## The report

```bash
php artisan loft:signups
```

It covers studios started in the last 30 days (`--days=90` for longer), in three parts:
- **How they heard about Loft:** studios and how many have subscribed, for each answer.
- **Referral codes:** studios, subscribed and rewarded, for each code.
- **Each studio:** when it started, its answer and detail, its code, where its visit began (for example `instagram / bio (launch) → /pricing`), and where it stands now (`trial`, `subscribed`, `comped`, `grace` or `paused`).

"Subscribed" means a live Stripe subscription, including one still in its free trial.

## Where it lives

| What | Where |
|---|---|
| Codes, rewards and the email | `App\Services\Referrals`, `App\Models\ReferralCode` (`referral_codes`) |
| The question and where visits begin | `App\Support\SignupSource`, `App\Http\Middleware\RememberSignupSource` (on every web route) |
| The link | `/r/{code}` → `PlatformController::referral` |
| The signup form | `Auth\StudioSignupController`, `auth/signup.blade.php` |
| The invite on Loft's pages | `components/referral-invite.blade.php`, in `layouts/platform` |
| The owner's card | `admin/subscription.blade.php` (`#refer`) |
| The reward | `StudioBillingWebhooks` (on `invoice.paid`) → `Referrals::reward` |
| Commands | `loft:partner-code`, `loft:signups` |
| Tests | `tests/Feature/ReferralsTest.php` |
