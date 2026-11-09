# Mobile App

**Loft** for iOS and Android: one app for members of every studio on Loft ([decision 35](Decisions)). It's in `mobile/`, built with Expo (React Native, TypeScript) on the [API](API). Studios don't set anything up: members find their studio by its web address.

## What members can do

- **Get in:** find the studio by its address (`loftstudio.app/skyline`, or just `skyline`), then sign in, create an account, or join with the account they have. A forgotten password is reset on the website.
- **Schedule:** a week at a time in the studio's time zone, with spots left, waitlists, online and hybrid classes, and their own bookings marked.
- **Book a class** for anyone on the account:
  - In the studio or online, for a class that's both; an apparatus, when the class has a choice.
  - A waiver to sign first: signed in the app (typed name and agreement), one after another.
  - No plan that covers it: the plans that do, and paying for one finishes the booking.
  - A full class: the waitlist.
- **My classes:** upcoming and past; cancelling (with the late-cancel warning); **Join online** from 15 minutes before an online class until it ends.
- **Videos:** the library, and the player for videos they can watch (signed playback from Mux).
- **Account:**
  - **Check-in code:** a QR code for each person, changing every 30 seconds, for the studio's kiosk.
  - **Family:** everyone on the account, the waivers they still need, adding someone.
  - **Plans & purchases:** memberships and credits, every purchase (refunds shown), cards and invoices on the website.
  - **Buy a plan:** on Stripe's Checkout page in an in-app browser. Plans that include videos aren't sold in the app ([decision 33](Decisions)).
  - Contacting the studio, its website, switching or adding studios, signing out, and deleting the account (on the website).

The app follows the phone's light or dark setting and uses the studio's brand color.

## How it's built

| | |
|---|---|
| Framework | Expo SDK 58 (React Native 0.88), TypeScript, Expo Router (one file per screen in `mobile/app/`) |
| Data | TanStack Query over a small API client (`src/api/client.ts`): bearer token, the error envelope as `ApiError`, `Idempotency-Key` on booking, signing and checkout |
| Sign-in | One token per install (`POST /auth/tokens`), kept in the device's keychain (`expo-secure-store`); signing out revokes it. A refused token signs the app out |
| Studio | `/studio/[slug]/…`: the studio's details and color for every screen under it |
| Times | Shown in the studio's time zone, wherever the phone is |
| Video | `expo-video` with the HLS stream URL and token from `POST …/videos/{id}/playback` |
| Payments | `expo-web-browser`'s auth session opens Stripe Checkout and returns when it reaches the API's `return_url`; the app then asks `GET …/checkouts/{id}` |
| Types | Written by hand in `src/api/types.ts` from the API's resources. Generating them from an OpenAPI description is still to do |

## Working on it

```bash
cd mobile
npm install
npm start          # Expo Go on a phone, or a simulator
npm run typecheck
npm test
```

`EXPO_PUBLIC_API_URL=http://localhost:8000/api/v1 npm start` uses a local API (`php artisan serve` in `web/`, with the demo studio). `npm run web` runs it in a browser; the API allows that.

CI (`.github/workflows/mobile.yml`) type-checks, runs the tests and builds the web bundle on every change to `mobile/`.

## Getting it into the stores

Not done yet. It needs accounts only the owner can open:
1. **Expo account** (free) and `npm install -g eas-cli`, then `eas init` in `mobile/` to link the project.
2. **Apple Developer Program** ($99 a year) for TestFlight and the App Store, and **Google Play Console** ($25 once) for Google Play.
3. `eas build --profile preview` for installable test builds; `eas build --profile production` and `eas submit` for the stores (`mobile/eas.json`).
4. Store listings: screenshots, description, a privacy policy URL (the website doesn't have one yet), and Apple's privacy details. The bundle ID and package are `app.loftstudio.loft`.

## Not in the app yet

- **Push notifications:** the API keeps devices' push tokens, but nothing sends them yet, and the app doesn't register one.
- **A drawn signature** on waivers (the website has one; the app takes a typed name).
- **Editing or removing family members**, and the profile (name, phone, email preferences): on the website for now.
- **Offline use:** the app needs a connection.
