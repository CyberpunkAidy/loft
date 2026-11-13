import * as WebBrowser from 'expo-web-browser';
import { router } from 'expo-router';
import { Linking, View } from 'react-native';

import { useSession } from '@/src/api/session';
import { WEB_URL } from '@/src/config';
import { useCurrentStudio, useSlug } from '@/src/api/studio';
import { confirm } from '@/src/lib/confirm';
import { Card, Divider, Loading, Muted, Row, Screen, Title } from '@/src/ui';

export default function Account() {
  const slug = useSlug();
  const studio = useCurrentStudio();
  const { user, signOut } = useSession();
  if (!studio || !user) return <Loading />;
  const studios = user.studios ?? [];

  async function leave() {
    if (await confirm('Sign out?', 'You’ll need your email and password to sign in again.', 'Sign out')) {
      await signOut();
      router.replace('/welcome');
    }
  }

  return (
    <Screen>
      <View style={{ gap: 4 }}>
        <Title>{user.first_name} {user.last_name}</Title>
        <Muted>{user.email} · {studio.name}</Muted>
      </View>

      <Card style={{ paddingVertical: 4 }}>
        <Row icon="qr" title="Check-in code" subtitle="Scan it at the studio’s kiosk" onPress={() => router.push(`/studio/${slug}/check-in`)} />
        <Divider />
        <Row icon="family" title="Family" subtitle="The people you book for" onPress={() => router.push(`/studio/${slug}/family`)} />
        <Divider />
        <Row icon="card" title="Plans & purchases" subtitle="Memberships, class credits and receipts" onPress={() => router.push(`/studio/${slug}/purchases`)} />
        <Divider />
        <Row icon="receipt" title="Buy a plan" onPress={() => router.push(`/studio/${slug}/plans`)} />
        {studio.texts ? (
          <>
            <Divider />
            <Row icon="text" title="Text messages" subtitle="Class reminders and updates by text" onPress={() => router.push(`/studio/${slug}/texts`)} />
          </>
        ) : null}
      </Card>

      <Card style={{ paddingVertical: 4 }}>
        {studio.contact_email ? (
          <>
            <Row icon="mail" title={`Contact ${studio.name}`} subtitle={studio.contact_email} onPress={() => Linking.openURL(`mailto:${studio.contact_email}`)} />
            <Divider />
          </>
        ) : null}
        <Row icon="web" title="Studio website" subtitle="Your profile, cards and invoices" onPress={() => WebBrowser.openBrowserAsync(studio.website_url)} />
        {studios.length > 1 ? (
          <>
            <Divider />
            <Row icon="swap" title="Switch studio" subtitle={`${studios.length} studios`} onPress={() => router.push('/studios')} />
          </>
        ) : (
          <>
            <Divider />
            <Row icon="plus" title="Add another studio" onPress={() => router.push('/find')} />
          </>
        )}
      </Card>

      <Card style={{ paddingVertical: 4 }}>
        <Row icon="signOut" title="Sign out" onPress={leave} />
        <Divider />
        <Row
          icon="trash"
          title="Delete my account"
          subtitle="On the website: Profile → Delete account"
          onPress={() => WebBrowser.openBrowserAsync(`${studio.website_url}/account/profile`)}
        />
      </Card>

      <Card style={{ paddingVertical: 4 }}>
        <Row title="Privacy Policy" onPress={() => WebBrowser.openBrowserAsync(`${WEB_URL}/privacy`)} />
        <Divider />
        <Row title="Terms of Service" onPress={() => WebBrowser.openBrowserAsync(`${WEB_URL}/terms`)} />
      </Card>
    </Screen>
  );
}
