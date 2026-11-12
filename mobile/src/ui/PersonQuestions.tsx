import { View } from 'react-native';

import type { Question } from '../api/types';
import { type Answers, PHOTO_CHOICES } from '../lib/questions';
import { Choice, Heading, Muted, Notice, TextField } from './index';
import { space } from './theme';

/**
 * The studio's questions for one person (decision 45): injuries and health, then photos. When the
 * studio doesn't ask about health, a plain medical notes field instead, as before.
 */
export function PersonQuestions({ asked, studioName, value, onChange, error, recordedNote }: {
  asked: Question[];
  studioName: string;
  value: Answers;
  onChange: (next: Answers) => void;
  error?: (field: string) => string | undefined;
  /** How the studio heard, when its staff recorded the current photo answer (decision 46). */
  recordedNote?: string | null;
}) {
  const set = (patch: Partial<Answers>) => onChange({ ...value, ...patch });
  return (
    <View style={{ gap: space.lg }}>
      {asked.includes('health') ? (
        <View style={{ gap: space.sm }}>
          <Heading>Injuries and health</Heading>
          <Muted>Any injuries, health conditions or allergies the instructors should know about?</Muted>
          <View style={{ flexDirection: 'row', gap: space.sm }}>
            <View style={{ flex: 1 }}><Choice title="No" selected={value.has_health_notes === false} onPress={() => set({ has_health_notes: false })} /></View>
            <View style={{ flex: 1 }}><Choice title="Yes" selected={value.has_health_notes === true} onPress={() => set({ has_health_notes: true })} /></View>
          </View>
          {error?.('has_health_notes') ? <Muted>{error('has_health_notes')}</Muted> : null}
          {value.has_health_notes ? (
            <TextField
              label="Tell us about them"
              value={value.medical_notes}
              onChangeText={(t) => set({ medical_notes: t })}
              multiline
              hint="For example a recent shoulder injury, asthma or a nut allergy. Instructors see this on the class list."
              error={error?.('medical_notes')}
            />
          ) : null}
        </View>
      ) : (
        <TextField
          label="Medical notes (optional)"
          value={value.medical_notes}
          onChangeText={(t) => set({ medical_notes: t })}
          multiline
          hint="Shown to instructors, so they can keep everyone safe."
          error={error?.('medical_notes')}
        />
      )}
      {asked.includes('photos') ? (
        <View style={{ gap: space.sm }}>
          <Heading>Photos and video</Heading>
          <Muted>{studioName} sometimes takes photos and short videos in class. Is that OK?</Muted>
          {recordedNote ? <Notice>{`${studioName} recorded this answer from what you told them (“${recordedNote}”). If it isn’t right, choose another.`}</Notice> : null}
          {PHOTO_CHOICES.map((p) => (
            <Choice key={p.value} title={p.title} subtitle={p.subtitle} selected={value.photo_consent === p.value} onPress={() => set({ photo_consent: p.value })} />
          ))}
          {error?.('photo_consent') ? <Muted>{error('photo_consent')}</Muted> : null}
        </View>
      ) : null}
    </View>
  );
}
