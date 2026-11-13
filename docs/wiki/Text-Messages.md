# Text messages

Studios can text members from their own number ([decision 48](Decisions)): class reminders, a spot opening on the waitlist, a class the studio cancelled, and automations by text. Each studio connects its own Twilio account ([decision 24](Decisions)), so the studio owns the number, registers it with the carriers and pays Twilio for its texts. Loft charges nothing extra.

Texts go only to members who opted in at that studio. Booking texts and marketing texts are separate choices, as the TCPA treats them.

## Setting it up (the studio)

**Admin → Text messages** (admins and owners):

1. **Make a Twilio account** and add a payment method. A trial account can only text numbers verified in it.
2. **Buy a local number** that can send SMS.
3. **Register it for US texting (A2P 10DLC):** the business as a brand, then a campaign, under Messaging → Regulatory compliance. Carriers block most texts from unregistered numbers, and approval takes days to weeks, so start early. The page lists what to fill in (below).
4. **Paste the Account SID and Auth Token** from the Twilio console's home page. Loft checks them with Twilio and lists the account's numbers that can text. With one number it's taken straight away; otherwise the studio chooses.

Choosing a number points its incoming texts at Loft (its **SmsUrl**), so Loft hears STOP, START and replies. If the number is in a Messaging Service set to send its own webhook, set its incoming messages to "Defer to sender's webhook" instead.

Once connected, the page has:

