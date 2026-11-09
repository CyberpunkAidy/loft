import { createContext, ReactNode, useContext, useMemo } from 'react';
import { useColorScheme } from 'react-native';

import { inkOn, mix, textOn, tint, validAccent } from '../lib/color';

/** The website's palette (public/css/app.css), and a dark one to match the phone's setting. */
export const palettes = {
  light: {
    bg: '#f7f6f3', surface: '#ffffff', surface2: '#faf9f7', soft: '#f1efeb', line: '#e9e6e0', lineStrong: '#d9d5cd',
    ink: '#17141f', ink2: '#3a3545', muted: '#6c6776', faint: '#9c97a5',
    ok: '#137a47', okBg: '#e8f6ee', warn: '#9a5400', warnBg: '#fff4de', err: '#be1f2a', errBg: '#fdeeee', info: '#2c50cc', infoBg: '#edf1ff',
  },
  dark: {
    bg: '#121018', surface: '#1c1924', surface2: '#221f2b', soft: '#2a2633', line: '#2f2b39', lineStrong: '#3d3848',
    ink: '#f4f2f7', ink2: '#d9d5e2', muted: '#a39fae', faint: '#7b7686',
    ok: '#5fd197', okBg: '#16301f', warn: '#f2b45a', warnBg: '#33240e', err: '#ff8a8f', errBg: '#3a1517', info: '#9db1ff', infoBg: '#1a2244',
  },
};

export type Palette = typeof palettes.light;

/** Loft's own violet until a studio is chosen. */
export const LOFT_ACCENT = '#5b4bf5';

const AccentContext = createContext<string>(LOFT_ACCENT);

/** Everything inside uses $color (a studio's brand color) for buttons, links and highlights. */
export function AccentProvider({ color, children }: { color: string | null | undefined; children: ReactNode }) {
  return <AccentContext.Provider value={validAccent(color)}>{children}</AccentContext.Provider>;
}

export function useTheme() {
  const scheme = useColorScheme() === 'dark' ? 'dark' : 'light';
  const accent = useContext(AccentContext);

  return useMemo(() => {
    const c = palettes[scheme];
    return {
      scheme,
      c,
      accent: {
        color: accent,
        ink: inkOn(accent),
        text: scheme === 'dark' ? tint(accent, 0.6) : textOn(accent),
        soft: scheme === 'dark' ? mix(accent, c.surface, 0.22) : tint(accent, 0.1),
      },
    };
  }, [scheme, accent]);
}

export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 24, xxl: 32 };
export const radius = { sm: 10, md: 14, lg: 20, pill: 999 };
