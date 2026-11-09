/**
 * Times are shown in the studio's time zone, wherever the phone is: a class at 5:00 PM in Denver
 * reads 5:00 PM for a member travelling in New York, as on the website.
 */
function format(iso: string, timeZone: string, options: Intl.DateTimeFormatOptions): string {
  return new Intl.DateTimeFormat('en-US', { timeZone, ...options }).format(new Date(iso));
}

/** "5:00 PM" */
export function timeOf(iso: string, timeZone: string): string {
  return format(iso, timeZone, { hour: 'numeric', minute: '2-digit' });
}

/** "Mon, Nov 9" */
export function dayOf(iso: string, timeZone: string): string {
  return format(iso, timeZone, { weekday: 'short', month: 'short', day: 'numeric' });
}

/** "Monday, November 9" */
export function longDayOf(iso: string, timeZone: string): string {
  return format(iso, timeZone, { weekday: 'long', month: 'long', day: 'numeric' });
}

/** "2026-11-09": the studio's calendar date, for grouping and the schedule's from= parameter. */
export function dateKey(iso: string | Date, timeZone: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(
    typeof iso === 'string' ? new Date(iso) : iso,
  );
  const get = (type: string) => parts.find((p) => p.type === type)?.value ?? '';
  return `${get('year')}-${get('month')}-${get('day')}`;
}

/** A studio date ("2026-11-09") moved by $days. */
export function addDays(key: string, days: number): string {
  const [y, m, d] = key.split('-').map(Number);
  const date = new Date(Date.UTC(y, m - 1, d + days));
  return date.toISOString().slice(0, 10);
}

/** "Mon, Nov 9" for a studio date, without time-zone shifts. */
export function labelOfDate(key: string): string {
  const [y, m, d] = key.split('-').map(Number);
  return new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric' }).format(
    new Date(Date.UTC(y, m - 1, d)),
  );
}

export function isPast(iso: string | null | undefined): boolean {
  return !!iso && new Date(iso).getTime() <= Date.now();
}
