import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { router, Stack, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { keys, useAttendees, useWaivers } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { Attendee } from '@/src/api/types';
import { answersBody, answersComplete, answersOf, howMany } from '@/src/lib/questions';
import { Button, Checkbox, Empty, Loading, Muted, Notice, Screen, TextField, Title } from '@/src/ui';
import { PersonQuestions } from '@/src/ui/PersonQuestions';
import { space } from '@/src/ui/theme';

/**
 * Add someone to the account, or edit them (?attendee=), with the studio's questions (decision 45).
 * ?ask=1 shows only the questions: the way to a booking for someone who hasn't answered them.
 */
export default function PersonScreen() {
  const slug = useSlug();
  const params = useLocalSearchParams<{ attendee?: string; ask?: string }>();
  const attendees = useAttendees(slug);
  const studio = useCurrentStudio();
  if (attendees.isLoading || !studio) return <Loading />;

  const existing = params.attendee ? attendees.data?.find((a) => a.id === Number(params.attendee)) : undefined;
  if (params.attendee && !existing) return <Screen><Empty>That person isn’t on your account.</Empty></Screen>;

  return <PersonForm key={existing?.id ?? 'new'} existing={existing} askOnly={params.ask === '1' && !!existing} hasSelf={(attendees.data ?? []).some((a) => a.is_self)} />;
}

function PersonForm({ existing, askOnly, hasSelf }: { existing?: Attendee; askOnly: boolean; hasSelf: boolean }) {
  const slug = useSlug();
  const studio = useCurrentStudio()!;
  const waivers = useWaivers(slug);
  const queryClient = useQueryClient();
  const asked = studio.policies.questions ?? [];
  const [f, setF] = useState({
    first_name: existing?.first_name ?? '',
    last_name: existing?.last_name ?? '',
    dob: existing?.dob ?? '',
    emergency_contact: existing?.emergency_contact ?? '',
  });
  const [isSelf, setIsSelf] = useState(existing?.is_self ?? false);
  const [answers, setAnswers] = useState(answersOf(existing));
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (k: keyof typeof f) => (v: string) => setF((x) => ({ ...x, [k]: v }));

  const details = { ...f, dob: f.dob || null, emergency_contact: f.emergency_contact || null, is_self: isSelf };
  const toSign = existing ? (waivers.data ?? []).filter((w) => w.attendees.some((a) => a.attendee_id === existing.id && a.needed)).length : 0;
  const title = askOnly ? (existing!.is_self ? 'About you' : `About ${existing!.first_name}`) : existing ? `${existing.first_name} ${existing.last_name}` : 'Add someone';
  const ready = answersComplete(asked, answers) && (askOnly || (!!f.first_name.trim() && !!f.last_name.trim()));

  async function save() {
    setBusy(true);
    setError(null);
    try {
      const body = askOnly ? answersBody(asked, answers) : { ...details, ...answersBody(asked, answers) };
      await request(existing ? `studios/${slug}/attendees/${existing.id}` : `studios/${slug}/attendees`, {
        method: existing ? 'PATCH' : 'POST',
        body,
        idempotencyKey: existing ? undefined : Crypto.randomUUID(),
      });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.attendees(slug) }),
        queryClient.invalidateQueries({ queryKey: ['session', slug] }), // booking options depend on the answers
      ]);
      router.back();
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Stack.Screen options={{ title }} />
      {askOnly ? (
        <View style={{ gap: 4 }}>
          <Title>{existing!.is_self ? `${capitalize(howMany(asked))} for you` : `${capitalize(howMany(asked))} about ${existing!.first_name}`}</Title>
          <Muted>{studio.name} asks everyone before they book. You can change your answers any time under Family.</Muted>
        </View>
      ) : (
        <>
          {existing && toSign ? (
            <View style={{ gap: space.sm }}>
              <Notice tone="warn">{`${existing.first_name} has ${toSign === 1 ? 'a waiver' : `${toSign} waivers`} to sign.`}</Notice>
              <Button title="Sign the waiver" small variant="secondary" icon="waiver" onPress={() => router.push({ pathname: '/studio/[slug]/waiver', params: { slug, attendee: String(existing.id) } })} />
            </View>
          ) : null}
          <TextField label="First name" value={f.first_name} onChangeText={set('first_name')} error={error?.fieldMessage('first_name')} />
          <TextField label="Last name" value={f.last_name} onChangeText={set('last_name')} error={error?.fieldMessage('last_name')} />
          <TextField label="Date of birth (optional)" value={f.dob} onChangeText={set('dob')} placeholder="YYYY-MM-DD" keyboardType="numbers-and-punctuation" hint="Some classes have age limits." error={error?.fieldMessage('dob')} />
          <TextField label="Emergency contact (optional)" value={f.emergency_contact} onChangeText={set('emergency_contact')} error={error?.fieldMessage('emergency_contact')} />
          {!hasSelf || existing?.is_self ? <Checkbox label="This is me" checked={isSelf} onChange={setIsSelf} /> : null}
        </>
      )}
      <PersonQuestions asked={asked} studioName={studio.name} value={answers} onChange={setAnswers} error={(field) => error?.fieldMessage(field)} />
      {error && !Object.keys(error.fields).length ? <Notice tone="err">{error.message}</Notice> : null}
      <Button title={askOnly ? 'Save and go back to the class' : existing ? 'Save' : 'Add'} onPress={save} loading={busy} disabled={!ready} />
      <View style={{ height: space.lg }} />
    </Screen>
  );
}

function capitalize(s: string): string {
  return s.charAt(0).toUpperCase() + s.slice(1);
}
