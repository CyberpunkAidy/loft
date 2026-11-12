# Injury and photo questions

Every studio asks two questions about each person who takes classes ([decision 45](Decisions)):

1. **Injuries and health:** "Any injuries, health conditions or allergies the instructors should know about?" No, or Yes with the details.
2. **Photos and video:** "{Studio} sometimes takes photos and short videos in class. Is that OK?"
   - **Yes, including on social media**
   - **Only inside the studio (not online)**
   - **No photos or video**

A parent answers for their children. Answers can be changed any time.

## When they're asked

| When | Where | How |
|---|---|---|
| Adding someone to an account | Website: My account → Family → Add. App: Account → Family → Add someone | Part of the form, required on the website. The app asks both too, but the API lets an older app add someone without them |
| Before booking someone who hasn't answered | Website booking page; app class screen | The booking page says "We’ll ask two quick questions first". Booking goes to **Two quick questions about Leo**, then back to the booking page with Leo still chosen. A waiver comes after, as before |
| Any time | Website: Family → the person. App: Family → the person | The same questions, with the current answers |
| When they tell staff | Check-in, or the member page | Staff record the photo answer for them ([below](#when-staff-record-a-photo-answer)) |

Everyone who joined before the questions existed is asked at their next booking. Until then, staff see **Not asked yet** on rosters, and should leave them out of photos.

**Bookings the questions don't hold up:**
- staff adding someone to a class;
- a booking finished by a paid checkout (the member answered before paying);
- waitlist moves.

## When staff record a photo answer

A parent often says it in person: "no photos of Leo, please". Staff record it from what they were told ([decision 46](Decisions)):

- **Where:** **Record photo answer** (or **Change photo answer**) under each person on **Check-in** (instructors and up), and in the Photos column of the **member page** (admins and owners).
- **What:** any of the three answers, plus a required note on how they heard, such as "At the front desk" or "Paper form".
- **The member is told:** an email to the account holder, "Leo’s photo answer was recorded", with the answer, how the studio heard, who recorded it, and a **Check or change it** button. It's transactional, so it always goes (except from a demo studio).
- **The member can still change it:** their Family page and the app say "{Studio} recorded this answer on {date}, from what you told them (“At the front desk”)". Their own answer replaces it and clears the note.
- **Nothing to change:** recording the answer someone already has does nothing and sends nothing.
- **The record:** the member page shows "Recorded by Cam Rivera, Nov 12, 2026: “At the front desk”". Activity shows "Photos and video: no photos or video, recorded by staff: “At the front desk”".

Health answers can only come from the member.

## Turning them off

**Admin → Settings → Questions for each person** has a checkbox for each (`ask_health` and `ask_photo_consent`, both on by default). With the health question off, the form goes back to a plain, optional **Medical notes / allergies** box. With both off, nobody is asked and booking is as before. Answers already given are kept.

## What staff see

- **Check-in and the class roster:**
  - a line above the list naming who to leave out of a photo: "Photos and video: No photos: Jordan Lee. In-studio only: Ines Lee. Not asked yet: Sam Lee." It counts only people booked or checked in, and says "everyone in this class said yes" when that's so;
  - per person: a red **No photos** badge, an amber **In-studio photos only** badge, or a grey **Not asked yet: injuries, photos**;
  - their injury and health notes in amber, as before.
- **Member page → Family:** each person's photo answer and when it was given (and by which staff member, if staff recorded it), and their health notes or "None", with when they answered.
- **Member page → Activity:** every photo answer, as "Photos and video: only inside the studio (not online) (was: yes, including on social media)".

Loft records the answers. Following them, and any extra consent local law needs before publishing someone's image, is up to the studio (Terms, section on waivers).

## How it's stored

| Column on `attendees` | Meaning |
|---|---|
| `medical_notes` | The details, for a Yes. A No clears them |
| `health_answered_at` | When the health question was last answered. Empty = not asked. With it set, empty notes mean "No" |
| `photo_consent` | `public`, `studio` or `none`. Empty = not asked |
| `photo_consent_at`, `photo_consent_by` | When the current answer was given, and by whom: the account holder, or the staff member who recorded it |
| `photo_consent_note` | How staff heard, when they recorded it. Cleared when the member answers |

Each new photo answer also records a `consent.photos_answered` event (`answer`, `previous`, and `staff_id` and `note` when staff recorded it), which is the history on the member page.

## In the API

- `GET /studios/{slug}`: `policies.questions` lists the questions the studio asks (`health`, `photos`).
- People (`/attendees`) have `has_health_notes` (null until answered), `health_answered_at`, `photo_consent`, `photo_consent_at`, `photo_consent_by_studio` and `photo_consent_note` (how the studio heard, when its staff recorded it), and `questions_needed`.
- Adding or editing someone accepts `has_health_notes` (true needs `medical_notes`) and `photo_consent`.
- Booking someone with questions left answers `422 questions_required`, with `attendee_id` and `questions`. A class's booking options give the same `reason`, with `questions`.

See [API](API).

## Where it lives

| What | Where |
|---|---|
| Rules, labels and what's left to answer | `App\Models\Attendee` (`PHOTO_CONSENT`, `answerRules()`, `questionsNeeded()`, `answersFrom()`) |
| Saving answers, the history event, and staff recording (with the email) | `App\Services\PersonAnswers` (`save()`, `recordByStaff()`) |
| Enforcing before booking | `Bookings::book()` (`questions`), shown by `BookingOptions` |
| Member pages | `Studio\AttendeeController` (`questions`, `answer`), `studio/_person-questions.blade.php`, `studio/person-questions.blade.php` |
| Staff views | `admin/_person-flags.blade.php`, `admin/_photo-summary.blade.php`, `admin/_photo-record.blade.php`; `Admin\PhotoAnswerController` |
| App | `app/studio/[slug]/person.tsx`, `src/ui/PersonQuestions.tsx`, `src/lib/questions.ts` |
| Tests | `tests/Feature/PersonQuestionsTest.php`, `mobile/src/__tests__/questions.test.ts` |
