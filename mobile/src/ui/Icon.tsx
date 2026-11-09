import { SymbolView } from 'expo-symbols';
import type { ComponentProps } from 'react';
import type { ColorValue } from 'react-native';

type Names = ComponentProps<typeof SymbolView>['name'];

/** SF Symbols on iOS, Material Symbols on Android and the web. */
export const icons = {
  schedule: { ios: 'calendar', android: 'calendar_month', web: 'calendar_month' },
  classes: { ios: 'checkmark.circle', android: 'event_available', web: 'event_available' },
  videos: { ios: 'play.rectangle', android: 'smart_display', web: 'smart_display' },
  account: { ios: 'person.crop.circle', android: 'account_circle', web: 'account_circle' },
  chevron: { ios: 'chevron.right', android: 'chevron_right', web: 'chevron_right' },
  back: { ios: 'chevron.left', android: 'chevron_left', web: 'chevron_left' },
  qr: { ios: 'qrcode', android: 'qr_code_2', web: 'qr_code_2' },
  family: { ios: 'person.2', android: 'group', web: 'group' },
  card: { ios: 'creditcard', android: 'credit_card', web: 'credit_card' },
  receipt: { ios: 'doc.text', android: 'receipt_long', web: 'receipt_long' },
  waiver: { ios: 'signature', android: 'draw', web: 'draw' },
  swap: { ios: 'arrow.left.arrow.right', android: 'swap_horiz', web: 'swap_horiz' },
  signOut: { ios: 'rectangle.portrait.and.arrow.right', android: 'logout', web: 'logout' },
  web: { ios: 'safari', android: 'open_in_new', web: 'open_in_new' },
  lock: { ios: 'lock.fill', android: 'lock', web: 'lock' },
  play: { ios: 'play.fill', android: 'play_arrow', web: 'play_arrow' },
  video: { ios: 'video', android: 'videocam', web: 'videocam' },
  check: { ios: 'checkmark', android: 'check', web: 'check' },
  plus: { ios: 'plus', android: 'add', web: 'add' },
  mail: { ios: 'envelope', android: 'mail', web: 'mail' },
  trash: { ios: 'trash', android: 'delete', web: 'delete' },
} satisfies Record<string, Names>;

export type IconName = keyof typeof icons;

export function Icon({ name, size = 22, color }: { name: IconName; size?: number; color: ColorValue }) {
  return <SymbolView name={icons[name]} size={size} tintColor={color} />;
}
