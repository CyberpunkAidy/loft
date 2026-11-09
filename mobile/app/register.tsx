import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import { ApiError } from '@/src/api/client';
import { useStudio } from '@/src/api/hooks';
import { useSession } from '@/src/api/session';
import { AccentProvider, Body, Button, Checkbox, Muted, Screen, TextField } from '@/src/ui';

export default function Register() {
  const { register } = useSession();
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const studio = useStudio(String(slug));
  const [fields, setFields] = useState({ first_name: '', last_name: '', email: '', phone: '', password: '' });
  const [marketing, setMarketing] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const set = (key: keyof typeof fields) => (value: string) => setFields((f) => ({ ...f, [key]: value }));

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await register(String(slug), { ...fields, phone: fields.phone || undefined, marketing_email: marketing });
      router.replace(`/studio/${slug}`);
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  const name = studio.data?.name ?? 'the studio';
  return (
    <AccentProvider color={studio.data?.accent_color}>
      <Screen>
        <Body>One account for everyone in your family: you add your children after signing up.</Body>
        <TextField label="First name" value={fields.first_name} onChangeText={set('first_name')} autoComplete="given-name" error={error?.fieldMessage('first_name')} />
        <TextField label="Last name" value={fields.last_name} onChangeText={set('last_name')} autoComplete="family-name" error={error?.fieldMessage('last_name')} />
        <TextField label="Email" value={fields.email} onChangeText={set('email')} autoCapitalize="none" autoComplete="email" keyboardType="email-address" error={error?.fieldMessage('email')} />
        <TextField label="Phone (optional)" value={fields.phone} onChangeText={set('phone')} autoComplete="tel" keyboardType="phone-pad" error={error?.fieldMessage('phone')} />
        <TextField label="Password" value={fields.password} onChangeText={set('password')} secureTextEntry autoComplete="new-password" hint="At least 8 characters." error={error?.fieldMessage('password')} />
        <Checkbox label={`Send me news and offers from ${name}. You can unsubscribe at any time.`} checked={marketing} onChange={setMarketing} />
        {error && !Object.keys(error.fields).length ? <Muted>{error.message}</Muted> : null}
        <Button title="Create account" onPress={submit} loading={busy} />
      </Screen>
    </AccentProvider>
  );
}
