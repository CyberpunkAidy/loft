import type { Attendee } from '../api/types';
import { answersBody, answersComplete, answersOf, howMany } from '../lib/questions';

const person = (over: Partial<Attendee> = {}): Attendee => ({
  id: 1, first_name: 'Leo', last_name: 'Shah', dob: null, is_self: false, is_minor: true, emergency_contact: null,
  medical_notes: null, has_health_notes: null, health_answered_at: null, photo_consent: null, photo_consent_at: null, questions_needed: ['health', 'photos'],
  ...over,
});

describe('the studio’s questions (decision 45)', () => {
  it('starts from someone’s answers, or none', () => {
    expect(answersOf()).toEqual({ has_health_notes: null, medical_notes: '', photo_consent: null });
    expect(answersOf(person({ has_health_notes: false, photo_consent: 'none' }))).toEqual({ has_health_notes: false, medical_notes: '', photo_consent: 'none' });
    // Notes from before the question existed read as a yes, ready to confirm.
    expect(answersOf(person({ medical_notes: 'Asthma' })).has_health_notes).toBe(true);
  });

  it('needs every question the studio asks answered, with details for a yes', () => {
    const both = ['health', 'photos'] as const;
    expect(answersComplete([...both], { has_health_notes: null, medical_notes: '', photo_consent: 'public' })).toBe(false);
    expect(answersComplete([...both], { has_health_notes: true, medical_notes: '  ', photo_consent: 'public' })).toBe(false);
    expect(answersComplete([...both], { has_health_notes: true, medical_notes: 'Sore wrist', photo_consent: null })).toBe(false);
    expect(answersComplete([...both], { has_health_notes: false, medical_notes: '', photo_consent: 'studio' })).toBe(true);
    expect(answersComplete(['photos'], { has_health_notes: null, medical_notes: '', photo_consent: 'none' })).toBe(true);
    expect(answersComplete([], { has_health_notes: null, medical_notes: '', photo_consent: null })).toBe(true);
  });

  it('sends an answer to each question asked, and plain notes when health isn’t asked', () => {
    expect(answersBody(['health', 'photos'], { has_health_notes: false, medical_notes: 'old', photo_consent: 'studio' }))
      .toEqual({ has_health_notes: false, medical_notes: null, photo_consent: 'studio' });
    expect(answersBody(['health'], { has_health_notes: true, medical_notes: ' Sore wrist ', photo_consent: null }))
      .toEqual({ has_health_notes: true, medical_notes: 'Sore wrist' });
    expect(answersBody([], { has_health_notes: null, medical_notes: '', photo_consent: null })).toEqual({ medical_notes: null });
    expect([howMany(['photos']), howMany(['health', 'photos'])]).toEqual(['a quick question', 'two quick questions']);
  });
});
