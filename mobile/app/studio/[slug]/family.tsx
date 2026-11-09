import { router } from 'expo-router';
import { View } from 'react-native';

import { useAttendees, useWaivers } from '@/src/api/hooks';
import { useSlug } from '@/src/api/studio';
import { Badge, Button, Card, Divider, Empty, ErrorView, Loading, Muted, Row, Screen } from '@/src/ui';

/** The people on the account, and any waivers they still need to sign. */
export default function Family() {
  const slug = useSlug();
  const attendees = useAttendees(slug);
  const waivers = useWaivers(slug);
  if (attendees.isLoading) return <Loading />;
  if (attendees.error) return <Screen><ErrorView error={attendees.error} onRetry={() => attendees.refetch()} /></Screen>;

  const needs = (id: number) => (waivers.data ?? []).filter((w) => w.attendees.some((a) => a.attendee_id === id && a.needed)).length;

  return (
    <Screen onRefresh={() => { void attendees.refetch(); void waivers.refetch(); }} refreshing={attendees.isRefetching}>
      <Muted>Everyone you book classes for: you, your children, anyone in your care.</Muted>
      {(attendees.data ?? []).length === 0 ? <Empty>No one yet. Add yourself first.</Empty> : (
        <Card style={{ paddingVertical: 4 }}>
          {(attendees.data ?? []).map((a, i) => {
            const n = needs(a.id);
            return (
              <View key={a.id}>
                {i > 0 ? <Divider /> : null}
                <Row
                  title={`${a.first_name} ${a.last_name}`}
                  subtitle={[a.is_self ? 'You' : null, a.dob ? `Born ${a.dob}` : null].filter(Boolean).join(' · ') || undefined}
                  onPress={n ? () => router.push({ pathname: '/studio/[slug]/waiver', params: { slug, attendee: String(a.id) } }) : undefined}
                  right={n ? <Badge label={`${n} to sign`} tone="warn" /> : <Badge label="Waivers signed" tone="ok" />}
                />
              </View>
            );
          })}
        </Card>
      )}
      <Button title="Add someone" icon="plus" variant="secondary" onPress={() => router.push(`/studio/${slug}/add-person`)} />
    </Screen>
  );
}
