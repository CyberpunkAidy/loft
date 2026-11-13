import { useQueryClient } from '@tanstack/react-query';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { keys, useTexts } from '@/src/api/hooks';
import { useSession } from '@/src/api/session';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { TextChoices } from '@/src/api/types';
import { looksTextable, textsSummary } from '@/src/lib/texts';
import { Button, Checkbox, ErrorView, Loading, Muted, Notice, Row, Screen, TextField, Title } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** Booking texts and news and offers by text from this studio (decision 48), and the number they go to. */
export default function TextsScreen() {
  const slug = useSlug();
  const texts = useTexts(slug);
  if (texts.isLoading) return <Loading />;
  if (texts.error || !texts.data) return <ErrorView error={texts.error} onRetry={() => texts.refetch()} />;
  return <TextsForm choices={texts.data} />;
}

function TextsForm({ choices }: { choices: TextChoices }) {
  const slug = useSlug();
  const studio = useCurrentStudio()!;
  const { refreshUser } = useSession();
  const queryClient = useQueryClient();
  const [phone, setPhone] = useState(choices.phone ?? '');
  const [updates, setUpdates] = useState(choices.updates);
  const [marketing, setMarketing] = useState(choices.marketing);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const [saved, setSaved] = useState<string | null>(null);

  if (!choices.available) {
    return <Screen><Notice>{`${studio.name} doesn’t send texts.`}</Notice></Screen>;
  }
  const wants = updates || marketing;
  const numberProblem = wants && phone.trim() !== '' && !looksTextable(phone) ? 'Enter a mobile number we can text, like (303) 555-0123.' : undefined;

  async function save() {
    setBusy(true);
    setError(null);
    setSaved(null);
    // A newly ticked box, or a new number, sends the confirmation text.
    const newNumber = phone.trim() !== (choices.phone ?? '');
    const turnedOn = (updates && !choices.updates) || (marketing && !choices.marketing) || ((updates || marketing) && newNumber);
    try {
      const body = { updates, marketing, ...(newNumber ? { phone: phone.trim() || null } : {}) };
      const result = await request<{ data: TextChoices }>(`studios/${slug}/texts`, { method: 'PATCH', body });
      queryClient.setQueryData(keys.texts(slug), result.data);
      await refreshUser(); // the number is on the account
      setSaved(turnedOn ? 'Saved. A text is on its way to confirm.' : updates || marketing ? 'Saved.' : 'Saved. No more texts.');
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <View style={{ gap: 4 }}>
        <Title>Texts from {studio.name}</Title>
        <Muted>{`From ${choices.from}. Now: ${textsSummary(choices).toLowerCase()}.`}</Muted>
      </View>
      {choices.stopped_at ? (
        <Notice tone="warn">{`You replied STOP to ${studio.name}’s texts, so they’ve stopped. To get them again, text START to ${choices.from}.`}</Notice>
      ) : (
        <>
          <TextField
            label="Mobile phone"
            value={phone}
            onChangeText={setPhone}
            autoComplete="tel"
            keyboardType="phone-pad"
            hint="Your account’s number, for every studio. Changing it turns texts off until you tick the boxes again."
            error={error?.fieldMessage('phone') ?? numberProblem}
          />
          <Checkbox label={choices.labels.updates} checked={updates} onChange={setUpdates} />
          <Muted style={{ marginTop: -space.sm, marginLeft: 36 }}>Reminders before class, a spot opening on the waitlist, a class cancelled. The emails come either way.</Muted>
          <Checkbox label={choices.labels.marketing} checked={marketing} onChange={setMarketing} />
          <Muted>{`${choices.disclosure} You don’t need texts to book or buy anything.`}</Muted>
          {error && !Object.keys(error.fields).length ? <Notice tone="err">{error.message}</Notice> : null}
          {saved ? <Notice tone="ok">{saved}</Notice> : null}
          <Button title="Save" onPress={save} loading={busy} disabled={wants && !looksTextable(phone)} />
        </>
      )}
      <Row title="Privacy Policy" onPress={() => WebBrowser.openBrowserAsync(choices.privacy_url)} />
    </Screen>
  );
}
