import { router } from 'expo-router';
import { View } from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';
import Svg, { Circle, Defs, LinearGradient, Path, Rect, Stop } from 'react-native-svg';

import { Body, Button, Title, useTheme } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** The Loft mark, as on the website. */
export function LoftMark({ size = 56 }: { size?: number }) {
  return (
    <Svg width={size} height={size} viewBox="0 0 48 48" accessibilityLabel="Loft">
      <Defs>
        <LinearGradient id="g" x1="0" y1="0" x2="48" y2="48" gradientUnits="userSpaceOnUse">
          <Stop offset="0" stopColor="#5b4bf5" />
          <Stop offset="0.55" stopColor="#c05ad8" />
          <Stop offset="1" stopColor="#ff8a5c" />
        </LinearGradient>
      </Defs>
      <Rect width="48" height="48" rx="13" fill="url(#g)" />
      <Path d="M12.5 36.5C12.8 27 17.6 19.6 26.6 16.6" fill="none" stroke="#fff" strokeWidth="4.6" strokeLinecap="round" />
      <Circle cx="35.2" cy="14.2" r="4.4" fill="#fff" />
    </Svg>
  );
}

export default function Welcome() {
  const { c } = useTheme();
  return (
    <SafeAreaView style={{ flex: 1, backgroundColor: c.bg }}>
      <View style={{ flex: 1, padding: space.xl, justifyContent: 'space-between' }}>
        <View style={{ gap: space.lg, marginTop: space.xxl * 2 }}>
          <LoftMark size={64} />
          <Title style={{ fontSize: 34 }}>Your classes, in your pocket</Title>
          <Body>Book classes for you and your family, check in at the door, and watch your studio’s videos.</Body>
        </View>
        <View style={{ gap: space.md }}>
          <Button title="Find my studio" onPress={() => router.push('/find')} />
          <Button title="I already have an account" variant="secondary" onPress={() => router.push('/sign-in')} />
        </View>
      </View>
    </SafeAreaView>
  );
}
