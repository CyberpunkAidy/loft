# Home Page

The marketing page at [loftstudio.app](https://loftstudio.app) ([decision 39](Decisions)). It's written for studio owners deciding whether to try Loft, and follows the market research: one flat price with everything included, no cut of sales, free help moving from another system, and booking by apparatus for aerial and pole studios.

## What's on it, top to bottom

1. **Hero:** "Your whole studio, one flat price.", with screenshots of the owner's dashboard and a parent's phone.
2. **Facts:** the price, unlimited members and staff, the Loft fee on sales (0% unless `LOFT_PLATFORM_FEE_PERCENT` is set), no contract.
3. **Booking that fills every spot:** apparatus booking and waitlists, aimed at aerial, pole and reformer studios.
4. **Tour:** waivers and check-in, payments into the studio's own Stripe account, and members at risk of leaving. Each section has a real screenshot.
5. **Everything else, included:** point of sale, shop, video, live classes, emails, families, door check-in, any phone.
6. **Price and calculator:** what a studio pays now compared with Loft over a year. It names no other products. It starts from $199 a month of software that adds 1% to $10,000 a month of card sales. Stripe's own card fees apply either way, so they're left out of both sides. When Loft would cost more, it says so.
7. **Up and running this week:** steps for a first-time studio, and the **free import offer** for studios that are switching.
8. **Questions,** then a closing call to action with the support address.

The trial length, grace days, price and Loft fee all come from the billing settings, so the page always matches `/pricing` and the terms. The support address comes from `LOFT_SUPPORT_EMAIL` (default `hello@loftstudio.app`). It appears on the page and in the footer of every platform page.

## Promises the page makes

Keep these true, or change the page:

- **"We'll move your members over for free."** Someone has to answer `hello@loftstudio.app` and run the import with the owner ([Data Import](Data-Import)).
- **"A person reads and answers every message."**
- **No Loft fee on sales.** The page switches to the fee if `LOFT_PLATFORM_FEE_PERCENT` is set.
- **"$150–500 a month elsewhere"** comes from the November 2026 market research, which isn't kept in the repository: what it costs to get the same features from other products. Check it again if prices in the market change.
- Every feature the page lists exists today. **Studio logos and SMS are not mentioned, because Loft doesn't have them.** The mobile app isn't mentioned until it's in the stores.

## Search and sharing

- `<meta name="description">` on the home and pricing pages, from `@section('description')` in `layouts/base`.
- Open Graph and Twitter tags, a canonical link, and the share image `public/images/og/loft-home.jpg` (1200×630). **The share image has "$49" and "30 days" drawn into it.** Make it again if the price or trial changes.
- JSON-LD with a `SoftwareApplication` (with the price from config) and an `FAQPage` built from the same questions shown on the page.

## Screenshots

The product images are in `public/images/home/` as WebP files, about 300 KB in all. Only the two hero images load straight away; the rest load as they're scrolled to. They were taken from a demo studio (the Skyline seed, plus about 60 made-up members, a week of bookings and a month of sales), at twice the screen's resolution, then compressed.

To retake them after the screens change:

1. Seed a demo database with `php artisan migrate --seed`, then add members, bookings and sales so the pages aren't empty.
2. Take each screen at 2x with Playwright:
   - the dashboard at 1200 px wide;
   - check-in at 1040 px;
   - sales and members at 1200–1360 px;
   - phones at 390 px.
3. Change shown addresses to `loftstudio.app`.
4. Save as WebP, quality about 82, with the widths used now.
5. Update the `width` and `height` on each `<img>`. `HomePageTest` checks that they match the files and that each image has alt text.

## Where it lives

| What | Where |
|---|---|
| The page | `web/resources/views/platform/home.blade.php` |
| Numbers it shows, and the calculator's starting values | `PlatformController::home` |
| Calculator in the browser | `public/js/app.js` (`[data-calc]`), the same sum as the controller |
| Styles | `public/css/app.css`, "Marketing (platform home)" |
| Tests | `tests/Feature/HomePageTest.php` |

## To set up

- **Forward `hello@loftstudio.app`** in Porkbun (Domain → Email forwarding), as with `privacy@` ([Legal](Legal)).
