import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { router } from 'expo-router';
import { useState } from 'react';

import { ApiError, request } from '@/src/api/client';
import { keys, useAttendees } from '@/src/api/hooks';
import { useSlug } from '@/src/api/studio';
import { Button, Checkbox, Muted, Screen, TextField } from '@/src/ui';

export default function AddPerson() {
  const slug = useSlug();
  const attendees = useAttendees(slug);
  const queryClient = useQueryClient();
  const hasSelf = (attendees.data ?? []).some((a) => a.is_self);
  const [f, setF] = useState({ first_name: '', last_name: '', dob: '', emergency_contact: '', medical_notes: '' });
  const [isSelf, setIsSelf] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (v: string) => setF((x) => ({ ...x, [k]: v }));

  async function save() {
    setBusy(true);
    setError(null);
    try {
      await request(`studios/${slug}/attendees`, {
        method: 'POST',
        body: { ...f, dob: f.dob || null, emergency_contact: f.emergency_contact || null, medical_notes: f.medical_notes || null, is_self: isSelf },
        idempotencyKey: Crypto.randomUUID(),
      });
      await queryClient.invalidateQueries({ queryKey: keys.attendees(slug) });
      router.back();
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <TextField label="First name" value={f.first_name} onChangeText={set('first_name')} error={error?.fieldMessage('first_name')} />
      <TextField label="Last name" value={f.last_name} onChangeText={set('last_name')} error={error?.fieldMessage('last_name')} />
      <TextField label="Date of birth (optional)" value={f.dob} onChangeText={set('dob')} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" hint="Some classes have age limits." error={error?.fieldMessage('dob')} />
      <TextField label="Emergency contact (optional)" value={f.emergency_contact} onChangeText={set('emergency_contact')} error={error?.fieldMessage('emergency_contact')} />
      <TextField label="Medical notes (optional)" value={f.medical_notes} onChangeText={set('medical_notes')} multiline hint="Shown to instructors, so they can keep everyone safe." error={error?.fieldMessage('medical_notes')} />
      {!hasSelf ? <Checkbox label="This is me" checked={isSelf} onChange={setIsSelf} /> : null}
      {error && !Object.keys(error.fields).length ? <Muted>{error.message}</Muted> : null}
      <Button title="Add" onPress={save} loading={busy} disabled={!f.first_name.trim() || !f.last_name.trim()} />
    </Screen>
  );
}
