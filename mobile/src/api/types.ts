/** What the Loft API (v1) returns. See the wiki's API page; new fields can appear, so extra ones are ignored. */

export type Studio = {
  id: number;
  slug: string;
  name: string;
  timezone: string;
  currency: string;
  accent_color: string;
  /** The studio's logo (decision 41), or null: show its initial instead. */
  logo: StudioLogo | null;
  contact_email: string | null;
  website_url: string;
  online_payments: boolean;
  policies: {
    late_cancel_hours: number;
    booking_opens_days_before: number;
    booking_closes_minutes_before: number;
    minor_age: number;
    /** The questions the studio asks of each person (decision 45): either, both or neither. */
    questions: Question[];
  };
};

/** One of the studio's questions: injuries and health, or photos and video. */
export type Question = 'health' | 'photos';

/** The photo question's answers: yes (social media too), only inside the studio, or no. */
export type PhotoConsent = 'public' | 'studio' | 'none';

export type Membership = {
  id: number;
  slug: string;
  name: string;
  role: 'member' | 'instructor' | 'admin' | 'owner';
  timezone: string;
  currency: string;
  accent_color: string;
  logo: StudioLogo | null;
};

/** A studio's logo, served for good from its URL; width and height give its shape. */
export type StudioLogo = { url: string; width: number; height: number };

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
  /** Part of a series (decision 47); drop_ins false: only people signed up for it book it. */
  series?: { id: number; name: string; drop_ins: boolean } | null;
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
  /** The health question: null until answered, then whether medical_notes has anything. */
  has_health_notes: boolean | null;
  health_answered_at: string | null;
  photo_consent: PhotoConsent | null;
  photo_consent_at: string | null;
  /** Staff recorded the photo answer from what the member told them (decision 46), and how they heard. */
  photo_consent_by_studio?: boolean;
  photo_consent_note?: string | null;
  /** Questions to answer before booking (an older API leaves this out: treat as none). */
  questions_needed?: Question[];
};

/** For each person on the account: can they book this class, and if not, why. */
export type BookingOption = {
  attendee: Attendee;
  booking: { id: number; status: string; position: number | null } | null;
  can_book: boolean;
  reason: string | null;
  message: string | null;
  /** With reason questions_required: what to ask first. */
  questions?: Question[];
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

/** A run of classes sold as one (decision 47), and what signing up now costs. */
export type Series = {
  id: number;
  name: string;
  program: { id: number; title: string; excerpt: string | null; schedule: string };
  starts_on: string;
  ends_on: string;
  dates: string;
  currency: string;
  price_cents: number;
  price_now_cents: number;
  classes_total: number;
  classes_left: number;
  spots_left: number;
  open: boolean;
  closed_message: string | null;
  join_until_class: number;
  makeups: boolean;
  drop_ins: boolean;
  url: string;
  classes?: { session_id: number; starts_at: string; starts_at_local: string; done: boolean }[];
  mine?: { attendee_id: number; status: 'pending' | 'active' }[];
};

export type SeriesSignup = { signed_up: boolean; checkout_id: string | null; checkout_url: string | null; return_url: string };

