/**
 * Text messages from a studio (decision 48). The rules match the website's (App\Support\Phone):
 * a US or Canadian number, or any number with its + country code.
 */

/** Shown with every box that opts someone in to texts, as the carriers require. */
export const TEXT_DISCLOSURE = 'Message frequency varies. Msg & data rates may apply. Reply HELP for help, STOP to opt out.';

/** Whether $phone is a number Loft could text: 10 US/Canadian digits (a leading 1 is fine), or + and a country code. */
export function looksTextable(phone: string | null | undefined): boolean {
  const input = (phone ?? '').trim().replace(/\s*(?:ext\.?|extension|x|#)\s*\d+$/i, '');
  if (!input || /[^0-9+\s().\-/]/.test(input)) return false;
  let digits = input.replace(/\D/g, '');
  const international = input.startsWith('+');
  if (international && !digits.startsWith('1')) return /^[2-9]\d{6,13}$/.test(digits);
  if (international || (digits.length === 11 && digits.startsWith('1'))) digits = digits.slice(1);
  return /^[2-9]\d{2}[2-9]\d{6}$/.test(digits);
}

/** "Booking texts and news" / "Booking texts" / "Off" / "Stopped (you replied STOP)". */
export function textsSummary(t: { updates: boolean; marketing: boolean; stopped_at: string | null }): string {
  if (t.stopped_at) return 'Stopped (you replied STOP)';
  if (t.updates && t.marketing) return 'Booking texts, and news and offers';
  if (t.updates) return 'Booking texts';
  if (t.marketing) return 'News and offers';
  return 'Off';
}
