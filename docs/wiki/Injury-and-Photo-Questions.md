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

Everyone who joined before the questions existed is asked at their next booking. Until then, staff see **Not asked yet** on rosters, and should leave them out of photos.

**Bookings the questions don't hold up:**
- staff adding someone to a class;
- a booking finished by a paid checkout (the member answered before paying);
- waitlist moves.

## Turning them off

**Admin → Settings → Questions for each person** has a checkbox for each (`ask_health` and `ask_photo_consent`, both on by default). With the health question off, the form goes back to a plain, optional **Medical notes / allergies** box. With both off, nobody is asked and booking is as before. Answers already given are kept.

## What staff see

- **Check-in and the class roster:**
  - a line above the list naming who to leave out of a photo: "Photos and video: No photos: Jordan Lee. In-studio only: Ines Lee. Not asked yet: Sam Lee." It counts only people booked or checked in, and says "everyone in this class said yes" when that's so;
  - per person: a red **No photos** badge, an amber **In-studio photos only** badge, or a grey **Not asked yet: injuries, photos**;
  - their injury and health notes in amber, as before.
- **Member page → Family:** each person's photo answer and when it was given, and their health notes or "None", with when they answered.
- **Member page → Activity:** every photo answer, as "Photos and video: only inside the studio (not online) (was: yes, including on social media)".

Loft records the answers. Following them, and any extra consent local law needs before publishing someone's image, is up to the studio (Terms, section on waivers).

## How it's stored

| Column on `attendees` | Meaning |
|---|---|
| `medical_notes` | The details, for a Yes. A No clears them |
| `health_answered_at` | When the health question was last answered. Empty = not asked. With it set, empty notes mean "No" |
| `photo_consent` | `public`, `studio` or `none`. Empty = not asked |
| `photo_consent_at`, `photo_consent_by` | When the current answer was given, and by whom (the account holder) |

Each new photo answer also records a `consent.photos_answered` event (`answer`, `previous`), which is the history on the member page.

## In the API

- `GET /studios/{slug}`: `policies.questions` lists the questions the studio asks (`health`, `photos`).
- People (`/attendees`) have `has_health_notes` (null until answered), `health_answered_at`, `photo_consent`, `photo_consent_at` and `questions_needed`.
- Adding or editing someone accepts `has_health_notes` (true needs `medical_notes`) and `photo_consent`.
- Booking someone with questions left answers `422 questions_required`, with `attendee_id` and `questions`. A class's booking options give the same `reason`, with `questions`.

See [API](API).

## Where it lives

| What | Where |
|---|---|
| Rules, labels and what's left to answer | `App\Models\Attendee` (`PHOTO_CONSENT`, `answerRules()`, `questionsNeeded()`, `answersFrom()`) |
| Saving answers, and the history event | `App\Services\PersonAnswers` |
| Enforcing before booking | `Bookings::book()` (`questions`), shown by `BookingOptions` |
| Member pages | `Studio\AttendeeController` (`questions`, `answer`), `studio/_person-questions.blade.php`, `studio/person-questions.blade.php` |
| Staff views | `admin/_person-flags.blade.php`, `admin/_photo-summary.blade.php` |
| App | `app/studio/[slug]/person.tsx`, `src/ui/PersonQuestions.tsx`, `src/lib/questions.ts` |
| Tests | `tests/Feature/PersonQuestionsTest.php`, `mobile/src/__tests__/questions.test.ts` |
