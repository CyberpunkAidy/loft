import { router } from 'expo-router';
import { Image, Pressable, View } from 'react-native';

import { useVideos } from '@/src/api/hooks';
import { useSlug } from '@/src/api/studio';
import { Badge, Empty, ErrorView, Heading, Icon, Loading, Muted, Notice, Screen, useTheme } from '@/src/ui';
import { radius, space } from '@/src/ui/theme';

/** The studio's video library: what the member can watch, and what needs a plan. */
export default function Videos() {
  const slug = useSlug();
  const videos = useVideos(slug);
  const { c } = useTheme();
  if (videos.isLoading) return <Loading />;
  if (videos.error) return <Screen><ErrorView error={videos.error} onRetry={() => videos.refetch()} /></Screen>;
  const list = videos.data?.data ?? [];
  const locked = list.some((v) => v.locked_reason === 'needs_plan');
  const plans = videos.data?.meta.plans ?? [];

  return (
    <Screen onRefresh={() => videos.refetch()} refreshing={videos.isRefetching}>
      {list.length === 0 ? <Empty>No videos yet.</Empty> : null}
      {locked && plans.length ? <Notice>Some videos come with {plans.map((p) => p.name).join(' or ')}. Ask the studio about it.</Notice> : null}
      {list.map((v) => (
        <Pressable
          key={v.id}
          accessibilityRole="button"
          accessibilityLabel={`${v.title}${v.can_watch ? '' : ', locked'}`}
          onPress={() => router.push(`/studio/${slug}/video/${v.id}`)}
          style={({ pressed }) => ({ backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: 1, borderColor: c.line, overflow: 'hidden', opacity: pressed ? 0.85 : 1 })}>
          <View style={{ aspectRatio: 16 / 9, backgroundColor: c.soft }}>
            {v.thumbnail_url ? <Image source={{ uri: v.thumbnail_url }} style={{ width: '100%', height: '100%' }} resizeMode="cover" /> : null}
            <View style={{ position: 'absolute', left: 0, right: 0, top: 0, bottom: 0, alignItems: 'center', justifyContent: 'center' }}>
              <View style={{ width: 52, height: 52, borderRadius: 26, backgroundColor: 'rgba(23,20,31,0.62)', alignItems: 'center', justifyContent: 'center' }}>
                <Icon name={v.can_watch ? 'play' : 'lock'} color="#fff" size={22} />
              </View>
            </View>
            {v.duration_label ? (
              <View style={{ position: 'absolute', right: 8, bottom: 8, backgroundColor: 'rgba(23,20,31,0.78)', borderRadius: 6, paddingHorizontal: 6, paddingVertical: 2 }}>
                <Muted style={{ color: '#fff', fontSize: 12, fontWeight: '600' }}>{v.duration_label}</Muted>
              </View>
            ) : null}
          </View>
          <View style={{ padding: space.lg, gap: 4 }}>
            <Heading>{v.title}</Heading>
            {v.description ? <Muted numberOfLines={2}>{v.description}</Muted> : null}
            {v.locked_reason === 'needs_plan' ? <Badge label="With a plan" /> : null}
          </View>
        </Pressable>
      ))}
    </Screen>
  );
}
