# Design System

## Logo

- **The mark:** a rising arc with a dot at its peak. It reads as a leap at its highest point, which is what "loft" means.
- **Gradient:** the mark sits in a rounded square with a dusk-sky gradient, violet `#5b4bf5` → magenta `#c05ad8` → coral `#ff8a5c`.
- **Wordmark:** lowercase "loft" in Bricolage Grotesque, converted to outlines, so the logo files don't need the font.

| File | Use |
|---|---|
| `public/images/loft-logo.svg` | Mark and wordmark, dark text |
| `public/images/loft-logo-light.svg` | Mark and wordmark, white text (dark backgrounds) |
| `public/images/loft-mark.svg` | Mark only |
| `public/images/loft-logo-email.png` | For emails (email apps don't show SVG) |
| `public/favicon.svg`, `favicon.ico`, `apple-touch-icon.png` | Browser and home-screen icons |

- **In page templates:** use `<x-logo />` (add `:wordmark="false"` for the mark alone). The wordmark takes the text color.
- **Studio pages** show the studio's own mark instead (`<x-studio-brand>`): its **logo** if it has uploaded one, otherwise its initial in its brand color. A wide logo (at least twice as wide as tall) stands in for the name, with the name as its alt text; a square one sits beside the name. Logos carry their own width and height, so space is kept while they load and email apps draw them at that size. The favicon stays the initial, because a wide logo shrunk to a tab icon can't be read ([decision 41](Decisions)).

## Color

| Token | Value | Use |
|---|---|---|
| `--accent` | `#5b4bf5` on platform pages; the studio's brand color on studio pages | Buttons, links, highlights |
| `--accent-ink` | White or `#17141f` | Text on the accent color |
| `--accent-text` | The accent, darkened if needed | Accent used as text on white (at least 4.5:1) |
| `--ink` / `--ink-2` / `--muted` / `--faint` | `#17141f` / `#3a3545` / `#6c6776` / `#9c97a5` | Text, from strongest to faintest |
| `--bg` / `--surface` / `--soft` | `#f7f6f3` / `#ffffff` / `#f1efeb` | Page, cards, subtle fills |
| `--line` / `--line-strong` | `#e9e6e0` / `#d9d5cd` | Borders |
| `--ok`, `--warn`, `--err`, `--info` (with `-bg` and `-line`) | green, amber, red, blue | Status badges and alerts |

- **Any brand color works.** Studios pick any color, and `App\Support\Color` keeps it readable:
  - `inkOn()` picks white or near-black for button labels, whichever has the higher contrast.
  - `textOnWhite()` darkens the color just enough for 4.5:1 contrast as text on white.
  - So a yellow studio gets dark button labels and dark-gold links, instead of white on yellow.
- **Where the values are set:** the layout writes all three values into the page; the email templates use the same methods.

## Type

- **Inter** (variable) for text, with stylistic alternates `cv11` and `ss03`.
- **Bricolage Grotesque** (variable) for headings.
- **Self-hosted** in `public/fonts`, under the SIL Open Font License (license files alongside). The Latin set is preloaded; Latin Extended loads only on pages that need it. No requests go to font CDNs.

## Components (`public/css/app.css`)

One stylesheet, no build step, built on CSS custom properties. The class names are shared by every page:
- **Buttons:** `.btn` (pill-shaped), `.btn-secondary`, `.btn-danger`, `.btn-dark`, `.btn-small`, `.btn-large`, `.btn-block`; `.link`.
- **Surfaces:** `.card`, `.card-flush`, `.fieldset` (a form section with a heading), `.alert-*`, `.empty`.
- **Labels:** `.badge` (with a status dot), `.tag`, `.chip` (filters), `.tabs` and `.tab` (segmented control).
- **Forms:** `.form`, `.field`, `.input`, styled selects with a chevron, `.check`, `.prefix` (for example a currency symbol), `.grid-2`, `.grid-3`.
- **Data:** `.table` inside `.table-wrap` (wide tables scroll inside their card on phones), `.stats` and `.stat`, `.pagination`.
- **Layout:** `.container`, `.narrow`, `.split`, `.page-head`, `.site-header` (sticky and frosted on desktop), the `.admin` shell with `.sidebar`.

## Icons

- **Set:** a subset of [Lucide](https://lucide.dev) (ISC license) in `resources/icons`.
- **Use:** `<x-icon name="calendar-days" />`, rendered inline with the `icon` class and hidden from screen readers.
- **Adding one:** copy its SVG from Lucide into that folder.

## Accessibility

- Visible focus rings.
- Contrast-checked brand colors.
- Reduced motion is respected (`prefers-reduced-motion`).
- Icons are decorative, and labels carry the meaning.
- Every page works at phone width without sideways scrolling.

## Emails

The same look, rebuilt for email apps. See [Email](Email#templates).
