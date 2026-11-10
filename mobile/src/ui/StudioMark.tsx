import { Image, Text, View } from 'react-native';

import type { Studio } from '../api/types';
import { useTheme } from './theme';

type Brand = Pick<Studio, 'name' | 'accent_color' | 'logo'>;

/**
 * The studio's mark (decision 41): its logo at $height if it has one, otherwise its initial on its
 * brand color. A wide logo (twice as wide as tall) usually spells out the name, so with $withName
 * the name is shown only beside a square logo or the initial.
 */
export function StudioMark({ studio, height = 44, maxWidth = 200, withName = false }: { studio: Brand; height?: number; maxWidth?: number; withName?: boolean }) {
  const { c } = useTheme();
  const logo = studio.logo;
  const wide = !!logo && logo.width >= 2 * logo.height;
  let mark;
  if (logo) {
    const ratio = logo.width / logo.height;
    const width = Math.min(maxWidth, Math.round(height * ratio));
    mark = (
      <Image
        source={{ uri: logo.url }}
        style={{ width, height: Math.round(width / ratio) }}
        resizeMode="contain"
        accessibilityLabel={wide ? studio.name : undefined}
        accessible={wide}
      />
    );
  } else {
    mark = (
      <View style={{ width: height, height, borderRadius: Math.round(height * 0.27), backgroundColor: studio.accent_color, alignItems: 'center', justifyContent: 'center' }}>
        <Text style={{ color: '#fff', fontSize: Math.round(height * 0.42), fontWeight: '700' }}>{studio.name.slice(0, 1).toUpperCase()}</Text>
      </View>
    );
  }
  if (!withName || wide) {
    return mark;
  }

  return (
    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 10, flexShrink: 1 }}>
      {mark}
      <Text numberOfLines={1} style={{ fontSize: 17, fontWeight: '600', color: c.ink, letterSpacing: -0.2, flexShrink: 1 }}>{studio.name}</Text>
    </View>
  );
}
