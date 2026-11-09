import { useQuery } from '@tanstack/react-query';
import { useLocalSearchParams } from 'expo-router';
import { useVideoPlayer, VideoView } from 'expo-video';
import { View } from 'react-native';

import { request } from '@/src/api/client';
import { useVideos } from '@/src/api/hooks';
import { useSlug } from '@/src/api/studio';
import type { Playback } from '@/src/api/types';
import { Body, ErrorView, Loading, Muted, Notice, Screen, Title } from '@/src/ui';

/** Play a video: signed tokens from Loft, streamed by Mux. Asking for them counts as a view. */
export default function WatchVideo() {
  const slug = useSlug();
  const { id } = useLocalSearchParams<{ id: string }>();
  const videos = useVideos(slug);
  const video = videos.data?.data.find((v) => String(v.id) === id);
  const playback = useQuery({
    queryKey: ['playback', slug, id],
    queryFn: () => request<{ data: Playback }>(`studios/${slug}/videos/${id}/playback`, { method: 'POST' }).then((r) => r.data),
    enabled: !!video?.can_watch,
    staleTime: 60 * 60_000,
  });

  if (videos.isLoading) return <Loading />;
  if (!video) return <Screen><Notice tone="warn">That video isn’t in the library any more.</Notice></Screen>;

  return (
    <Screen>
      {video.can_watch ? (
        playback.data ? <Player url={playback.data.stream_url} ratio={video.aspect_ratio} /> : playback.error ? <ErrorView error={playback.error} onRetry={() => playback.refetch()} /> : <Loading />
      ) : (
        <Notice tone="warn">{video.locked_reason === 'needs_plan' ? 'This video comes with a plan that includes videos. Ask the studio about it.' : 'You can’t watch this one yet.'}</Notice>
      )}
      <Title>{video.title}</Title>
      {video.duration_label ? <Muted>{video.duration_label}</Muted> : null}
      {video.description ? <Body>{video.description}</Body> : null}
    </Screen>
  );
}

function Player({ url, ratio }: { url: string; ratio: string | null }) {
  const player = useVideoPlayer(url, (p) => {
    p.play();
  });
  const [w, h] = (ratio ?? '16:9').split(':').map(Number);
  return (
    <View style={{ aspectRatio: w && h ? w / h : 16 / 9, backgroundColor: '#000', borderRadius: 16, overflow: 'hidden' }}>
      <VideoView player={player} style={{ width: '100%', height: '100%' }} allowsPictureInPicture nativeControls contentFit="contain" />
    </View>
  );
}
