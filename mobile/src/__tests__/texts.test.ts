import { looksTextable, textsSummary } from '../lib/texts';

describe('looksTextable', () => {
  it('takes US and Canadian numbers however they’re typed', () => {
    for (const phone of ['303-555-0123', '(303) 555-0123', '+1 303 555 0123', '1-303-555-0123', '303.555.0123 ext 12']) {
      expect(looksTextable(phone)).toBe(true);
    }
  });

  it('takes other countries with their + code', () => {
    expect(looksTextable('+44 20 7946 0958')).toBe(true);
  });

  it('refuses what isn’t a number Loft could text', () => {
    for (const phone of ['', '555-0123', '123-456-7890', '+1 103 555 0123', 'call me', null, undefined]) {
      expect(looksTextable(phone)).toBe(false);
    }
  });
});

describe('textsSummary', () => {
  it('says what the member gets', () => {
    expect(textsSummary({ updates: true, marketing: false, stopped_at: null })).toBe('Booking texts');
    expect(textsSummary({ updates: true, marketing: true, stopped_at: null })).toBe('Booking texts, and news and offers');
    expect(textsSummary({ updates: false, marketing: false, stopped_at: null })).toBe('Off');
    expect(textsSummary({ updates: false, marketing: false, stopped_at: '2026-11-13T10:00:00Z' })).toBe('Stopped (you replied STOP)');
  });
});
