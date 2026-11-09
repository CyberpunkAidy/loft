/** What the Loft API (v1) returns. See the wiki's API page; new fields can appear, so extra ones are ignored. */

export type Studio = {
  id: number;
  slug: string;
  name: string;
  timezone: string;
  currency: string;
  accent_color: string;
  contact_email: string | null;
  website_url: string;
  online_payments: boolean;
  policies: {
    late_cancel_hours: number;
    booking_opens_days_before: number;
    booking_closes_minutes_before: number;
    minor_age: number;
  };
};

export type Membership = {
  id: number;
  slug: string;
  name: string;
  role: 'member' | 'instructor' | 'admin' | 'owner';
  timezone: string;
  currency: string;
  accent_color: string;
};

export type User = {
  id: number;
  first_name: string;
  last_name: string;
  email: string;
  phone: string | null;
  studios?: Membership[];
};

export type Delivery = 'in_person' | 'online' | 'hybrid';

export type Session = {
  id: number;
  status: string;
  starts_at: string;
  ends_at: string;
  starts_at_local: string;
  ends_at_local: string;
  program: {
    id: number;
    title: string;
    description: string | null;
    min_age: number | null;
    max_age: number | null;
    members_only: boolean;
    categories: { type: string; slug: string; name: string }[];
  };
  instructor: { id: number; name: string } | null;
  room: { id: number; name: string } | null;
  drop_in_price_cents: number | null;
  currency: string;
  delivery: Delivery;
  capacity: number;
  spots_left: number;
  online_capacity: number | null;
  online_spots_left: number | null;
  is_full: boolean;
  waitlist: { count: number; capacity: number | null; is_full: boolean };
  apparatus: { id: number; name: string; spots: number; spots_left: number }[];
  booking_opens_at: string | null;
  mine?: { booking_id: number; attendee_id: number; status: string; position: number | null }[];
};

export type Attendee = {
  id: number;
  first_name: string;
  last_name: string;
  dob: string | null;
  is_self: boolean;
  is_minor: boolean;
  emergency_contact: string | null;
  medical_notes: string | null;
};

/** For each person on the account: can they book this class, and if not, why. */
export type BookingOption = {
  attendee: Attendee;
  booking: { id: number; status: string; position: number | null } | null;
  can_book: boolean;
  reason: string | null;
  message: string | null;
  waiver_id: number | null;
};

export type SessionDetail = {
  data: Session;
  booking?: {
    closed: boolean;
    closed_code: string | null;
    closed_message: string | null;
    attendees: BookingOption[];
  };
};

export type Booking = {
  id: number;
  status: 'booked' | 'waitlisted' | 'attended' | 'cancelled' | 'late_cancelled' | 'no_show' | string;
  status_label: string;
  position: number | null;
  apparatus: string | null;
  online: boolean;
  join_opens_at: string | null;
  attendee: { id: number; first_name: string; last_name: string };
  session: {
    id: number;
    title: string;
    status: string;
    delivery: Delivery;
    starts_at: string;
    ends_at: string;
    starts_at_local: string;
    room: string | null;
  };
  booked_at: string | null;
  cancelled_at: string | null;
  can_cancel: boolean;
};

export type Plan = {
  id: number;
  name: string;
  kind: 'subscription' | 'season' | 'pack' | 'dropin';
  kind_label: string;
  description: string | null;
  price_cents: number;
  currency: string;
  price_label: string;
  detail_label: string;
  billing_interval: string | null;
  credits: number | null;
  includes_videos?: boolean;
  can_buy_online: boolean;
};

export type Entitlement = {
  id: number;
  kind: string;
  status: string;
  status_label: string;
  plan: { id: number; name: string; kind: string } | null;
  credits_total: number | null;
  credits_remaining: number | null;
  weekly_limit: number | null;
  expires_at: string | null;
  current_period_end: string | null;
};

export type Order = {
  id: number;
  number: number;
  status: 'paid' | 'void' | 'refunded' | string;
  channel: string;
  total_cents: number;
  total_label: string;
  refunded_cents?: number;
  paid_at: string | null;
  fulfillment_status: string | null;
  items: { kind: string; description: string; quantity: number; total_cents: number }[];
};

export type Waiver = {
  id: number;
  title: string;
  version: number;
  body: string;
  applies_to_every_class: boolean;
  programs: { id: number; title: string }[];
  attendees: {
    attendee_id: number;
    signed: boolean;
    expired: boolean;
    signed_at: string | null;
    expires_at: string | null;
    needed: boolean;
  }[];
};

export type CheckInCode = { attendee_id: number; code: string; expires_at: string; refresh_in: number };

export type Checkout = { id: string; url: string; expires_at: string | null; return_url: string };

export type CheckoutResult = {
  id: string;
  status: 'open' | 'complete' | 'expired' | null;
  payment_status: 'paid' | 'unpaid' | 'no_payment_required' | null;
  fulfilled: boolean;
  entitlement: Entitlement | null;
  booking: Booking | null;
  booking_error: string | null;
};

export type Video = {
  id: number;
  title: string;
  description: string | null;
  duration_seconds: number | null;
  duration_label: string | null;
  aspect_ratio: string | null;
  access: 'members' | 'plan';
  thumbnail_url: string | null;
  published_at: string | null;
  can_watch: boolean;
  locked_reason: 'sign_in' | 'not_a_member' | 'needs_plan' | null;
};

export type Playback = {
  video_id: number;
  playback_id: string;
  tokens: { playback: string; thumbnail: string; storyboard: string };
  stream_url: string;
  expires_at: string;
};

export type Paged<T> = { data: T[]; meta?: { next_cursor?: string | null } };
