import { Tabs } from 'expo-router';

import { useCurrentStudio } from '@/src/api/studio';
import { Icon, StudioMark, useTheme } from '@/src/ui';

export default function StudioTabs() {
  const { c, accent } = useTheme();
  const studio = useCurrentStudio();
  return (
    <Tabs
      screenOptions={{
        tabBarActiveTintColor: accent.text,
        tabBarInactiveTintColor: c.muted,
        tabBarStyle: { backgroundColor: c.surface, borderTopColor: c.line },
        headerStyle: { backgroundColor: c.bg },
        headerTintColor: c.ink,
        headerShadowVisible: false,
        sceneStyle: { backgroundColor: c.bg },
      }}>
      <Tabs.Screen
        name="index"
        options={{
          title: 'Schedule',
          headerTitle: studio ? () => <StudioMark studio={studio} height={30} maxWidth={180} withName /> : 'Schedule',
          tabBarIcon: ({ color }) => <Icon name="schedule" color={color} />,
        }}
      />
      <Tabs.Screen name="classes" options={{ title: 'My classes', tabBarIcon: ({ color }) => <Icon name="classes" color={color} /> }} />
      <Tabs.Screen name="videos" options={{ title: 'Videos', tabBarIcon: ({ color }) => <Icon name="videos" color={color} /> }} />
      <Tabs.Screen name="account" options={{ title: 'Account', tabBarIcon: ({ color }) => <Icon name="account" color={color} /> }} />
    </Tabs>
  );
}
