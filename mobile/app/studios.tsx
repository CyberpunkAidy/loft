import { router } from 'expo-router';
import { View } from 'react-native';

import { useSession } from '@/src/api/session';
import { Button, Card, Divider, Empty, Muted, Row, Screen } from '@/src/ui';

/** The studios this account belongs to; pick one, or add another. */
export default function Studios() {
  const { user, chooseStudio, signOut } = useSession();
  const studios = user?.studios ?? [];

  async function open(slug: string) {
    await chooseStudio(slug);
    router.replace(`/studio/${slug}`);
  }

  return (
    <Screen>
      {user ? <Muted>Signed in as {user.email}</Muted> : null}
      {studios.length === 0 ? <Empty>You’re not a member of a studio yet.</Empty> : (
        <Card style={{ paddingVertical: 4 }}>
          {studios.map((s, i) => (
            <View key={s.id}>
              {i > 0 ? <Divider /> : null}
              <Row title={s.name} subtitle={s.role === 'member' ? 'Member' : `Staff (${s.role})`} onPress={() => open(s.slug)} />
            </View>
          ))}
        </Card>
      )}
      <Button title="Add a studio" variant="secondary" icon="plus" onPress={() => router.push('/find')} />
      <Button title="Sign out" variant="link" onPress={async () => { await signOut(); router.replace('/welcome'); }} />
    </Screen>
  );
}
