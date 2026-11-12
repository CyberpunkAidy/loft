import type { Attendee, PhotoConsent, Question } from '../api/types';

/** A form's answers to the studio's questions (decision 45). null = not answered yet. */
export type Answers = { has_health_notes: boolean | null; medical_notes: string; photo_consent: PhotoConsent | null };

/** The photo question's answers, as the website words them. */
export const PHOTO_CHOICES: { value: PhotoConsent; title: string; subtitle: string }[] = [
  { value: 'public', title: 'Yes, including on social media', subtitle: 'Class photos and clips may be shared on the studio’s website and social media.' },
  { value: 'studio', title: 'Only inside the studio (not online)', subtitle: 'For feedback in class and for the studio itself. Never posted online.' },
  { value: 'none', title: 'No photos or video', subtitle: 'The studio will keep them out of photos and video.' },
];

/** Someone's current answers, to start a form from (a new person has none). */
export function answersOf(a?: Attendee): Answers {
  return {
    has_health_notes: a?.has_health_notes ?? (a?.medical_notes ? true : null),
    medical_notes: a?.medical_notes ?? '',
    photo_consent: a?.photo_consent ?? null,
  };
}

/** Is every question the studio asks answered (with the details, for a yes)? */
export function answersComplete(asked: Question[], v: Answers): boolean {
  const health = !asked.includes('health') || v.has_health_notes === false || (v.has_health_notes === true && v.medical_notes.trim() !== '');
  const photos = !asked.includes('photos') || v.photo_consent !== null;
  return health && photos;
}

/** What to send: an answer to each question asked; without the health question, medical notes as they are. */
export function answersBody(asked: Question[], v: Answers): Record<string, unknown> {
  const body: Record<string, unknown> = {};
  if (asked.includes('health')) {
    body.has_health_notes = v.has_health_notes;
    body.medical_notes = v.has_health_notes ? v.medical_notes.trim() : null;
  } else {
    body.medical_notes = v.medical_notes.trim() || null;
  }
  if (asked.includes('photos')) body.photo_consent = v.photo_consent;
  return body;
}

/** "a quick question" or "two quick questions", for what's left to answer. */
export function howMany(questions: Question[]): string {
  return questions.length === 1 ? 'a quick question' : 'two quick questions';
}
