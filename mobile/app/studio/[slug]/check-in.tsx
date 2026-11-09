import { useState } from 'react';
import { Pressable, Text, View } from 'react-native';
import QRCode from 'react-native-qrcode-svg';

import { useAttendees, useCheckInCode } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import { Body, Card, Empty, ErrorView, Heading, Loading, Muted, Screen, useTheme } from '@/src/ui';
import { radius, space } from '@/src/ui/theme';

/** A rotating QR code per person, to scan at the studio's kiosk. It changes every 30 seconds. */
export default function CheckIn() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const attendees = useAttendees(slug);
  const { c, accent } = useTheme();
  const [chosen, setChosen] = useState<number | null>(null);

  if (attendees.isLoading) return <Loading />;
  if (attendees.error) return <Screen><ErrorView error={attendees.error} onRetry={() => attendees.refetch()} /></Screen>;
  const people = attendees.data ?? [];
  if (people.length === 0) return <Screen><Empty>Add someone under Family first.</Empty></Screen>;
  const person = people.find((a) => a.id === chosen) ?? people.find((a) => a.is_self) ?? people[0];

  return (
    <Screen>
      {people.length > 1 ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: space.sm }}>
          {people.map((a) => {
            const on = a.id === person.id;
            return (
              <Pressable
                key={a.id}
                accessibilityRole="button"
                accessibilityState={{ selected: on }}
                onPress={() => setChosen(a.id)}
                style={{ paddingHorizontal: 14, paddingVertical: 8, borderRadius: radius.pill, borderWidth: 1, borderColor: on ? accent.color : c.line, backgroundColor: on ? accent.soft : c.surface }}>
                <Text style={{ fontWeight: '600', color: on ? accent.text : c.ink2 }}>{a.first_name}</Text>
              </Pressable>
            );
          })}
        </View>
      ) : null}
      <Code slug={slug} attendeeId={person.id} name={`${person.first_name} ${person.last_name}`} />
      <Muted style={{ textAlign: 'center' }}>
        Hold it up to the scanner at {studio?.name ?? 'the studio'}. It changes every 30 seconds, so a screenshot won’t work for long.
      </Muted>
    </Screen>
  );
}

function Code({ slug, attendeeId, name }: { slug: string; attendeeId: number; name: string }) {
  const code = useCheckInCode(slug, attendeeId);
  return (
    <Card style={{ alignItems: 'center', gap: space.md, paddingVertical: space.xl }}>
      <Heading>{name}</Heading>
      {code.data ? (
        <View style={{ backgroundColor: '#fff', padding: space.md, borderRadius: radius.md }} accessibilityLabel={`Check-in code for ${name}`}>
          <QRCode value={code.data.code} size={230} />
        </View>
      ) : code.error ? (
        <ErrorView error={code.error} onRetry={() => code.refetch()} />
      ) : (
        <View style={{ height: 254, justifyContent: 'center' }}><Loading /></View>
      )}
      <Body style={{ fontSize: 13 }}>{code.isFetching ? 'Refreshing…' : ' '}</Body>
    </Card>
  );
}
