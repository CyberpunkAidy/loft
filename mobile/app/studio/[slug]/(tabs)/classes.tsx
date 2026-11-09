import { useQueryClient } from '@tanstack/react-query';
import { router } from 'expo-router';
import { useState } from 'react';
import { Linking, Pressable, Text, View } from 'react-native';

import { request } from '@/src/api/client';
import { useBookings } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { Booking } from '@/src/api/types';
import { confirm } from '@/src/lib/confirm';
import { dayOf, isPast, timeOf } from '@/src/lib/time';
import { Badge, Button, Card, Empty, ErrorView, Heading, Loading, Muted, Notice, Screen, useTheme } from '@/src/ui';
import { radius, space } from '@/src/ui/theme';

/** Upcoming and past classes for everyone on the account: cancel, or join one that's online. */
export default function MyClasses() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const { c } = useTheme();
  const [when, setWhen] = useState<'upcoming' | 'past'>('upcoming');
  const bookings = useBookings(slug, when);
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'err'; text: string } | null>(null);

  if (!studio) return <Loading />;
  const tz = studio.timezone;

  async function cancel(b: Booking) {
    const ok = await confirm(`Cancel ${b.attendee.first_name}’s booking?`, `${b.session.title}, ${dayOf(b.session.starts_at, tz)} at ${timeOf(b.session.starts_at, tz)}.`, 'Cancel booking', true);
    if (!ok) return;
    setBusy(b.id);
    try {
      const res = await request<{ message: string }>(`studios/${slug}/bookings/${b.id}`, { method: 'DELETE' });
      setNotice({ tone: 'ok', text: res.message });
      await Promise.all([queryClient.invalidateQueries({ queryKey: ['bookings', slug] }), queryClient.invalidateQueries({ queryKey: ['sessions', slug] })]);
    } catch (e) {
      setNotice({ tone: 'err', text: (e as Error).message });
    } finally {
      setBusy(null);
    }
  }

  async function join(b: Booking) {
    setBusy(b.id);
    try {
      const res = await request<{ data: { url: string } }>(`studios/${slug}/bookings/${b.id}/join`, { method: 'POST' });
      await Linking.openURL(res.data.url);
      void queryClient.invalidateQueries({ queryKey: ['bookings', slug] });
    } catch (e) {
      setNotice({ tone: 'err', text: (e as Error).message });
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen onRefresh={() => bookings.refetch()} refreshing={bookings.isRefetching}>
      <View accessibilityRole="tablist" style={{ flexDirection: 'row', backgroundColor: c.soft, borderRadius: radius.pill, padding: 4 }}>
        {(['upcoming', 'past'] as const).map((w) => (
          <Pressable
            key={w}
            accessibilityRole="tab"
            accessibilityState={{ selected: when === w }}
            onPress={() => setWhen(w)}
            style={{ flex: 1, paddingVertical: 8, borderRadius: radius.pill, backgroundColor: when === w ? c.surface : 'transparent', alignItems: 'center' }}>
            <Text style={{ fontWeight: '600', color: when === w ? c.ink : c.muted }}>{w === 'upcoming' ? 'Upcoming' : 'Past'}</Text>
          </Pressable>
        ))}
      </View>
      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
      {bookings.isLoading ? <Loading /> : bookings.error ? <ErrorView error={bookings.error} onRetry={() => bookings.refetch()} /> : (bookings.data ?? []).length === 0 ? (
        <Empty>{when === 'upcoming' ? 'Nothing booked yet. Find a class on the Schedule.' : 'No past classes yet.'}</Empty>
      ) : (
        (bookings.data ?? []).map((b) => {
          const live = ['booked', 'attended'].includes(b.status) && b.online && !isPast(b.session.ends_at);
          const canJoin = live && isPast(b.join_opens_at);
          return (
            <Card key={b.id}>
              <Pressable onPress={() => router.push(`/studio/${slug}/class/${b.session.id}`)} style={{ gap: 4 }}>
                <Muted>{dayOf(b.session.starts_at, tz)} · {timeOf(b.session.starts_at, tz)}</Muted>
                <Heading>{b.session.title}</Heading>
                <Muted>{[b.attendee.first_name, b.apparatus, b.online ? 'Online' : b.session.room].filter(Boolean).join(' · ')}</Muted>
              </Pressable>
              <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: 6, alignItems: 'center' }}>
                <Badge
                  label={b.status === 'waitlisted' ? `Waitlist #${b.position ?? ''}` : b.status === 'attended' ? 'Checked in' : b.status_label}
                  tone={b.status === 'waitlisted' ? 'warn' : b.status === 'booked' ? 'accent' : b.status === 'attended' ? 'ok' : 'muted'}
                />
                {b.session.status === 'cancelled' ? <Badge label="Cancelled by the studio" tone="err" /> : null}
              </View>
              {canJoin ? <Button title="Join online" icon="video" onPress={() => join(b)} loading={busy === b.id} /> : null}
              {live && !canJoin && b.join_opens_at ? <Muted>The link to join appears here at {timeOf(b.join_opens_at, tz)}.</Muted> : null}
              {b.can_cancel ? <Button title="Cancel booking" variant="link" onPress={() => cancel(b)} loading={busy === b.id && !canJoin} /> : null}
            </Card>
          );
        })
      )}
      {when === 'upcoming' ? <Muted>Cancel at least {studio.policies.late_cancel_hours} hours before class to get a class credit back.</Muted> : null}
    </Screen>
  );
}
