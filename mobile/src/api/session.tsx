import { useQueryClient } from '@tanstack/react-query';
import Constants from 'expo-constants';
import { createContext, ReactNode, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { Platform } from 'react-native';

import { storage } from '../lib/storage';
import { ApiError, request, setToken, setUnauthorizedHandler } from './client';
import type { User } from './types';

const TOKEN_KEY = 'loft.token';
const STUDIO_KEY = 'loft.studio';

export type Registration = {
  first_name: string;
  last_name: string;
  email: string;
  password: string;
  phone?: string;
  marketing_email?: boolean;
};

type SessionValue = {
  /** False until the saved token has been read and checked. */
  ready: boolean;
  user: User | null;
  /** The studio the member last chose (its web address, e.g. "skyline"). */
  studioSlug: string | null;
  signIn(email: string, password: string): Promise<User>;
  register(studioSlug: string, fields: Registration): Promise<User>;
  /** Become a member of another studio with the account already signed in. */
  join(studioSlug: string, marketingEmail?: boolean): Promise<void>;
  chooseStudio(slug: string | null): Promise<void>;
  refreshUser(): Promise<User | null>;
  signOut(): Promise<void>;
};

const SessionContext = createContext<SessionValue | null>(null);

/** Each sign-in creates a token named after the device, so members can tell their sign-ins apart. */
export function deviceName(): string {
  const name = Constants.deviceName;
  return (name ? `${name} (Loft app)` : `Loft app on ${Platform.OS}`).slice(0, 100);
}

export function SessionProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient();
  const [ready, setReady] = useState(false);
  const [user, setUser] = useState<User | null>(null);
  const [studioSlug, setStudioSlug] = useState<string | null>(null);

  const forget = useCallback(async () => {
    setToken(null);
    setUser(null);
    setStudioSlug(null);
    queryClient.clear();
    await Promise.all([storage.remove(TOKEN_KEY), storage.remove(STUDIO_KEY)]);
  }, [queryClient]);

  const refreshUser = useCallback(async () => {
    try {
      const me = await request<{ data: User }>('me');
      setUser(me.data);
      return me.data;
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        await forget();
        return null;
      }
      throw e;
    }
  }, [forget]);

  useEffect(() => {
    setUnauthorizedHandler(() => {
      void forget();
    });
    (async () => {
      const [saved, slug] = await Promise.all([storage.get(TOKEN_KEY), storage.get(STUDIO_KEY)]);
      if (saved) {
        setToken(saved);
        setStudioSlug(slug);
        try {
          await refreshUser();
        } catch {
          // offline: keep the token and try again later
        }
      }
      setReady(true);
    })();
    return () => setUnauthorizedHandler(null);
  }, [forget, refreshUser]);

  const chooseStudio = useCallback(async (slug: string | null) => {
    setStudioSlug(slug);
    if (slug) await storage.set(STUDIO_KEY, slug);
    else await storage.remove(STUDIO_KEY);
  }, []);

  const keep = useCallback(async (issued: { token: string; user: User }) => {
    setToken(issued.token);
    await storage.set(TOKEN_KEY, issued.token);
    setUser(issued.user);
    return issued.user;
  }, []);

  const value = useMemo<SessionValue>(
    () => ({
      ready,
      user,
      studioSlug,
      async signIn(email, password) {
        const res = await request<{ data: { token: string; user: User } }>('auth/tokens', {
          method: 'POST',
          body: { email: email.trim(), password, device_name: deviceName() },
          auth: false,
        });
        return keep(res.data);
      },
      async register(slug, fields) {
        const res = await request<{ data: { token: string; user: User } }>(`studios/${slug}/register`, {
          method: 'POST',
          body: { ...fields, email: fields.email.trim(), device_name: deviceName() },
          auth: false,
        });
        const signedIn = await keep(res.data);
        await chooseStudio(slug);
        return signedIn;
      },
      async join(slug, marketingEmail = false) {
        await request(`studios/${slug}/join`, { method: 'POST', body: { marketing_email: marketingEmail } });
        await refreshUser();
      },
      chooseStudio,
      refreshUser,
      async signOut() {
        try {
          await request('auth/tokens/current', { method: 'DELETE' });
        } catch {
          // the token goes from this device either way
        }
        await forget();
      },
    }),
    [ready, user, studioSlug, keep, chooseStudio, refreshUser, forget],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): SessionValue {
  const value = useContext(SessionContext);
  if (!value) throw new Error('useSession needs SessionProvider');
  return value;
}
