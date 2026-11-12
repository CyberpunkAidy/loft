import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import { router, useLocalSearchParams } from 'expo-router';
import * as WebBrowser from 'expo-web-browser';
import { useState } from 'react';
import { View } from 'react-native';

import { ApiError, request } from '@/src/api/client';
import { useAttendees, useSeries } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { SeriesSignup } from '@/src/api/types';
import { money } from '@/src/lib/money';
import { longDayOf, timeOf } from '@/src/lib/time';
import { Badge, Body, Button, Card, Choice, Divider, ErrorView, Heading, Loading, Muted, Notice, Screen, Title } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** A series (decision 47): its classes and price, and signing someone up for all of it. */
export default function SeriesScreen() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const { id } = useLocalSearchParams<{ id: string }>();
  const series = useSeries(slug, Number(id));
  const attendees = useAttendees(slug);
  const queryClient = useQueryClient();
  const [who, setWho] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'warn' | 'err'; text: string } | null>(null);

  if (series.isLoading || attendees.isLoading || !studio) return <Loading />;
  if (series.error || !series.data) return <Screen><ErrorView error={series.error} onRetry={() => series.refetch()} /></Screen>;

  const s = series.data;
  const tz = studio.timezone;
  const signedUp = (attendeeId: number) => s.mine?.find((m) => m.attendee_id === attendeeId)?.status;
  const people = attendees.data ?? [];
  const late = s.classes_left < s.classes_total;

  async function signUp(attendeeId: number) {
    setBusy(true);
    setNotice(null);
    try {
      const res = (await request<{ data: SeriesSignup }>(`studios/${slug}/series/${s.id}/signups`, {
        method: 'POST',
        body: { attendee_id: attendeeId },
        idempotencyKey: Crypto.randomUUID(),
      })).data;
      if (res.checkout_url) {
        await WebBrowser.openAuthSessionAsync(res.checkout_url, res.return_url);
        const result = res.checkout_id ? (await request<{ data: { fulfilled: boolean; status: string | null; payment_status: string | null } }>(`studios/${slug}/checkouts/${res.checkout_id}`)).data : null;
        setNotice(result?.fulfilled
          ? { tone: 'ok', text: `You’re signed up for ${s.name}, and booked into every class.` }
          : result?.status === 'complete'
            ? { tone: 'warn', text: 'Your payment is processing. Your spot is held, and we’ll email you when it goes through.' }
            : { tone: 'warn', text: 'Checkout wasn’t finished, so nothing was charged.' });
      } else {
        setNotice({ tone: 'ok', text: `You’re signed up for ${s.name}, and booked into every class.` });
      }
      await queryClient.invalidateQueries();
    } catch (e) {
      if (e instanceof ApiError && e.code === 'questions_required') {
        router.push({ pathname: '/studio/[slug]/person', params: { slug, attendee: String(attendeeId), ask: '1' } });
      } else if (e instanceof ApiError && e.code === 'waiver_required') {
        router.push({ pathname: '/studio/[slug]/waiver', params: { slug, attendee: String(attendeeId), waiver: String(e.details.waiver_id ?? '') } });
      } else {
        setNotice({ tone: 'err', text: (e as Error).message });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <Screen onRefresh={() => series.refetch()} refreshing={series.isRefetching}>
      <View style={{ gap: 6 }}>
        <Badge label="Series" tone="accent" />
        <Title>{s.name}</Title>
        <Body>{s.dates} · {s.program.schedule}</Body>
        {s.program.excerpt ? <Muted>{s.program.excerpt}</Muted> : null}
      </View>
      <Card style={{ gap: space.sm }}>
        <Heading>{money(s.price_now_cents, s.currency)} <Muted>for {late ? `${s.classes_left} of ${s.classes_total}` : `all ${s.classes_total}`} classes</Muted></Heading>
        {late && s.open ? <Muted>It’s already started, so you pay for the classes left.</Muted> : null}
        <Muted>Sign up once and you’re booked into every class.{s.makeups ? ' Cancel in time and you get a make-up class for another day.' : ''}</Muted>
        {s.classes_left ? <Badge label={`${s.spots_left} ${s.spots_left === 1 ? 'spot' : 'spots'} left`} tone={s.spots_left <= 2 ? 'warn' : 'ok'} /> : null}
      </Card>
      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}

      {!s.open ? (
        <Notice tone="warn">{s.closed_message ?? 'Sign-ups are closed.'}</Notice>
      ) : (
        <View style={{ gap: space.sm }}>
          <Heading>Who’s signing up?</Heading>
          {people.length === 0 ? <Muted>Add the people you book for under Account → Family.</Muted> : null}
          {people.map((a) => {
            const status = signedUp(a.id);
            return (
              <Choice
                key={a.id}
                title={`${a.first_name} ${a.last_name}`}
                subtitle={status === 'active' ? 'Already signed up' : status === 'pending' ? 'Paying now' : undefined}
                selected={who === a.id}
                disabled={!!status}
                onPress={() => setWho(a.id)}
              />
            );
          })}
          <Button title={s.price_now_cents > 0 ? `Sign up and pay ${money(s.price_now_cents, s.currency)}` : 'Sign up'} onPress={() => who && signUp(who)} loading={busy} disabled={!who} />
        </View>
      )}

      <Heading>Classes</Heading>
      <Card style={{ paddingVertical: space.xs }}>
        {(s.classes ?? []).map((c, i) => (
          <View key={c.session_id}>
            {i > 0 ? <Divider /> : null}
            <View style={{ paddingVertical: space.sm, flexDirection: 'row', justifyContent: 'space-between' }}>
              <Body style={c.done ? { opacity: 0.5 } : undefined}>{i + 1}. {longDayOf(c.starts_at, tz)} · {timeOf(c.starts_at, tz)}</Body>
              {c.done ? <Muted>Done</Muted> : null}
            </View>
          </View>
        ))}
      </Card>
      <View style={{ height: space.lg }} />
    </Screen>
  );
}
