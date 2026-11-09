import { useQuery } from '@tanstack/react-query';

import { request } from './client';
import type {
  Attendee, Booking, CheckInCode, Entitlement, Order, Paged, Plan, Session, SessionDetail, Studio, User, Video, Waiver,
} from './types';

/** Query keys, so a change (a booking, say) can refresh what it affects. */
export const keys = {
  me: ['me'] as const,
  studio: (slug: string) => ['studio', slug] as const,
  sessions: (slug: string, from: string) => ['sessions', slug, from] as const,
  session: (slug: string, id: number) => ['session', slug, id] as const,
  bookings: (slug: string, when: 'upcoming' | 'past') => ['bookings', slug, when] as const,
  attendees: (slug: string) => ['attendees', slug] as const,
  waivers: (slug: string) => ['waivers', slug] as const,
  plans: (slug: string) => ['plans', slug] as const,
  entitlements: (slug: string) => ['entitlements', slug] as const,
  orders: (slug: string) => ['orders', slug] as const,
  videos: (slug: string) => ['videos', slug] as const,
  checkInCode: (slug: string, attendee: number) => ['check-in-code', slug, attendee] as const,
};

export function useMe() {
  return useQuery({ queryKey: keys.me, queryFn: () => request<{ data: User }>('me').then((r) => r.data) });
}

export function useStudio(slug: string) {
  return useQuery({
    queryKey: keys.studio(slug),
    queryFn: () => request<{ data: Studio }>(`studios/${slug}`, { auth: false }).then((r) => r.data),
    staleTime: 10 * 60_000,
  });
}

/** A week of classes from $from (the studio's date), with the member's own bookings in each. */
export function useSessions(slug: string, from: string, days = 7) {
  return useQuery({
    queryKey: keys.sessions(slug, from),
    queryFn: () => request<Paged<Session>>(`studios/${slug}/sessions`, { query: { from, days, per_page: 100 } }).then((r) => r.data),
  });
}

/** One class, with each person's booking options. No request when $id is 0 (nothing to show). */
export function useSessionDetail(slug: string, id: number) {
  return useQuery({ queryKey: keys.session(slug, id), queryFn: () => request<SessionDetail>(`studios/${slug}/sessions/${id}`), enabled: id > 0 });
}

export function useBookings(slug: string, when: 'upcoming' | 'past') {
  return useQuery({
    queryKey: keys.bookings(slug, when),
    queryFn: () => request<Paged<Booking>>(`studios/${slug}/bookings`, { query: { when, per_page: 100 } }).then((r) => r.data),
  });
}

export function useAttendees(slug: string) {
  return useQuery({ queryKey: keys.attendees(slug), queryFn: () => request<{ data: Attendee[] }>(`studios/${slug}/attendees`).then((r) => r.data) });
}

export function useWaivers(slug: string) {
  return useQuery({ queryKey: keys.waivers(slug), queryFn: () => request<{ data: Waiver[] }>(`studios/${slug}/waivers`).then((r) => r.data) });
}

export function usePlans(slug: string) {
  return useQuery({ queryKey: keys.plans(slug), queryFn: () => request<{ data: Plan[] }>(`studios/${slug}/plans`, { auth: false }).then((r) => r.data) });
}

export function useEntitlements(slug: string) {
  return useQuery({ queryKey: keys.entitlements(slug), queryFn: () => request<{ data: Entitlement[] }>(`studios/${slug}/entitlements`).then((r) => r.data) });
}

export function useOrders(slug: string) {
  return useQuery({ queryKey: keys.orders(slug), queryFn: () => request<Paged<Order>>(`studios/${slug}/orders`).then((r) => r.data) });
}

export function useVideos(slug: string) {
  return useQuery({ queryKey: keys.videos(slug), queryFn: () => request<{ data: Video[]; meta: { plans: Plan[] } }>(`studios/${slug}/videos`) });
}

/** A person's check-in code, fetched again when it's about to change (every 30 seconds). */
export function useCheckInCode(slug: string, attendeeId: number) {
  return useQuery({
    queryKey: keys.checkInCode(slug, attendeeId),
    queryFn: () => request<{ data: CheckInCode }>(`studios/${slug}/attendees/${attendeeId}/check-in-code`).then((r) => r.data),
    refetchInterval: (query) => Math.max(1, query.state.data?.refresh_in ?? 30) * 1000,
    refetchIntervalInBackground: false,
  });
}
