import { Redirect } from 'expo-router';

import { useSession } from '@/src/api/session';
import { Loading } from '@/src/ui';

/** Where the app opens: the last studio, the studio list, or the welcome screen. */
export default function Start() {
  const { ready, user, studioSlug } = useSession();
  if (!ready) return <Loading />;
  if (!user) return <Redirect href="/welcome" />;
  const studios = user.studios ?? [];
  const slug = studioSlug && studios.some((s) => s.slug === studioSlug) ? studioSlug : studios.length === 1 ? studios[0].slug : null;
  if (slug) return <Redirect href={`/studio/${slug}`} />;
  return <Redirect href={studios.length ? '/studios' : '/find'} />;
}
