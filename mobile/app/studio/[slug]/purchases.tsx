import * as WebBrowser from 'expo-web-browser';
import { router } from 'expo-router';
import { View } from 'react-native';

import { useEntitlements, useOrders } from '@/src/api/hooks';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import { money } from '@/src/lib/money';
import { dayOf } from '@/src/lib/time';
import { Badge, Button, Card, Divider, Empty, ErrorView, Heading, Loading, Muted, Screen } from '@/src/ui';
import { space } from '@/src/ui/theme';

/** Memberships and class credits, then every purchase. */
export default function Purchases() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const entitlements = useEntitlements(slug);
  const orders = useOrders(slug);
  if (!studio || entitlements.isLoading) return <Loading />;
  const tz = studio.timezone;
  const current = (entitlements.data ?? []).filter((e) => ['active', 'past_due'].includes(e.status));

  return (
    <Screen onRefresh={() => { void entitlements.refetch(); void orders.refetch(); }} refreshing={entitlements.isRefetching}>
      <Heading>What you have</Heading>
      {entitlements.error ? <ErrorView error={entitlements.error} onRetry={() => entitlements.refetch()} /> : current.length === 0 ? (
        <Empty>No membership or class credits right now.</Empty>
      ) : (
        current.map((e) => (
          <Card key={e.id}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: space.sm }}>
              <Heading style={{ flex: 1, fontSize: 16 }}>{e.plan?.name ?? 'Plan'}</Heading>
              <Badge label={e.status_label} tone={e.status === 'past_due' ? 'err' : 'ok'} />
            </View>
            <Muted>
              {[
                e.credits_remaining !== null ? `${e.credits_remaining} of ${e.credits_total} classes left` : e.weekly_limit ? `Up to ${e.weekly_limit} classes a week` : 'Unlimited classes',
                e.expires_at ? `until ${dayOf(e.expires_at, tz)}` : e.current_period_end ? `renews ${dayOf(e.current_period_end, tz)}` : null,
              ].filter(Boolean).join(' · ')}
            </Muted>
            {e.status === 'past_due' ? <Muted>The last payment failed. Update your card on the website to keep booking.</Muted> : null}
          </Card>
        ))
      )}
      <Button title="Buy a plan" variant="secondary" onPress={() => router.push(`/studio/${slug}/plans`)} />

      <Heading style={{ marginTop: space.md }}>Purchases</Heading>
      {orders.isLoading ? <Loading /> : (orders.data ?? []).length === 0 ? <Empty>No purchases yet.</Empty> : (
        <Card style={{ paddingVertical: space.sm }}>
          {(orders.data ?? []).map((o, i) => (
            <View key={o.id}>
              {i > 0 ? <Divider /> : null}
              <View style={{ paddingVertical: space.md, gap: 4 }}>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', gap: space.sm }}>
                  <Heading style={{ flex: 1, fontSize: 15 }}>{o.items.map((i) => i.description).join(' + ') || `Order #${o.number}`}</Heading>
                  <Heading style={{ fontSize: 15 }}>{o.status === 'refunded' ? 'Refunded' : o.status === 'void' ? 'Void' : o.total_label}</Heading>
                </View>
                <Muted>
                  {[o.paid_at ? dayOf(o.paid_at, tz) : null, `Order #${o.number}`, o.refunded_cents && o.status === 'paid' ? `${money(o.refunded_cents, studio.currency)} refunded` : null, o.fulfillment_status === 'to_pick_up' ? 'Ready to pick up' : null].filter(Boolean).join(' · ')}
                </Muted>
              </View>
            </View>
          ))}
        </Card>
      )}
      <Button title="Cards, invoices and cancelling: on the website" variant="link" onPress={() => WebBrowser.openBrowserAsync(`${studio.website_url}/account/billing`)} />
    </Screen>
  );
}
