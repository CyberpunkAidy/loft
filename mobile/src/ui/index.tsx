import { ReactNode } from 'react';
import {
  ActivityIndicator,
  Pressable,
  RefreshControl,
  ScrollView,
  StyleProp,
  StyleSheet,
  Text,
  TextInput,
  TextInputProps,
  TextStyle,
  View,
  ViewStyle,
} from 'react-native';

import { Icon, IconName } from './Icon';
import { radius, space, useTheme } from './theme';

export { Icon } from './Icon';
export { StudioMark } from './StudioMark';
export { AccentProvider, useTheme, space, radius } from './theme';

/** A scrolling page with the app's background; pull to refresh when $onRefresh is given. */
export function Screen({
  children,
  onRefresh,
  refreshing = false,
  padded = true,
}: {
  children: ReactNode;
  onRefresh?: () => void;
  refreshing?: boolean;
  padded?: boolean;
}) {
  const { c, accent } = useTheme();
  return (
    <ScrollView
      style={{ flex: 1, backgroundColor: c.bg }}
      contentContainerStyle={[padded && { padding: space.lg, paddingBottom: space.xxl * 2 }, { gap: space.md }]}
      keyboardShouldPersistTaps="handled"
      refreshControl={onRefresh ? <RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={accent.color} /> : undefined}>
      {children}
    </ScrollView>
  );
}

type TextProps = { children: ReactNode; style?: StyleProp<TextStyle>; numberOfLines?: number };

export function Title({ children, style }: TextProps) {
  const { c } = useTheme();
  return <Text accessibilityRole="header" style={[{ fontSize: 28, fontWeight: '700', letterSpacing: -0.6, color: c.ink }, style]}>{children}</Text>;
}

export function Heading({ children, style }: TextProps) {
  const { c } = useTheme();
  return <Text accessibilityRole="header" style={[{ fontSize: 18, fontWeight: '600', color: c.ink, letterSpacing: -0.2 }, style]}>{children}</Text>;
}

export function Body({ children, style, numberOfLines }: TextProps) {
  const { c } = useTheme();
  return <Text numberOfLines={numberOfLines} style={[{ fontSize: 16, lineHeight: 23, color: c.ink2 }, style]}>{children}</Text>;
}

export function Muted({ children, style, numberOfLines }: TextProps) {
  const { c } = useTheme();
  return <Text numberOfLines={numberOfLines} style={[{ fontSize: 14, lineHeight: 20, color: c.muted }, style]}>{children}</Text>;
}

export function Card({ children, style }: { children: ReactNode; style?: StyleProp<ViewStyle> }) {
  const { c } = useTheme();
  return (
    <View style={[{ backgroundColor: c.surface, borderRadius: radius.lg, borderWidth: StyleSheet.hairlineWidth, borderColor: c.line, padding: space.lg, gap: space.sm }, style]}>
      {children}
    </View>
  );
}

type ButtonProps = {
  title: string;
  onPress: () => void;
  variant?: 'primary' | 'secondary' | 'danger' | 'link';
  loading?: boolean;
  disabled?: boolean;
  icon?: IconName;
  small?: boolean;
  accessibilityHint?: string;
};

