# Data import

How a studio moves to Loft from another system ([decision 37](Decisions)): **Admin → Members → Import**, for admins and owners. It works with a CSV export from any system: Mindbody, Momence, Punchpass, WellnessLiving, Glofox, Jackrabbit, or a spreadsheet saved as CSV.

## The three steps

1. **Members and families.** Upload the client list: one row per person.
2. **Credits and memberships.** Upload what members have already paid for, and match each plan in the file to one of the studio's plans. Create the plans first.
3. **Invite your members.** Nothing is emailed until staff press **Send invitations**.

Each upload opens a preview before anything is imported:

- **Columns:** Loft matches columns by their names, in English, French, Spanish or German. Staff can change any match, and choose whether dates are written month first or day first. Loft guesses the date order from the file, or from the studio's currency when the file doesn't show it.
- **Plans:** each plan name in the file is matched to the studio's plan with the same name; staff can change the match, or choose not to import it.
- **What will happen:** counts, the first dozen accounts or plans, the rows that will be skipped and why, and notes about anything changed on the way in.

Importing runs in the background on `loft-worker`, and the page updates by itself. A finished import can be **undone for 30 days**: undoing removes whatever it created that nobody has used since. These are kept:

- people who have booked a class;
- members who accepted their invitation or hold plans;
- credits or memberships that have been used or carried on in Loft.

Templates for both files are linked from the page.

## Members and families

| Column (any of these names, and similar) | Becomes |
|---|---|
| First name, Last name, or a Full name ("Priya Lee" or "Lee, Priya") | The person's name |
| Email | Their account. **Rows that share an email are one family.** |
| Parent's email, Parent's name | For a child (or anyone) booked by someone else: they go on that account |
| Phone, Birthday, Medical notes, Emergency contact (+ phone) | The person's details |
| Agreed to marketing email ("yes") | Opted in to the studio's news and offers, recorded as consent from the import |

How rows become accounts:

- **Grouping:** rows with the same email, or the same parent's email, form one account.
- **Who holds the account:** the first adult row, wherever it is in the file; they also take classes. Everyone else is on their family.
- **No adult on an email:** the parent's name is used if there is one. Otherwise the account is named "*Lastname* family" until the parent changes it, and the preview notes this.
- **Existing Loft accounts:** an email that already has a Loft account, from another studio, joins this studio as it is. The import never changes an existing person's name, phone or password.
- **Marketing consent:** only the account holder's own row counts, and an earlier unsubscribe always stands.
- **Skipped rows:** rows with no name or no usable email are skipped, as are people already on the account. A birthday that can't be read is left blank, with a note.
- **Duplicates:** importing the same file again adds nobody twice.

## Credits and memberships

| Column | For |
|---|---|
| Member's email | Finding the member. Import members first |
| Plan | The pack, membership or pass name, matched to one of the studio's plans |
| Classes left | Packs and drop-ins |
| Expires or paid through | A pack's expiry, or the date a membership is paid up to |

- **Packs** come in with the classes left. They expire at the end of the given day in the studio's time zone, or after the plan's validity if no date is given.
- **Memberships** (monthly plans and season tuition) come in active until their paid-through date, with no card behind them. A membership without that date is skipped.
- **Skipped rows:** expired rows, packs with no classes left, emails that aren't members here, and plans not matched to one of the studio's.
- **Not a sale:** nothing is recorded as a sale. Each plan shows in the member's activity as "brought over from the old system".

### Carrying memberships on in Loft

Members on recurring memberships had saved cards in the old system, and those cards don't move. Instead:

- **Choosing the plan in Loft:** the member does this before the imported membership ends, from their invitation, the reminder email or the Plans page, which says "Keep it going". Stripe Checkout then starts the subscription with a trial until the old membership's end date, so the card is **first charged when the old one ends**. If that's less than 48 hours away, the card is charged straight away.
  - The season registration fee isn't charged again.
  - The imported membership then ends ("carried on as a Loft membership").
  - Stripe's $0 opening invoice isn't recorded as a sale.
- **Reminder:** a week before an imported membership ends, members who have been invited get one "Keep your *plan* going" email, unless they've already started it in Loft. Nobody is reminded before they've been invited, or when the studio can't take payments online.

## Invitations

- **The email:** studio-branded, listing the family and plans that came across.
  - New accounts get **Choose your password**. The link lasts 30 days, stops working once used, and signs the member in.
  - Members who already use Loft at another studio get **Sign in**.
- **Reminders:** staff can remind members who haven't come in, three days after the last email.
- **Email limits:** check the Resend plan's daily sending limit before inviting a big list; the free plan's is low. Emails over the limit fail, and show as failed in each member's email log.

## What imported people change elsewhere

- **First class:** an imported attendee's first class in Loft isn't their first class at the studio, so the "after a first class" automation doesn't send.
- **Churn scores:** imported members aren't scored as new joiners who never came.
  - An imported former client with no plan and nothing booked counts as **lapsed**.
  - One still holding credits or a membership who hasn't come within two weeks is flagged "Hasn't been to a class since moving to Loft".
  - Scores for everyone else are unchanged, so the model stays `rules-v1`.

## Data kept

- **The uploaded file** is kept only until it's imported. Drafts nobody imports are deleted after a week, and failed imports lose their file after a week.
- **The import record** keeps the file's name, the column matches, counts and the skipped rows (row number, name and reason, up to 1,000).

## How it's built

- **The `imports` table** holds the upload. `import_id` on `users`, `studio_users`, `attendees` and `entitlements` points back at it. `studio_users.invited_at` and `invitation_accepted_at` track invitations, and `member_snapshots.imported` feeds churn scoring.
- **`App\Support\CsvReader`:**
  - encodings: UTF-8 with or without a byte-order mark, UTF-16 and Windows-1252;
  - comma, semicolon or tab separators, and fields that run over several lines;
  - limits of 20,000 rows and 10 MB.
- **`App\Services\Imports`:**
  - `Fields`: column names and matching.
  - `Dates`: dates in either order.
  - `PeopleImporter` and `PlansImporter`: preview with `plan()`, then write with `apply()`.
  - `Imports`: upload, preview, run, undo and prune.
- **`App\Jobs\RunImport`** runs an import on the queue.
- **`App\Services\MemberInvitations`:** invitations, the welcome link, renewal reminders, and `continuation()` for checkout.
- **`Admin\ImportController`** runs the admin pages, and **`Studio\WelcomeController`** the welcome page at `/{studio}/welcome/{member}`.
- **Tests:** `tests/Feature/ImportTest.php`.
