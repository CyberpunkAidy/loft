import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { useSession } from '@/src/api/session';
import type { Studio } from '@/src/api/types';
import { studioSlugFrom } from '@/src/lib/studioAddress';
import { AccentProvider, Body, Button, Card, Heading, Muted, Notice, Screen, StudioMark, TextField } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** Find a studio by its web address; then sign in, create an account, or (signed in) join it. */
export default function FindStudio() {
  const { user, join, chooseStudio } = useSession();
  const params = useLocalSearchParams<{ address?: string }>();
  const [address, setAddress] = useState(params.address ?? '');
  const [studio, setStudio] = useState<Studio | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const member = !!studio && !!user?.studios?.some((s) => s.slug === studio.slug);

  async function look() {
    const slug = studioSlugFrom(address);
    setError(null);
    setStudio(null);
    if (!slug) {
      setError('Type the studio’s web address, like loftstudio.app/skyline.');
      return;
    }
    setBusy(true);
    try {
      setStudio((await request<{ data: Studio }>(`studios/${slug}`, { auth: false })).data);
    } catch (e) {
      setError(e instanceof ApiError && e.status === 404 ? 'No studio has that address. Check it with your studio.' : (e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  async function open() {
    if (!studio) return;
    setBusy(true);
    try {
      if (!member) await join(studio.slug);
      await chooseStudio(studio.slug);
      router.replace(`/studio/${studio.slug}`);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen>
      <Body>Your studio’s web address is on its website and emails, such as loftstudio.app/skyline.</Body>
      <TextField
        label="Studio address"
        value={address}
        onChangeText={(v) => {
          setAddress(v);
          setStudio(null);
        }}
        placeholder="loftstudio.app/your-studio"
        autoCapitalize="none"
        autoCorrect={false}
        keyboardType="url"
        returnKeyType="search"
        onSubmitEditing={look}
        error={error ?? undefined}
      />
      {!studio ? <Button title="Find it" onPress={look} loading={busy} /> : null}

      {studio ? (
        <AccentProvider color={studio.accent_color}>
          <Card>
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: space.md }}>
              <StudioMark studio={studio} maxWidth={140} />
              <View style={{ flex: 1 }}>
                <Heading>{studio.name}</Heading>
                <Muted>{studio.website_url.replace(/^https?:\/\//, '')}</Muted>
              </View>
            </View>
            {user ? (
              <>
                {!member ? <Notice>You’ll join {studio.name} as {user.first_name} ({user.email}).</Notice> : null}
                <Button title={member ? `Open ${studio.name}` : `Join ${studio.name}`} onPress={open} loading={busy} />
              </>
            ) : (
              <View style={{ gap: space.sm }}>
                <Button title="Create an account" onPress={() => router.push({ pathname: '/register', params: { slug: studio.slug } })} />
                <Button title="I have an account" variant="secondary" onPress={() => router.push({ pathname: '/sign-in', params: { slug: studio.slug } })} />
              </View>
            )}
          </Card>
        </AccentProvider>
      ) : null}
    </Screen>
  );
}