export function Button({ title, onPress, variant = 'primary', loading = false, disabled = false, icon, small = false, accessibilityHint }: ButtonProps) {
  const { c, accent } = useTheme();
  const off = disabled || loading;
  const colors = {
    primary: { bg: accent.color, fg: accent.ink, border: accent.color },
    secondary: { bg: c.surface, fg: c.ink, border: c.lineStrong },
    danger: { bg: c.surface, fg: c.err, border: c.err },
    link: { bg: 'transparent', fg: accent.text, border: 'transparent' },
  }[variant];

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ disabled: off, busy: loading }}
      accessibilityHint={accessibilityHint}
      onPress={off ? undefined : onPress}
      style={({ pressed }) => [
        {
          backgroundColor: colors.bg,
          borderColor: colors.border,
          borderWidth: variant === 'link' ? 0 : 1,
          borderRadius: radius.pill,
          paddingVertical: variant === 'link' ? space.xs : small ? 8 : 13,
          paddingHorizontal: variant === 'link' ? 0 : small ? 14 : 20,
          minHeight: variant === 'link' ? undefined : small ? 36 : 48,
          flexDirection: 'row',
          alignItems: 'center',
          justifyContent: 'center',
          alignSelf: variant === 'link' || small ? 'flex-start' : 'stretch',
          gap: space.sm,
          opacity: off ? 0.5 : pressed ? 0.8 : 1,
        },
      ]}>
      {loading ? <ActivityIndicator color={colors.fg} /> : icon ? <Icon name={icon} size={18} color={colors.fg} /> : null}
      <Text style={{ color: colors.fg, fontSize: small ? 15 : 16, fontWeight: '600', textDecorationLine: variant === 'link' ? 'underline' : 'none' }}>{title}</Text>
    </Pressable>
  );
}

export function TextField({ label, error, hint, style, ...input }: TextInputProps & { label: string; error?: string; hint?: string }) {
  const { c, accent } = useTheme();
  return (
    <View style={{ gap: 6 }}>
      <Text style={{ fontSize: 14, fontWeight: '600', color: c.ink2 }}>{label}</Text>
      <TextInput
        accessibilityLabel={label}
        placeholderTextColor={c.faint}
        selectionColor={accent.color}
        style={[
          { borderWidth: 1, borderColor: error ? c.err : c.lineStrong, borderRadius: radius.md, paddingHorizontal: 14, paddingVertical: 12, fontSize: 16, color: c.ink, backgroundColor: c.surface },
          style,
        ]}
        {...input}
      />
      {error ? <Text style={{ color: c.err, fontSize: 13 }}>{error}</Text> : hint ? <Text style={{ color: c.muted, fontSize: 13 }}>{hint}</Text> : null}
    </View>
  );
}

type Tone = 'ok' | 'warn' | 'err' | 'info' | 'muted' | 'accent';

export function Badge({ label, tone = 'muted' }: { label: string; tone?: Tone }) {
  const { c, accent } = useTheme();
  const colors: Record<Tone, [string, string]> = {
    ok: [c.ok, c.okBg],
    warn: [c.warn, c.warnBg],
    err: [c.err, c.errBg],
    info: [c.info, c.infoBg],
    muted: [c.muted, c.soft],
    accent: [accent.text, accent.soft],
  };
  const [fg, bg] = colors[tone];
  return (
    <View style={{ backgroundColor: bg, borderRadius: radius.pill, paddingHorizontal: 10, paddingVertical: 3, alignSelf: 'flex-start' }}>
      <Text style={{ color: fg, fontSize: 12, fontWeight: '600' }}>{label}</Text>
    </View>
  );
}

export function Loading({ label }: { label?: string }) {
  const { c, accent } = useTheme();
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.xl, gap: space.md, backgroundColor: c.bg }}>
      <ActivityIndicator color={accent.color} />
      {label ? <Muted>{label}</Muted> : null}
    </View>
  );
}

