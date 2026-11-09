import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack } from 'expo-router';
import { StatusBar } from 'expo-status-bar';
import { useState } from 'react';

import { ApiError } from '@/src/api/client';
import { SessionProvider } from '@/src/api/session';
import { useTheme } from '@/src/ui';

export { ErrorBoundary } from 'expo-router';

/** Retry network hiccups and server errors, not answers like "not found" or "needs a plan". */
function shouldRetry(failures: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status > 0 && error.status < 500) return false;
  return failures < 2;
}

export default function RootLayout() {
  const [queryClient] = useState(
    () => new QueryClient({ defaultOptions: { queries: { retry: shouldRetry, staleTime: 30_000 }, mutations: { retry: false } } }),
  );

  return (
    <QueryClientProvider client={queryClient}>
      <SessionProvider>
        <Navigation />
      </SessionProvider>
    </QueryClientProvider>
  );
}

function Navigation() {
  const { c, scheme } = useTheme();
  return (
    <>
      <StatusBar style={scheme === 'dark' ? 'light' : 'dark'} />
      <Stack
        screenOptions={{
          headerStyle: { backgroundColor: c.bg },
          headerTintColor: c.ink,
          headerShadowVisible: false,
          contentStyle: { backgroundColor: c.bg },
          headerBackTitle: 'Back',
        }}>
        <Stack.Screen name="index" options={{ headerShown: false }} />
        <Stack.Screen name="welcome" options={{ headerShown: false }} />
        <Stack.Screen name="find" options={{ title: 'Find your studio' }} />
        <Stack.Screen name="sign-in" options={{ title: 'Sign in' }} />
        <Stack.Screen name="register" options={{ title: 'Create your account' }} />
        <Stack.Screen name="studios" options={{ title: 'Your studios' }} />
        <Stack.Screen name="studio/[slug]" options={{ headerShown: false }} />
      </Stack>
    </>
  );
}
