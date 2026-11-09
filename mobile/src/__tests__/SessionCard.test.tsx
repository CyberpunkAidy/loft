import { fireEvent, render, screen } from '@testing-library/react-native';

import type { Session } from '../api/types';
import { SessionCard } from '../ui/SessionCard';

function session(overrides: Partial<Session> = {}): Session {
  return {
    id: 1, status: 'scheduled',
    starts_at: '2026-11-10T23:30:00Z', ends_at: '2026-11-11T00:30:00Z',
    starts_at_local: '2026-11-10T16:30:00-07:00', ends_at_local: '2026-11-10T17:30:00-07:00',
    program: { id: 1, title: 'Kids Aerial Silks', description: null, min_age: null, max_age: null, members_only: false, categories: [] },
    instructor: { id: 2, name: 'Cam Rivera' }, room: { id: 3, name: 'Main studio' },
    drop_in_price_cents: 2500, currency: 'usd', delivery: 'in_person',
    capacity: 8, spots_left: 6, online_capacity: null, online_spots_left: null, is_full: false,
    waitlist: { count: 0, capacity: null, is_full: false }, apparatus: [], booking_opens_at: null, mine: [],
    ...overrides,
  };
}

it('shows when, who and where, in the studio’s time, and opens the class', async () => {
  const open = jest.fn();
  await render(<SessionCard session={session()} timeZone="America/Denver" onPress={open} />);
  expect(screen.getByText('4:30 PM – 5:30 PM · Cam · Main studio')).toBeTruthy();
  expect(screen.getByText('6 spots left')).toBeTruthy();
  await fireEvent.press(screen.getByLabelText('Kids Aerial Silks, 4:30 PM'));
  expect(open).toHaveBeenCalled();
});

it('says what the member has booked, and when a class is full', async () => {
  const { rerender } = await render(
    <SessionCard session={session({ mine: [{ booking_id: 9, attendee_id: 1, status: 'booked', position: null }] })} timeZone="America/Denver" onPress={() => {}} />,
  );
  expect(screen.getByText('Booked')).toBeTruthy();

  await rerender(<SessionCard session={session({ mine: [{ booking_id: 9, attendee_id: 1, status: 'waitlisted', position: 2 }], is_full: true })} timeZone="America/Denver" onPress={() => {}} />);
  expect(screen.getByText('Waitlist #2')).toBeTruthy();

  await rerender(<SessionCard session={session({ is_full: true, spots_left: 0 })} timeZone="America/Denver" onPress={() => {}} />);
  expect(screen.getByText('Full · waitlist open')).toBeTruthy();

  await rerender(<SessionCard session={session({ is_full: true, spots_left: 0, waitlist: { count: 3, capacity: 3, is_full: true } })} timeZone="America/Denver" onPress={() => {}} />);
  expect(screen.getByText('Full')).toBeTruthy();
});

it('marks online and hybrid classes, and a hybrid class with room online', async () => {
  const { rerender } = await render(<SessionCard session={session({ delivery: 'online', room: { id: 3, name: 'Main studio' } })} timeZone="America/Denver" onPress={() => {}} />);
  expect(screen.getByText('Online')).toBeTruthy();
  expect(screen.queryByText(/Main studio/)).toBeNull();

  await rerender(<SessionCard session={session({ delivery: 'hybrid', is_full: true, online_spots_left: 4 })} timeZone="America/Denver" onPress={() => {}} />);
  expect(screen.getByText('In studio or online')).toBeTruthy();
  expect(screen.getByText('Studio full · online open')).toBeTruthy();
});