export function ErrorView({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  const message = error instanceof Error ? error.message : 'Something went wrong.';
  return (
    <Card>
      <Body>{message}</Body>
      {onRetry ? <Button title="Try again" variant="secondary" small onPress={onRetry} /> : null}
    </Card>
  );
}

export function Empty({ children }: { children: ReactNode }) {
  const { c } = useTheme();
  return (
    <View style={{ borderWidth: 1, borderStyle: 'dashed', borderColor: c.lineStrong, borderRadius: radius.lg, padding: space.xl, alignItems: 'center' }}>
      <Muted style={{ textAlign: 'center' }}>{children}</Muted>
    </View>
  );
}

/** A row in a list: an icon, a title and what it leads to. */
export function Row({ title, subtitle, icon, onPress, right }: { title: string; subtitle?: string; icon?: IconName; onPress?: () => void; right?: ReactNode }) {
  const { c, accent } = useTheme();
  return (
    <Pressable
      accessibilityRole={onPress ? 'button' : undefined}
      onPress={onPress}
      style={({ pressed }) => ({ flexDirection: 'row', alignItems: 'center', gap: space.md, paddingVertical: space.md, opacity: pressed ? 0.7 : 1 })}>
      {icon ? (
        <View style={{ width: 36, height: 36, borderRadius: 10, backgroundColor: accent.soft, alignItems: 'center', justifyContent: 'center' }}>
          <Icon name={icon} size={20} color={accent.text} />
        </View>
      ) : null}
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={{ fontSize: 16, fontWeight: '600', color: c.ink }}>{title}</Text>
        {subtitle ? <Muted numberOfLines={2}>{subtitle}</Muted> : null}
      </View>
      {right ?? (onPress ? <Icon name="chevron" size={16} color={c.faint} /> : null)}
    </Pressable>
  );
}

export function Divider() {
  const { c } = useTheme();
  return <View style={{ height: StyleSheet.hairlineWidth, backgroundColor: c.line }} />;
}

/** One choice in a set (radio): a bordered card that's highlighted when chosen. */
export function Choice({ title, subtitle, selected, disabled = false, onPress }: { title: string; subtitle?: string; selected: boolean; disabled?: boolean; onPress: () => void }) {
  const { c, accent } = useTheme();
  return (
    <Pressable
      accessibilityRole="radio"
      accessibilityState={{ selected, disabled }}
      onPress={disabled ? undefined : onPress}
      style={{
        flexDirection: 'row', alignItems: 'center', gap: space.md, padding: space.md,
        borderRadius: radius.md, borderWidth: 1.5,
        borderColor: selected ? accent.color : c.line,
        backgroundColor: selected ? accent.soft : c.surface,
        opacity: disabled ? 0.5 : 1,
      }}>
      <View style={{ width: 20, height: 20, borderRadius: 10, borderWidth: 2, borderColor: selected ? accent.color : c.lineStrong, alignItems: 'center', justifyContent: 'center' }}>
        {selected ? <View style={{ width: 10, height: 10, borderRadius: 5, backgroundColor: accent.color }} /> : null}
      </View>
      <View style={{ flex: 1, gap: 2 }}>
        <Text style={{ fontSize: 16, fontWeight: '600', color: c.ink }}>{title}</Text>
        {subtitle ? <Muted>{subtitle}</Muted> : null}
      </View>
    </Pressable>
  );
}

export function Checkbox({ label, checked, onChange }: { label: string; checked: boolean; onChange: (value: boolean) => void }) {
  const { c, accent } = useTheme();
  return (
    <Pressable accessibilityRole="checkbox" accessibilityState={{ checked }} onPress={() => onChange(!checked)} style={{ flexDirection: 'row', gap: space.md, alignItems: 'flex-start' }}>
      <View style={{ width: 22, height: 22, borderRadius: 6, borderWidth: 2, marginTop: 1, borderColor: checked ? accent.color : c.lineStrong, backgroundColor: checked ? accent.color : 'transparent', alignItems: 'center', justifyContent: 'center' }}>
        {checked ? <Icon name="check" size={14} color={accent.ink} /> : null}
      </View>
      <Text style={{ flex: 1, fontSize: 15, lineHeight: 22, color: c.ink2 }}>{label}</Text>
    </Pressable>
  );
}

/** A notice at the top of a screen, such as a message from the studio. */
export function Notice({ children, tone = 'info' }: { children: ReactNode; tone?: 'info' | 'ok' | 'warn' | 'err' }) {
  const { c } = useTheme();
  const [fg, bg] = { info: [c.info, c.infoBg], ok: [c.ok, c.okBg], warn: [c.warn, c.warnBg], err: [c.err, c.errBg] }[tone];
  return (
    <View style={{ backgroundColor: bg, borderRadius: radius.md, padding: space.md }}>
      <Text style={{ color: fg, fontSize: 15, lineHeight: 21, fontWeight: '500' }}>{children}</Text>
    </View>
  );
}
