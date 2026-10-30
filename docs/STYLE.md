# SDLabs style guide (Datasheet look)

The public site is styled like a manufacturer's datasheet, with measurement charts where measurements exist. It still works as a shop: every card is a product photo, and every product page leads with the price and the main button.

The approved mock-up is the "SDLabs Datasheet Lab" artifact (round 3). This file is the reference for building it. Where the two differ, this file wins.

## Fonts

| Use | Font | How |
|---|---|---|
| Headings, labels, buttons, nav | Archivo, narrowed | `font-condensed` + weight 600/700, usually `uppercase` (the `.sd-title` / `.sd-label` classes) |
| Body text | Archivo | default `font-sans` |
| Numbers, specs, file sizes, frequencies | Azeret Mono | `font-mono tabular-nums` (`.sd-num`) |

The fonts come from npm (`@fontsource-variable/archivo`, `@fontsource/azeret-mono`) and are bundled by Vite, so no request goes to Google. Filament components on the dashboard pick up the same `font-sans`.

## Colour

| Token (Tailwind) | Value | Use |
|---|---|---|
| `ink` / `ink-2` / `ink-3` | #161b26 / #4a5366 / #7a8396 | text: main / secondary / hints |
| `paper` | #f2f4f7 | page background of browse and detail pages |
| `white` | | sheets: cards, panels, sections |
| `rule` | #cfd5df | borders of sheets, table rules |
| `hair` | #e5e9ef | faint lines inside a sheet |
| `brand-800` | purple | the main button, links, active nav underline |
| `accent-500` | amber | "Official" seal, cart count, highlights |
| `trace` | #7c3fc2 | chart lines and frequency bands |
| green-700 | | "Free" prices |

The header and footer stay dark (`bg-header`, `bg-footer`). Seasonal themes recolour them through CSS variables, so don't hard-code their colours.

## Shapes

- **Square corners everywhere.** No `rounded-*` on cards, panels or buttons. The only round things are avatars, status dots and the star mask.
- **No drop shadows** on cards. Hover changes the border to `border-ink`.
- **Sheets:** `.sd-sheet` (white, 1 px `rule` border). Sections inside a page are sheets with a header row (`border-b border-rule`) and a content area.
- **Section headings on a page:** `.sd-section-head`, a 2 px ink rule under an `.sd-title` heading, with an optional link on the right.

## Building blocks (in `resources/themes/blank/assets/css/app.css`)

- `.sd-btn`: square, 2 px ink border, narrow uppercase label; inverts on hover.
- `.sd-btn-go`: the one main action of a page or card (purple). Use `.sd-btn-sm` in tables and rows.
- `.sd-pill`: type tag on photos (ink). `.sd-seal`: "Official" (amber).
- `.sd-title`, `.sd-label`, `.sd-num`.
- `.sd-rich`: the default look for text members write in the rich editor (summary, description, driver notes). Always use it for that content. It takes the full width of its section, as the editor does in the form, so text alignment matches.

## Cards (designs, drivers, listings)

- **Photo first.**
  - Designs: 4:3, `object-cover`. Drivers: 1:1, `object-contain` on white, padded.
  - Always use the `<x-marketing.card-image>` thumbnails.
- **Tags on the photo:** type pill top left; "Official" seal top right.
- **Body:**
  - Name: narrow, bold, 21 px.
  - "by …" line, in `ink-2`.
  - One row of key specs in `font-mono` 12 px (for designs: impedance, number of drivers, build cost).
  - A dotted rule, then the price (green "Free") and the rating.
- **No response curves on cards.** Curves are for the detail page.
- **Click target:** the whole card links to the product (a stretched link on the title).

## Page types

- **Browse pages** (home, designs, drivers, marketplace, seller and profile pages):
  - Paper background.
  - Keep the admin banner (`<livewire:banner-display>`) at the top where it is today. The home hero sits under it and stays compact, so the banner and the hero don't fight.
  - Filters are plain lists with counts.
  - Grids are `repeat(auto-fill, minmax(250px, 1fr))` with an 18 px gap.
- **Detail pages:**
  - Breadcrumb, then the photo gallery on the left (7/12 width) and a "sheet" on the right (5/12): document number line, name, tagline, byline, buy box first, then a spec table with mono values.
  - Under that, a sticky row of section links and one sheet per section.
- **Dashboard** (later phase): quiet.
  - Tasks first ("Needs your attention", one button each), then plain tables with the state written in words.
  - No uppercase headings and no big photos.

## Charts (frequency response)

- **Data:** `App\Support\FrequencyResponse` (the same data as today).
- **Axes:**
  - Log frequency axis from 20 Hz to 20 kHz.
  - Grid at 20, 50, 100, 200, 500, 1k, 2k, 5k, 10k, 20k, plus every 10 dB.
  - Axis text: 10 px Azeret Mono, `ink-3`.
- **Lines:**
  - Each driver gets its own colour: amber #d97706, teal #0f766e, pink #be185d, blue #2563eb.
  - The sum is ink, 2.4 px.
  - The legend buttons switch lines on and off.
- **Sizing and reading:**
  - The chart is drawn at the real width of its box. Below 560 px wide it is taller (height = 0.8 × width) and shows fewer frequency labels (20, 100, 1k, 10k, 20k).
  - Hover, or drag a finger, to show the summed level at that frequency in a readout line under the chart.
- **Summary values** shown next to the chart:
  - Midband level: the average of 300 Hz to 3 kHz.
  - −3 dB point: the lowest frequency at or above midband − 3 dB.

## Writing

Short, plain labels in sentence case in body text, and uppercase only through `.sd-title` / `.sd-label`. Name things by what members recognise, for example "Crossover files", not "electronic_files".
