# Email

## Sending

- **Provider:** [Resend](https://resend.com), through Laravel's built-in `resend` mailer (`resend/resend-php`).
- **Sending domain:** `mail.loftstudio.app`, verified in Resend. The site's own domain stays free for other mail.
- **From:** `hello@mail.loftstudio.app` (`MAIL_FROM_ADDRESS`).
  - Studio emails use the studio's name (or its "email from" name) as the display name, and set **Reply-To** to the studio's contact email, so replies reach the studio.
  - Platform emails come from Loft.
- **Turning it on:** set `MAIL_MAILER=resend` and `RESEND_API_KEY` in Render (the `loft-shared` group, dashboard only).
  - Without `MAIL_MAILER`, the app writes emails to the log instead.
  - The log mailer writes at debug level, so with `LOG_LEVEL=info` those emails don't appear.
- **Queued:** studio emails go through the `loft-worker` service. If emails stop, check that the worker is live, and check the `failed_jobs` table.

## The delivery log

Every studio email is logged in `message_deliveries` by `App\Services\Messages`:
- **Once only:** each send has a dedupe key, such as `booked:booking:42`, `reminder:booking:42` or `payment_failed:entitlement:7:2026-11-08`. A second send with the same key is skipped, so a retried webhook or an extra housekeeping run can't send twice.
- **Status:** `queued`, then `sent` (with Resend's message ID) when the mailer sends it, or `failed` if the queue gives up. Marketing email to someone without consent is logged as `suppressed` and not sent.
- **What's kept:** the kind, category, status and times, not the address or the content. Deleting an account unlinks its rows.
- **Where staff see it:** each member's page in the admin lists their recent emails and their marketing consent.

## Marketing email and consent

Transactional email (bookings, receipts, reminders, payment problems) always goes out. Marketing email (news, offers, and the planned birthday and win-back messages) needs consent:
- **Opting in:** an unticked box on the join form ("Email me news and offers from …"), or **My account → Profile**. Consent is per studio, recorded with when and where (`studio_users.email_marketing_consent_*`), and each change is a domain event.
- **Opting out:** the profile box, or the **Unsubscribe** link in every marketing email. The link is a signed URL (`/{studio}/unsubscribe/{member}`) that works without signing in.
- **One-click unsubscribe:** marketing emails carry `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` (RFC 8058), which Gmail and Yahoo expect from bulk senders. Mail apps POST to the same signed URL.
- **Sending marketing email:** `Messages::email($user, $message, $dedupeKey, 'marketing')`. It checks consent, adds the footer line and the headers.
- **SMS:** consent for marketing texts (TCPA) will be collected when SMS is added.

## Invitations and renewals for imported members

Members imported from another system ([Data Import](Data-Import)) hear nothing until staff send invitations. Both emails are studio-branded and transactional:

| Email | Sent | Once per |
|---|---|---|
| Invitation (“*Studio* has a new booking system”) | When staff press Send invitations, or remind those who haven't come in | press (reminders three days apart) |
| Keep your *plan* going | A week before an imported membership ends, to invited members who haven't started it in Loft | membership |

## Automations

Admin → Automations: emails a studio sets up once that then go out by themselves (`App\Services\Automations`). Each is off until the studio turns it on.

| Automation | Sent | Once per |
|---|---|---|
| After a first class | A set number of hours (default 3) after someone's first class at the studio is checked in | attendee |
| Birthday | On the birthday, from 10 AM studio time; children's birthdays go to the parent. Leap-day birthdays are on February 28 in other years | attendee, per year |
| We miss you | A set number of days (default 30) after a member's last class, if they have nothing booked. Only members who lapsed in the past week are included, so turning it on doesn't email everyone who ever left | absence: coming back and lapsing again sends it again |
| At risk of leaving | From 10 AM, the day a member's churn risk turns **high** ([churn scores](Architecture#churn-scores)). Not within two weeks of a “We miss you” email | member, at most once in 30 days |

- **Marketing:** every automation counts as marketing ([decision 23](Decisions)). It goes only to members who opted in, with the unsubscribe link and headers. Members who didn't opt in get nothing, and the delivery log shows it as "not sent".
- **The text:** a subject and plain-text message with placeholders (`{first_name}`, `{attendee_first_name}`, `{who}`, `{studio}`, `{class}`, `{days}`, depending on the automation; unknown ones are refused). Blank lines separate paragraphs, and a button to the schedule is added. The page previews the saved version with sample names, and **Send me a test** emails it to the admin.
- **How they run:**
  - **After a first class:** from the outbox. `AutomationTriggers`, the first registered handler, sees `booking.attended` with `first_visit` and queues `SendAutomatedMessage` for later. When it runs, it checks the automation is still on and the member still there.
  - **Birthday and we miss you:** checked by the hourly housekeeping run.
  - Each send has a dedupe key (such as `automation:4:birthday:attendee:12:2026`), so it goes out once however often these run. The delivery log records which automation sent it.
- **Text messages:** the same automations will send SMS once a provider is set up. In the US that needs A2P 10DLC brand and campaign registration (through Twilio or similar), which takes weeks, so start it early.

## Templates

| File | Used for |
|---|---|
| `resources/views/mail/layout.blade.php` | The shell every HTML email uses |
| `mail/studio-message` (+ `-text`) | Every studio email (`App\Mail\StudioMessage`) |
| `mail/loft-notice` (+ `-text`) | Emails from Loft to a studio's owners about its Loft subscription (`App\Mail\LoftNotice`; [Loft Subscription](Loft-Subscription#emails-to-owners)) |
| `mail/password` (+ `-text`) | Password resets and staff invitations (`ResetPassword::toMailUsing` in `AppServiceProvider`) |
| `components/mail/button`, `components/mail/headline` | Shared pieces |

- **Look:** styled like the site: warm off-white background, a white rounded card with a color strip, Bricolage Grotesque headings, pill buttons.
- **Studio emails:** the studio's badge, name and color, and "Powered by loft" at the bottom.
- **Built for email apps:**
  - Tables and inline styles, so Gmail and Outlook render it like Apple Mail.
  - The button puts its color on the table cell, so Outlook shows it.
  - The logo is a PNG (`public/images/loft-logo-email.png`), because email apps don't show SVG.
- **Inbox preview:** each email sets the line shown under the subject (usually its first sentence).
- **Fonts:** the web fonts load only where the email app allows it, such as Apple Mail. For that, Apache sends `Access-Control-Allow-Origin: *` on `.woff2` files, and CI checks it. Other apps use the system font.
- **Readable colors:** button text and links use the studio's readable colors ([Design System](Design-System)).
- **Plain text:** every email has a plain-text version too.
- **Class details:** booking, waitlist and reminder emails list the class, when, and who. For someone booked online they add **Where: Online**, and that the link to join is in their account from 15 minutes before class. The meeting link itself is never emailed, so a forwarded email doesn't let anyone in.

## Password and invitation email

- **One email, two uses:** the same email serves resets and new staff accounts (an admin adds someone who has no account yet).
- **Subject:** "Choose your Loft password".
- **Content:** one button, a 60-minute expiry (`auth.passwords.users.expire`) and a backup link.

## Previewing

Render an email in `php artisan tinker`, for example:

```php
(new App\Mail\StudioMessage($studio, 'Test', ['Hello'], [], [], url('/'), 'Open'))->render();
```

Then open the HTML in a browser.

## Deliverability notes

- DMARC is `p=none` (monitoring). Once Resend's reports look clean, consider `p=quarantine`.
- Marketing email goes out only with consent, with an unsubscribe link and one-click unsubscribe headers ([above](#marketing-email-and-consent)). The automations that send it (birthdays, win-back) are [Roadmap](Roadmap) step 4.
