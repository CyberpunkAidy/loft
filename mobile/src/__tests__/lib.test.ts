import { inkOn, mix, textOn, validAccent } from '../lib/color';
import { money } from '../lib/money';
import { studioSlugFrom } from '../lib/studioAddress';
import { addDays, dateKey, dayOf, labelOfDate, timeOf } from '../lib/time';

describe('studio addresses', () => {
  it.each([
    ['skyline', 'skyline'],
    ['  Skyline ', 'skyline'],
    ['loftstudio.app/skyline', 'skyline'],
    ['https://loftstudio.app/skyline/classes/4', 'skyline'],
    ['http://loftstudio.app/sky-line?x=1', 'sky-line'],
    ['loftstudio.app', null],
    ['', null],
    ['-bad', null],
    ['two words', null],
  ])('%s → %s', (input, slug) => {
    expect(studioSlugFrom(input)).toBe(slug);
  });
});

describe('times in the studio’s time zone', () => {
  const utc = '2026-11-10T00:30:00Z'; // 5:30 PM on the 9th in Denver

  it('shows the studio’s local time and day, wherever the phone is', () => {
    expect(timeOf(utc, 'America/Denver')).toBe('5:30 PM');
    expect(dayOf(utc, 'America/Denver')).toBe('Mon, Nov 9');
    expect(timeOf(utc, 'Europe/London')).toBe('12:30 AM');
    expect(dateKey(utc, 'America/Denver')).toBe('2026-11-09');
    expect(dateKey(utc, 'Europe/London')).toBe('2026-11-10');
  });

  it('moves studio dates by whole days, across months and daylight saving', () => {
    expect(addDays('2026-11-09', 7)).toBe('2026-11-16');
    expect(addDays('2026-10-30', 3)).toBe('2026-11-02');
    expect(addDays('2026-03-07', 1)).toBe('2026-03-08');
    expect(labelOfDate('2026-11-16')).toBe('Mon, Nov 16');
  });
});

describe('studio colors', () => {
  it('picks readable text on the accent and darkens light accents for text', () => {
    expect(inkOn('#5b4bf5')).toBe('#ffffff');
    expect(inkOn('#ffd84d')).toBe('#17141f');
    expect(textOn('#5b4bf5')).toBe('#5b4bf5');
    expect(textOn('#ffd84d')).not.toBe('#ffd84d');
    expect(validAccent('nope')).toBe('#5b4bf5');
    expect(mix('#ffffff', '#000000', 0.5)).toBe('#808080');
  });
});

it('formats money in the studio’s currency', () => {
  expect(money(16413, 'usd')).toBe('$164.13');
  expect(money(2500, 'eur')).toBe('€25.00');
});