| Part | What it does |
|---|---|
| Texts are on | The number, the account (masked), **Open Twilio console**, **Disconnect** (Loft forgets the keys; members' choices are kept) |
| This month | Texts sent, delivered and not delivered; how many members get booking texts and news and offers by text; how many replied STOP |
| What goes by text | **Class reminders** (the studio's reminder time, from Settings), **A spot opened on the waitlist**, **A class you cancelled**, each on by default, with what the text says |
| Send a test | Any mobile number (the admin's own by default) |
| Problems | The last five texts that weren't delivered, with why in plain words ("30034: Your number isn't registered for US texting yet (A2P 10DLC)") |
| What to put in your A2P 10DLC registration | The use case, campaign description, how members opt in (with the profile URL and the wording beside the boxes), the opt-in confirmation, a HELP message to set in Twilio, keywords and sample messages, filled in with the studio's name |

The demo studio can't connect Twilio, and never texts.

## Members opting in

| Where | What |
|---|---|
| Joining, on the website | An unticked box: **Text me class reminders and booking updates from {Studio}**. Ticking it needs a mobile number |
| My account → Profile | Both boxes: booking updates, and **Text me news and offers from {Studio}** |
| The app | Account → **Text messages**: the number and both boxes. Signing up in the app has the booking-updates box |

- **The wording beside the boxes:** "To your mobile number, from (303) 555-0100. Message frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out. You don't need texts to book or buy anything." with a link to the [Privacy Policy](https://loftstudio.app/privacy#texts).
- **Confirmation:** ticking a box sends a text: "{Studio}: You'll now get texts about your classes (reminders, waitlist spots, cancellations). Msg frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out."
- **The number is the account's**, shared by every studio. Changing it turns texts off at every studio until the member ticks the boxes again, since consent is for a number.
- **Staff can't opt anyone in.** The member page shows what each member chose and when.

## STOP, START, HELP and replies

| A member texts | What happens |
|---|---|
| STOP (or STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT, REVOKE, OPTOUT) | Twilio blocks the studio's texts to them and answers. Loft turns off both kinds for every account at the studio with that number. Their profile says to text START to get texts again, instead of the boxes |
| START (or UNSTOP, YES) | Twilio unblocks them. Loft turns booking texts back on; news and offers need the box ticked again |
| HELP or INFO | Twilio answers (set its HELP reply to the one on the Text messages page) |
| Anything else | Emailed to the studio's owners and admins: "Text from Priya Shah", with the message. Replying to the email answers the member by email. At most 10 an hour from one number |

If Twilio refuses a text because the member had replied STOP (error 21610), Loft marks them stopped too.

## What's texted

| Text | When | To |
|---|---|---|
| Class reminder | With the reminder email, by the hourly housekeeping run | Members who chose booking texts |
| A spot opened | When the waitlist moves someone in | Members who chose booking texts |
| Class cancelled | When the studio cancels a class | Members who chose booking texts |
| Automations | Each automation is sent by email, text or both ([Email](Email#automations)) | Members who chose news and offers by text |

Booking texts go as well as the email, never instead of it. A child's booking texts the parent's account, like the email.

Examples:
- "Skyline Aerial: Reminder: Maya has Kids Aerial Silks tomorrow at 4:30 PM. Your classes: https://loftstudio.app/skyline/account"
- "Skyline Aerial: A spot opened, so Maya is in Kids Aerial Silks on Mon, Nov 16 at 4:30 PM. Can't make it? Please cancel so someone else can have the spot: …"
- "Skyline Aerial: Sorry, Kids Aerial Silks on Mon, Nov 16 at 4:30 PM is cancelled (Maya was booked). Any credit used is back in your account. Find another class: …"

Rules for every text:
- **The studio's name first**, so members know who it's from.
- **Marketing texts end with "Reply STOP to opt out."**
- **Marketing texts go out between 9 AM and 8 PM studio time;** one due later waits until 9 AM. Booking texts go when they're due.
- **Plain characters:** curly quotes and dashes become straight ones, so a text stays in the GSM alphabet (160 characters a segment, not 70).
- **Checked again when sent:** a text queued for later isn't sent if the member has since replied STOP, or the studio disconnected.

## Automations by text

Each automation chooses **Email**, **Text message**, or **Email and text** (Admin → Automations → an automation). The text version is its own short message, up to 250 characters, with the same placeholders plus `{link}` (the schedule). The preview shows the text as it arrives. **Send me a test** texts the admin's own number too, if their profile has one. Without Twilio connected, an automation can't be switched to text, and one that texts says it isn't going out.

## How it's stored

| Where | What |
|---|---|
| `studios.twilio_account_sid`, `twilio_auth_token` | The studio's Twilio keys. The token is encrypted with `APP_KEY` and never shown again |
| `studios.sms_from`, `sms_number_sid`, `sms_connected_at` | The number texts come from (E.164) and its Twilio id |
| `studios.settings`: `text_reminders`, `text_waitlist`, `text_cancellations` | What goes by text (all on by default) |
| `users.phone_e164` | The account's phone as `+13035550142`, set whenever `phone` is. Null if it isn't a number Loft could text. Numbers without a country code are taken as US or Canadian |
| `studio_users.sms_updates_at`, `sms_updates_source` | Agreed to booking texts: when, and where (`join`, `profile`, `app`, `reply_start`) |
| `studio_users.sms_marketing_at`, `sms_marketing_source` | Agreed to news and offers by text |
| `studio_users.sms_stopped_at` | Replied STOP (or Twilio said so). Cleared by START, or a new number |
| `automations.channel`, `sms_body` | `email`, `sms` or `both`, and the text version |
| `message_deliveries` with `channel` `sms` | Every text: kind, category, `queued`, then `sent` (Twilio has it) and `delivered`, or `failed` with the reason, or `suppressed` (a marketing text to someone who didn't opt in). The number and the words aren't kept |

Events: `consent.sms_updates_granted` and `_withdrawn`, `consent.sms_marketing_granted` and `_withdrawn` (with `source`, including `phone_changed`), `consent.sms_stopped` (`reply_stop` or `carrier`) and `consent.sms_started`. They show on the member page's Activity.

## Twilio's requests to Loft

| URL | What |
|---|---|
| `POST /twilio/{studio id}/incoming` | Texts to the studio's number. Answers with an empty TwiML `<Response>` |
| `POST /twilio/{studio id}/status?delivery={id}` | Each text's progress (`StatusCallback`): `delivered`, or `failed`/`undelivered` with `ErrorCode` |

Both check `X-Twilio-Signature` (HMAC-SHA1 of the URL and parameters, with the studio's Auth Token) against the URL Loft gave Twilio, built from `APP_URL`. These routes skip CSRF and Laravel's trimming of input, which would change what was signed.

## In the API

- `GET /studios/{slug}`: `texts` is `{from}` when the studio texts, or `null`.
- `GET /studios/{slug}/texts`: `available`, `from`, `phone`, `phone_textable`, `updates`, `marketing`, `stopped_at`, the box `labels`, the `disclosure` and `privacy_url`.
- `PATCH /studios/{slug}/texts`: `updates`, `marketing` and optionally `phone`. Ticking either needs a number Loft can text. A newly ticked box sends the confirmation text. Errors: `texts_unavailable` (422), `texts_stopped` (409, with `from`).
- `POST /studios/{slug}/register` takes `text_updates`.

See [API](API).

## Where it lives

| What | Where |
|---|---|
| Sending, consent checks, quiet hours, error wording | `App\Services\Texts`, `App\Jobs\SendText` |
| Consent: boxes, STOP, START, a new number | `App\Services\Consent` (`setTexts`, `textsStopped`, `textsStarted`, `phoneChanged`) |
| Twilio's API and signatures | `App\Services\Twilio\TwilioClient`, `TwilioSignature` |
| Booking texts | `App\Listeners\StudioTexts`; reminders in `Housekeeping` |
| Twilio's requests | `App\Http\Controllers\TwilioWebhookController`, `App\Mail\TextReply` |
| Admin page | `Admin\TextsController`, `admin/texts.blade.php` |
| Members | `studio/_text-consent.blade.php` (profile and join), `Api\V1\TextsController` |
| App | `app/studio/[slug]/texts.tsx`, `src/lib/texts.ts` |
| Phone numbers | `App\Support\Phone` |
| Tests | `tests/Feature/TextMessagesTest.php`, `mobile/src/__tests__/texts.test.ts` |
