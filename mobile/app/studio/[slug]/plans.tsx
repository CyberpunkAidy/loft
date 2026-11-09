import { useQueryClient } from '@tanstack/react-query';
import * as Crypto from 'expo-crypto';
import * as WebBrowser from 'expo-web-browser';
import { useLocalSearchParams } from 'expo-router';
import { useState } from 'react';
import { View } from 'react-native';

import { request } from '@/src/api/client';
import { usePlans, useSessionDetail } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import type { Checkout, CheckoutResult, Plan } from '@/src/api/types';
import { Badge, Body, Button, Card, Empty, ErrorView, Heading, Loading, Muted, Notice, Screen } from '@/src/ui';
import { space } from '@/src/ui/theme';

/**
 * Plans the studio sells, paid on Stripe's Checkout page in an in-app browser. Opened from a class
 * the member couldn't book, it shows the plans that cover it and finishes the booking once paid.
 * Plans that include videos aren't sold in the app (decision 33).
 */
export default function Plans() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const params = useLocalSearchParams<{ session?: string; attendee?: string; apparatus?: string; online?: string; offered?: string }>();
  const plans = usePlans(slug);
  const forSession = params.session ? Number(params.session) : null;
  const session = useSessionDetail(slug, forSession ?? 0);
  const queryClient = useQueryClient();
  const [busy, setBusy] = useState<number | null>(null);
  const [notice, setNotice] = useState<{ tone: 'ok' | 'warn' | 'err'; text: string } | null>(null);

  if (plans.isLoading || !studio) return <Loading />;
  if (plans.error) return <Screen><ErrorView error={plans.error} onRetry={() => plans.refetch()} /></Screen>;

  const offered = params.offered ? params.offered.split(',').map(Number) : null;
  const list = (plans.data ?? []).filter((p) => !offered || offered.includes(p.id));
  const attendee = forSession ? session.data?.booking?.attendees.find((o) => String(o.attendee.id) === params.attendee)?.attendee : null;

  async function buy(plan: Plan) {
    setBusy(plan.id);
    setNotice(null);
    try {
      const started = await request<{ data: Checkout }>(`studios/${slug}/checkouts`, {
        method: 'POST',
        body: {
          plan_id: plan.id,
          session_id: forSession,
          attendee_id: params.attendee ? Number(params.attendee) : null,
          apparatus_id: params.apparatus ? Number(params.apparatus) : null,
          online: params.online === '1',
        },
        idempotencyKey: Crypto.randomUUID(),
      });
      await WebBrowser.openAuthSessionAsync(started.data.url, started.data.return_url);
      const result = (await request<{ data: CheckoutResult }>(`studios/${slug}/checkouts/${started.data.id}`)).data;
      if (result.fulfilled) {
        setNotice({
          tone: 'ok',
          text: result.booking
            ? `Thank you! ${result.booking.attendee.first_name} is ${result.booking.status === 'waitlisted' ? 'on the waitlist' : 'booked'} for ${result.booking.session.title}.`
            : `Thank you! ${result.entitlement?.plan?.name ?? plan.name} is on your account.`,
        });
        if (result.booking_error) setNotice({ tone: 'warn', text: `Your plan is ready, but the booking didn’t go through: ${result.booking_error}` });
        await queryClient.invalidateQueries();
      } else if (result.status === 'complete' && result.payment_status === 'unpaid') {
        setNotice({ tone: 'warn', text: 'Your payment is processing. Bank payments take a few days; the plan appears when it clears.' });
      } else {
        setNotice({ tone: 'warn', text: 'Checkout wasn’t finished, so nothing was charged.' });
      }
    } catch (e) {
      setNotice({ tone: 'err', text: (e as Error).message });
    } finally {
      setBusy(null);
    }
  }

  return (
    <Screen>
      {forSession && session.data ? (
        <Notice>
          {attendee ? `${attendee.first_name} needs` : 'You need'} a plan that covers {session.data.data.program.title}. Once it’s paid, the booking goes through.
        </Notice>
      ) : null}
      {notice ? <Notice tone={notice.tone}>{notice.text}</Notice> : null}
      {list.length === 0 ? <Empty>Nothing for sale right now. Ask the studio.</Empty> : null}
      {list.map((p) => (
        <Card key={p.id}>
          <Badge label={p.kind_label} tone="accent" />
          <Heading>{p.name}</Heading>
          <View style={{ flexDirection: 'row', alignItems: 'baseline', gap: space.sm, flexWrap: 'wrap' }}>
            <Heading style={{ fontSize: 26 }}>{p.price_label}</Heading>
            <Muted>{p.detail_label}</Muted>
          </View>
          {p.description ? <Body>{p.description}</Body> : null}
          {p.includes_videos ? (
            <Muted>Includes videos, so it isn’t sold in the app. Ask {studio.name} about it.</Muted>
          ) : !p.can_buy_online ? (
            <Muted>Available at the front desk.</Muted>
          ) : (
            <Button title={`Choose ${p.name}`} onPress={() => buy(p)} loading={busy === p.id} disabled={busy !== null && busy !== p.id} />
          )}
        </Card>
      ))}
      <Muted>Payments are taken by Stripe for {studio.name}. Your card details never reach the app.</Muted>
    </Screen>
  );
}
