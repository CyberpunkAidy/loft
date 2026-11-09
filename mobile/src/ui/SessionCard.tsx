import { Pressable, View } from 'react-native';

import type { Session } from '../api/types';
import { timeOf } from '../lib/time';
import { Badge, Body, Heading, Muted, useTheme } from './index';
import { radius, space } from './theme';

const ACTIVE = ['booked', 'attended', 'waitlisted'];

/** A class in the schedule: when, what, where, and whether there's room. */
export function SessionCard({ session, timeZone, onPress }: { session: Session; timeZone: string; onPress: () => void }) {
  const { c } = useTheme();
  const mine = (session.mine ?? []).filter((b) => ACTIVE.includes(b.status));
  const booked = mine.some((b) => b.status !== 'waitlisted');
  const waiting = mine.find((b) => b.status === 'waitlisted');
  const onlineOpen = session.delivery === 'hybrid' && session.is_full && session.online_spots_left !== 0;

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`${session.program.title}, ${timeOf(session.starts_at, timeZone)}`}
      onPress={onPress}
      style={({ pressed }) => ({
        backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line,
        padding: space.lg, gap: 6, opacity: pressed ? 0.8 : 1,
      })}>
      <Muted>
        {timeOf(session.starts_at, timeZone)} – {timeOf(session.ends_at, timeZone)}
        {session.instructor ? ` · ${session.instructor.name.split(' ')[0]}` : ''}
        {session.delivery !== 'online' && session.room ? ` · ${session.room.name}` : ''}
      </Muted>
      <Heading>{session.program.title}</Heading>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6 }}>
        {session.delivery === 'online' ? <Badge label="Online" tone="info" /> : session.delivery === 'hybrid' ? <Badge label="In studio or online" tone="info" /> : null}
        {booked ? <Badge label="Booked" tone="accent" /> : waiting ? <Badge label={`Waitlist #${waiting.position ?? ''}`.trim()} tone="warn" /> : null}
        {!booked && !waiting ? (
          onlineOpen ? <Badge label="Studio full · online open" tone="warn" />
            : session.is_full ? <Badge label={session.waitlist.is_full ? 'Full' : session.waitlist.count ? `Full · ${session.waitlist.count} waiting` : 'Full · waitlist open'} tone="err" />
              : <Badge label={`${session.spots_left} ${session.spots_left === 1 ? 'spot' : 'spots'} left`} tone={session.spots_left <= 2 ? 'warn' : 'ok'} />
        ) : null}
      </View>
      {session.program.members_only ? <Body style={{ fontSize: 13, color: c.muted }}>Members only</Body> : null}
    </Pressable>
  );
}
