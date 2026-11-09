import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { keys, useAttendees, useWaivers } from '@/src/api/hooks';
import { useSlug } from '@/src/api/studio';
import { Body, Button, Card, Checkbox, Empty, ErrorView, Heading, Loading, Muted, Notice, Screen, TextField, Title } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** Sign a waiver for someone on the account, then the next one they need, if any. */
export default function SignWaiver() {
  const slug = useSlug();
  const params = useLocalSearchParams<{ attendee: string; waiver?: string }>();
  const attendeeId = Number(params.attendee);
  const waivers = useWaivers(slug);
  const attendees = useAttendees(slug);
  const queryClient = useQueryClient();
  const [name, setName] = useState('');
  const [agree, setAgree] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [signedOne, setSignedOne] = useState(false);

  if (waivers.isLoading || attendees.isLoading) return <Loading />;
  if (waivers.error) return <Screen><ErrorView error={waivers.error} onRetry={() => waivers.refetch()} /></Screen>;

  const attendee = attendees.data?.find((a) => a.id === attendeeId);
  const needed = (waivers.data ?? []).filter((w) => w.attendees.some((a) => a.attendee_id === attendeeId && a.needed));
  const waiver = needed.find((w) => String(w.id) === params.waiver) ?? needed[0];

  if (!attendee) return <Screen><Empty>That person isn’t on your account.</Empty></Screen>;
  if (!waiver) {
    return (
      <Screen>
        <Notice tone="ok">{signedOne ? `All done: ${attendee.first_name} has signed everything this studio needs.` : `${attendee.first_name} has nothing to sign right now.`}</Notice>
        <Button title="Back to the class" onPress={() => router.back()} />
      </Screen>
    );
  }

  async function sign() {
    setBusy(true);
    setError(null);
    try {
      const res = await request<{ next_waiver_id: number | null }>(`studios/${slug}/attendees/${attendeeId}/waiver-signatures`, {
        method: 'POST',
        body: { waiver_id: waiver!.id, signed_name: name.trim(), agree },
        idempotencyKey: Crypto.randomUUID(),
      });
      setSignedOne(true);
      setName('');
      setAgree(false);
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: keys.waivers(slug) }),
        queryClient.invalidateQueries({ queryKey: ['session', slug] }),
        queryClient.invalidateQueries({ queryKey: ['bookings', slug] }),
      ]);
      router.setParams({ waiver: res.next_waiver_id ? String(res.next_waiver_id) : '' });
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      {signedOne ? <Notice tone="ok">Signed. There’s one more for {attendee.first_name}.</Notice> : null}
      <View style={{ gap: 4 }}>
        <Title>{waiver.title}</Title>
        <Muted>For {attendee.first_name} {attendee.last_name} · version {waiver.version}</Muted>
      </View>
      <Card>
        <Body>{waiver.body}</Body>
      </Card>
      <Heading>Sign</Heading>
      {attendee.is_minor ? <Muted>{attendee.first_name} is under 18, so a parent or guardian signs.</Muted> : null}
      <TextField
        label={attendee.is_minor ? 'Your full name (parent or guardian)' : 'Your full name'}
        value={name}
        onChangeText={setName}
        autoComplete="name"
        error={error?.fieldMessage('signed_name')}
      />
      <Checkbox label="I have read this waiver and agree to it. Typing my name is my signature." checked={agree} onChange={setAgree} />
      {error?.fieldMessage('agree') ? <Muted>{error.fieldMessage('agree')}</Muted> : null}
      {error && !Object.keys(error.fields).length ? <Notice tone="err">{error.message}</Notice> : null}
      <Button title="Sign" onPress={sign} loading={busy} disabled={name.trim().length < 3 || !agree} />
      <View style={{ height: space.lg }} />
    </Screen>
  );
}
