import Constants from 'expo-constants';

/**
 * The Loft API. EXPO_PUBLIC_API_URL points a development build at another server, such as a local
 * one (http://localhost:8000/api/v1); otherwise it's the live site.
 */
export const API_URL: string = (
  process.env.EXPO_PUBLIC_API_URL ??
  (Constants.expoConfig?.extra?.apiUrl as string | undefined) ??
  'https://loftstudio.app/api/v1'
).replace(/\/+$/, '');

/** The website, for things the app sends people to (resetting a password, deleting an account). */
export const WEB_URL = API_URL.replace(/\/api\/v1$/, '');
