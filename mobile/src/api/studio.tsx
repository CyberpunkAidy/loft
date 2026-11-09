import { createContext, ReactNode, useContext } from 'react';

import { AccentProvider } from '../ui/theme';
import { useStudio } from './hooks';
import type { Studio } from './types';

const StudioContext = createContext<{ slug: string; studio: Studio | null } | null>(null);

/**
 * The studio in the URL (/studio/[slug]/…): its slug and details for every screen under it (tabs
 * can't read their parent's URL parameters), and its color for the theme.
 */
export function StudioProvider({ slug, children }: { slug: string; children: ReactNode }) {
  const studio = useStudio(slug);
  return (
    <StudioContext.Provider value={{ slug, studio: studio.data ?? null }}>
      <AccentProvider color={studio.data?.accent_color}>{children}</AccentProvider>
    </StudioContext.Provider>
  );
}

/** The current studio's slug. */
export function useSlug(): string {
  const value = useContext(StudioContext);
  if (!value) throw new Error('useSlug needs StudioProvider');
  return value.slug;
}

/** The current studio, once loaded. */
export function useCurrentStudio(): Studio | null {
  return useContext(StudioContext)?.studio ?? null;
}
