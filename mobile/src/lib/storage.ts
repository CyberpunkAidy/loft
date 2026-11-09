import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

/**
 * Small values kept between launches: the sign-in token goes in the device's keychain (SecureStore);
 * the web preview, which has none, uses localStorage.
 */
export const storage = {
  async get(key: string): Promise<string | null> {
    if (Platform.OS === 'web') {
      try {
        return globalThis.localStorage?.getItem(key) ?? null;
      } catch {
        return null;
      }
    }
    return SecureStore.getItemAsync(key);
  },
  async set(key: string, value: string): Promise<void> {
    if (Platform.OS === 'web') {
      try {
        globalThis.localStorage?.setItem(key, value);
      } catch {
        // private mode: kept for this visit only
      }
      return;
    }
    await SecureStore.setItemAsync(key, value);
  },
  async remove(key: string): Promise<void> {
    if (Platform.OS === 'web') {
      try {
        globalThis.localStorage?.removeItem(key);
      } catch {
        // nothing to remove
      }
      return;
    }
    await SecureStore.deleteItemAsync(key);
  },
};
