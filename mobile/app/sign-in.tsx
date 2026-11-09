import * as WebBrowser from 'expo-web-browser';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';

import { ApiError } from '@/src/api/client';
import { useSession } from '@/src/api/session';
import { WEB_URL } from '@/src/config';
import { Body, Button, Screen, TextField } from '@/src/ui';

export default function SignIn() {
  const { signIn, join, chooseStudio } = useSession();
  const { slug } = useLocalSearchParams<{ slug?: string }>();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const user = await signIn(email, password);
      if (slug) {
        if (!user.studios?.some((s) => s.slug === slug)) await join(slug);
        await chooseStudio(slug);
        router.replace(`/studio/${slug}`);
      } else {
        router.replace('/');
      }
    } catch (e) {
      setError(e instanceof ApiError ? e : new ApiError(0, 'unknown', (e as Error).message));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Body>Use the email and password you use on your studio’s website.</Body>
      <TextField
        label="Email"
        value={email}
        onChangeText={setEmail}
        autoCapitalize="none"
        autoComplete="email"
        keyboardType="email-address"
        textContentType="username"
        error={error?.fieldMessage('email')}
      />
      <TextField
        label="Password"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
        autoComplete="current-password"
        textContentType="password"
        onSubmitEditing={submit}
        error={error?.fieldMessage('password') ?? (error && !error.fieldMessage('email') ? error.message : undefined)}
      />
      <Button title="Sign in" onPress={submit} loading={busy} disabled={!email || !password} />
      <Button title="Forgot your password?" variant="link" onPress={() => WebBrowser.openBrowserAsync(`${WEB_URL}/forgot-password`)} />
    </Screen>
  );
}
