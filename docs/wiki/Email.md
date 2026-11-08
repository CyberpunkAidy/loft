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
- Marketing email (birthdays, win-back) is planned. It will need consent and an unsubscribe link, separate from transactional mail ([Roadmap](Roadmap)).
