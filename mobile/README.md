# Loft — mobile app

The Loft app for members, for iOS and Android: one app for every studio on Loft. It's built with
Expo (React Native, TypeScript) and talks to the Laravel API in `../web` (`/api/v1`; see the wiki's
[API](https://github.com/CyberpunkAidy/loft-studio/wiki/API) page). More in the wiki's
[Mobile App](https://github.com/CyberpunkAidy/loft-studio/wiki/Mobile-App) page.

## What it does

- Find a studio by its web address, then sign in or create an account (or join with an existing one).
- **Schedule:** a week at a time, in the studio's time zone, with spots left and your bookings.
- **A class:** book each person on the account; choose in the studio or online for hybrid classes,
  and apparatus where there's a choice; sign a waiver when one's needed; buy a plan when none covers
  the class, and the booking finishes once paid.
- **My classes:** upcoming and past, cancel, and **Join online** from 15 minutes before an online class.
- **Videos:** the studio's library and player, for videos you can watch.
- **Account:** check-in codes (a QR code per person, changing every 30 seconds), family, plans and
  purchases, buying a plan (Stripe Checkout in an in-app browser; never plans that include videos),
  switching studios, signing out, and deleting the account on the website.

## Run it

```bash
npm install
npm start              # then open it in Expo Go on a phone, or press i / a for a simulator
```

It uses the live site's API. To use a local one (`php artisan serve` in `../web`):

```bash
EXPO_PUBLIC_API_URL=http://localhost:8000/api/v1 npm start
```

From a phone, use your computer's network address instead of `localhost`. The demo studio's
accounts (`parent@example.com`, password `password`) are in `../web/README.md`.

`npm run web` runs it in a browser, handy for a quick look. The API allows that from any origin.

## Checks

```bash
npm run typecheck
npm test
```

CI (`.github/workflows/mobile.yml`) runs both and builds the web bundle.

## How it's put together

- `app/`: the screens (Expo Router: one file per route). `app/studio/[slug]/` is everything for one
  studio, with its tabs in `(tabs)/`.
- `src/api/`: the API client (`client.ts`: bearer token, the error envelope as `ApiError`,
  `Idempotency-Key`), the signed-in session (`session.tsx`), data hooks (`hooks.ts`, TanStack Query),
  the current studio (`studio.tsx`) and the API's types (`types.ts`).
- `src/ui/`: the theme (the website's palette, light and dark, in the studio's color) and shared parts.
- `src/lib/`: times in the studio's time zone, money, colors, studio addresses.
- The token is kept in the device's keychain (`expo-secure-store`).

## Building for the stores

With an [Expo](https://expo.dev) account and EAS CLI (`npm install -g eas-cli`):

```bash
eas build --profile preview --platform all      # installable test builds
eas build --profile production --platform all   # for the App Store and Google Play
eas submit --platform ios                        # needs an Apple Developer account
```

The bundle ID and package are `app.loftstudio.loft`.
