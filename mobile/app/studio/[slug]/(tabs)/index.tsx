import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { View } from 'react-native';

import { useSessions } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import { addDays, dateKey, labelOfDate } from '@/src/lib/time';
import { Button, Empty, ErrorView, Heading, Loading, Muted, Screen } from '@/src/ui';
import { SessionCard } from '@/src/ui/SessionCard';
import { space } from '@/src/ui/theme';

/** A week of classes at a time, by day, in the studio's time zone. */
export default function Schedule() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const timeZone = studio?.timezone ?? 'UTC';
  const today = dateKey(new Date(), timeZone);
  const [from, setFrom] = useState(today);
  const sessions = useSessions(slug, from);

  const days = useMemo(() => {
    const byDay = new Map<string, NonNullable<typeof sessions.data>>();
    for (const s of sessions.data ?? []) {
      const key = dateKey(s.starts_at, timeZone);
      byDay.set(key, [...(byDay.get(key) ?? []), s]);
    }
    return [...byDay.entries()];
  }, [sessions.data, timeZone]);

  if (!studio) return <Loading />;

  return (
    <Screen onRefresh={() => sessions.refetch()} refreshing={sessions.isRefetching}>
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
        <Button title="‹ Earlier" variant="link" disabled={from <= today} onPress={() => setFrom(addDays(from, -7))} />
        <Muted>{labelOfDate(from)} – {labelOfDate(addDays(from, 6))}</Muted>
        <Button title="Later ›" variant="link" onPress={() => setFrom(addDays(from, 7))} />
      </View>
      {sessions.isLoading ? <Loading /> : sessions.error ? <ErrorView error={sessions.error} onRetry={() => sessions.refetch()} /> : days.length === 0 ? (
        <Empty>No classes this week. Try the next one.</Empty>
      ) : (
        days.map(([day, list]) => (
          <View key={day} style={{ gap: space.sm, marginTop: space.sm }}>
            <Heading>{day === today ? 'Today' : day === addDays(today, 1) ? 'Tomorrow' : labelOfDate(day)}</Heading>
            {list.map((s) => (
              <SessionCard key={s.id} session={s} timeZone={timeZone} onPress={() => router.push(`/studio/${slug}/class/${s.id}`)} />
            ))}
          </View>
        ))
      )}
    </Screen>
  );
}
