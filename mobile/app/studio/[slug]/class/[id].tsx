import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { router, useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { keys, useSessionDetail } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { Booking, BookingOption, Plan } from '@/src/api/types';
import { confirm } from '@/src/lib/confirm';
import { money } from '@/src/lib/money';
import { longDayOf, timeOf } from '@/src/lib/time';
import { Badge, Body, Button, Card, Choice, Divider, ErrorView, Heading, Loading, Muted, Notice, Screen, Title } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** One class: its details, and for each person on the account, booking it (or why they can't). */
export default function ClassScreen() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const { id } = useLocalSearchParams<{ id: string }>();
  const sessionId = Number(id);
  const detail = useSessionDetail(slug, sessionId);
  const queryClient = useQueryClient();
  const [online, setOnline] = useState<boolean | null>(null);
  const [apparatus, setApparatus] = useState<number | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'err' | 'warn'; text: string } | null>(null);

  if (detail.isLoading || !studio) return <Loading />;
  if (detail.error || !detail.data) return <Screen><ErrorView error={detail.error} onRetry={() => detail.refetch()} /></Screen>;

  const s = detail.data.data;
  const options = detail.data.booking;
  const tz = studio.timezone;
  const hybrid = s.delivery === 'hybrid';
  const studioFull = s.is_full && s.waitlist.is_full;
  const goOnline = hybrid && (online ?? (studioFull && s.online_spots_left !== 0));

  async function refresh() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: keys.session(slug, sessionId) }),
      queryClient.invalidateQueries({ queryKey: ['sessions', slug] }),
      queryClient.invalidateQueries({ queryKey: ['bookings', slug] }),
    ]);
  }

  async function book(option: BookingOption) {
    setBusy(option.attendee.id);
    setNotice(null);
    try {
      const res = await request<{ data: Booking; message: string }>(`studios/${slug}/sessions/${sessionId}/bookings`, {
        method: 'POST',
        body: { attendee_id: option.attendee.id, apparatus_id: goOnline ? null : apparatus, online: goOnline },
        idempotencyKey: Crypto.randomUUID(),
      });
      setNotice({ tone: res.data.status === 'waitlisted' ? 'warn' : 'ok', text: res.message });
      await refresh();
    } catch (e) {
      if (e instanceof ApiError && e.code === 'questions_required') {
        router.push({ pathname: '/studio/[slug]/person', params: { slug, attendee: String(option.attendee.id), ask: '1' } });
      } else if (e instanceof ApiError && e.code === 'waiver_required') {
        router.push({ pathname: '/studio/[slug]/waiver', params: { slug, attendee: String(option.attendee.id), waiver: String(e.details.waiver_id ?? '') } });
      } else if (e instanceof ApiError && e.code === 'needs_plan') {
        router.push({
          pathname: '/studio/[slug]/plans',
          params: {
            slug,
            session: String(sessionId),
            attendee: String(option.attendee.id),
            ...(goOnline ? { online: '1' } : apparatus ? { apparatus: String(apparatus) } : {}),
            offered: ((e.details.plans as Plan[] | undefined) ?? []).map((p) => p.id).join(','),
          },
        });
      } else {
        setNotice({ tone: 'err', text: (e as Error).message });
      }
    } finally {
      setBusy(null);
    }
  }

  async function cancel(option: BookingOption) {
    if (!option.booking) return;
    const late = (new Date(s.starts_at).getTime() - Date.now()) / 3_600_000 < studio!.policies.late_cancel_hours;
    const ok = await confirm(
      `Cancel ${option.attendee.first_name}’s booking?`,
      late ? `It’s within ${studio!.policies.late_cancel_hours} hours of class, so a class credit may not come back.` : 'Any class credit used comes back to your account.',
      'Cancel booking',
      true,
    );
    if (!ok) return;
    setBusy(option.attendee.id);
    try {
      const res = await request<{ message: string }>(`studios/${slug}/bookings/${option.booking.id}`, { method: 'DELETE' });
      setNotice({ tone: 'ok', text: res.message });
      await refresh();
    } catch (e) {
      setNotice({ tone: 'err', text: (e as Error).message });
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen onRefresh={() => detail.refetch()} refreshing={detail.isRefetching}>
      <View style={{ gap: 6 }}>
        <Title>{s.program.title}</Title>
        <Body>
          {longDayOf(s.starts_at, tz)} · {timeOf(s.starts_at, tz)} – {timeOf(s.ends_at, tz)}
        </Body>
        <Muted>
          {[s.instructor?.name, s.delivery === 'online' ? 'Online' : s.room?.name, hybrid ? 'In the studio or online' : null].filter(Boolean).join(' · ')}
        </Muted>
        <View style={{ flexDirection: 'row', gap: 6, flexWrap: 'wrap', marginTop: 4 }}>
          {s.is_full ? <Badge label={s.waitlist.is_full ? 'Full' : s.waitlist.count ? `Full · ${s.waitlist.count} on the waitlist` : 'Full · waitlist open'} tone="err" /> : <Badge label={`${s.spots_left} ${s.spots_left === 1 ? 'spot' : 'spots'} left`} tone="ok" />}
          {s.drop_in_price_cents ? <Badge label={`Drop-in ${money(s.drop_in_price_cents, s.currency)}`} /> : null}
          {s.program.members_only ? <Badge label="Members only" /> : null}
        </View>
      </View>

      {s.program.description ? <Body>{s.program.description}</Body> : null}
      {s.delivery === 'online' ? <Notice>This class is online. The link to join appears under My classes 15 minutes before it starts.</Notice> : null}
      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}

      {!options ? null : options.closed && options.closed_code === 'series_only' && s.series ? (
        <View style={{ gap: space.sm }}>
          <Notice>{options.closed_message ?? 'This class is part of a series.'}</Notice>
          <Button title="See the series" onPress={() => router.push({ pathname: '/studio/[slug]/series/[id]', params: { slug, id: String(s.series!.id) } })} />
        </View>
      ) : options.closed ? (
        <Notice tone="warn">{options.closed_message ?? 'Booking is closed for this class.'}</Notice>
      ) : (
        <>
          {hybrid ? (
            <View style={{ gap: space.sm }}>
              <Heading>Where</Heading>
              <Choice
                title="In the studio"
                subtitle={studioFull ? 'Full' : s.is_full ? 'Full. You’ll join the waitlist.' : `${s.spots_left} ${s.spots_left === 1 ? 'spot' : 'spots'} left`}
                selected={!goOnline}
                disabled={studioFull}
                onPress={() => setOnline(false)}
              />
              <Choice
                title="Online"
                subtitle={`${s.online_spots_left === null ? 'Join from home' : s.online_spots_left === 0 ? 'Full' : `${s.online_spots_left} ${s.online_spots_left === 1 ? 'spot' : 'spots'} left`}. The link appears 15 minutes before class.`}
                selected={goOnline}
                disabled={s.online_spots_left === 0}
                onPress={() => setOnline(true)}
              />
            </View>
          ) : null}

          {!goOnline && s.apparatus.length > 1 ? (
            <View style={{ gap: space.sm }}>
              <Heading>Apparatus</Heading>
              <Choice title="No preference" subtitle="Whichever has the most room" selected={apparatus === null} onPress={() => setApparatus(null)} />
              {s.apparatus.map((a) => (
                <Choice key={a.id} title={a.name} subtitle={a.spots_left > 0 ? `${a.spots_left} of ${a.spots} left` : 'Full. You’ll join the waitlist for it.'} selected={apparatus === a.id} onPress={() => setApparatus(a.id)} />
              ))}
            </View>
          ) : null}

          <Heading>Who’s coming?</Heading>
          <Card style={{ paddingVertical: space.sm }}>
            {options.attendees.length === 0 ? <Muted>Add the people you book for under Account → Family.</Muted> : null}
            {options.attendees.map((o, i) => (
              <View key={o.attendee.id}>
                {i > 0 ? <Divider /> : null}
                <View style={{ paddingVertical: space.md, gap: space.sm }}>
                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: space.sm }}>
                    <Heading style={{ fontSize: 16, flex: 1 }}>{o.attendee.first_name} {o.attendee.last_name}</Heading>
                    {o.booking ? <Badge label={o.booking.status === 'waitlisted' ? `Waitlist #${o.booking.position ?? ''}` : 'Booked'} tone={o.booking.status === 'waitlisted' ? 'warn' : 'accent'} /> : null}
                  </View>
                  {o.booking ? (
                    ['booked', 'waitlisted'].includes(o.booking.status) && new Date(s.starts_at).getTime() > Date.now() ? (
                      <Button title="Cancel booking" variant="link" onPress={() => cancel(o)} loading={busy === o.attendee.id} />
                    ) : null
                  ) : o.can_book ? (
                    <Button title={s.is_full && !goOnline ? 'Join the waitlist' : `Book ${o.attendee.first_name}`} small onPress={() => book(o)} loading={busy === o.attendee.id} />
                  ) : o.reason === 'questions_required' ? (
                    <>
                      <Muted>{o.message}</Muted>
                      <Button title="Answer the questions" small variant="secondary" onPress={() => router.push({ pathname: '/studio/[slug]/person', params: { slug, attendee: String(o.attendee.id), ask: '1' } })} />
                    </>
                  ) : o.reason === 'waiver_required' ? (
                    <>
                      <Muted>{o.message}</Muted>
                      <Button title="Sign the waiver" small variant="secondary" icon="waiver" onPress={() => router.push({ pathname: '/studio/[slug]/waiver', params: { slug, attendee: String(o.attendee.id), waiver: String(o.waiver_id ?? '') } })} />
                    </>
                  ) : (
                    <Muted>{o.message}</Muted>
                  )}
                </View>
              </View>
            ))}
          </Card>
        </>
      )}
    </Screen>
  );
}
