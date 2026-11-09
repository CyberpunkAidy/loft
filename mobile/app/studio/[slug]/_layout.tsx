import { Redirect, Stack, useLocalSearchParams } from 'expo-router';
import { useEffect } from 'react';

import { useSession } from '@/src/api/session';
import { StudioProvider, useCurrentStudio } from '@/src/api/studio';
import { Loading, useTheme } from '@/src/ui';

/** Everything for one studio: its tabs and the screens they open, in its color. */
export default function StudioLayout() {
  const { slug } = useLocalSearchParams<{ slug: string }>();
  const { ready, user, studioSlug, chooseStudio } = useSession();

  useEffect(() => {
    if (user && slug && slug !== studioSlug) void chooseStudio(String(slug));
  }, [user, slug, studioSlug, chooseStudio]);

  if (!ready) return <Loading />;
  if (!user) return <Redirect href="/welcome" />;

  return (
    <StudioProvider slug={String(slug)}>
      <StudioStack />
    </StudioProvider>
  );
}

function StudioStack() {
  const { c } = useTheme();
  const studio = useCurrentStudio();
  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: c.bg },
        headerTintColor: c.ink,
        headerShadowVisible: false,
        contentStyle: { backgroundColor: c.bg },
        headerBackTitle: 'Back',
      }}>
      <Stack.Screen name="(tabs)" options={{ headerShown: false, title: studio?.name ?? '' }} />
      <Stack.Screen name="class/[id]" options={{ title: 'Class' }} />
      <Stack.Screen name="waiver" options={{ title: 'Waiver' }} />
      <Stack.Screen name="plans" options={{ title: 'Plans' }} />
      <Stack.Screen name="check-in" options={{ title: 'Check-in code' }} />
      <Stack.Screen name="family" options={{ title: 'Family' }} />
      <Stack.Screen name="add-person" options={{ title: 'Add someone', presentation: 'modal' }} />
      <Stack.Screen name="purchases" options={{ title: 'Plans & purchases' }} />
      <Stack.Screen name="video/[id]" options={{ title: 'Video' }} />
    </Stack>
  );
}
