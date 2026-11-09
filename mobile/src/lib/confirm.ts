import { Alert, Platform } from 'react-native';

/** Ask before something that can't be undone. The web preview uses the browser's dialog. */
export function confirm(title: string, message: string, action = 'OK', destructive = false): Promise<boolean> {
  if (Platform.OS === 'web') {
    return Promise.resolve(globalThis.confirm?.(`${title}\n\n${message}`) ?? true);
  }
  return new Promise((resolve) => {
    Alert.alert(title, message, [
      { text: 'Not now', style: 'cancel', onPress: () => resolve(false) },
      { text: action, style: destructive ? 'destructive' : 'default', onPress: () => resolve(true) },
    ]);
  });
}
