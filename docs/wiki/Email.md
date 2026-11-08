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

## Templates

| File | Used for |
|---|---|
| `resources/views/mail/layout.blade.php` | The shell every HTML email uses |
| `mail/studio-message` (+ `-text`) | Every studio email (`App\Mail\StudioMessage`) |
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
