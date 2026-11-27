---
name: web-design
description: Design system and motion rules for the Ningredy storefront (Vue 3 + Inertia + Tailwind 4). Use when building or restyling any page, component, or transition in resources/js.
---

# Ningredy storefront design

The catalog sells laptops and components. The product photography is the
product: every search in this project exists to obtain clean, high-resolution
frames, so the interface's job is to get out of their way and make them look
expensive. Chrome competes with photos; restraint sells them.

## The mood, in one line

**Precision instrument, not a shop.** Dark, quiet, technical — closer to a
hardware configurator or a trading terminal than to a marketplace. Cyan is the
only loud colour and it is used like a laser pointer: rarely, and always to
mean "this, here".

Naming the mood matters more than any single rule below. When a choice is
ambiguous, ask which option a precision instrument would make.

## Tokens

Defined in `resources/css/app.css` on `:root`. Never hardcode a hex that
duplicates one of these; add a token instead.

| token | role |
| --- | --- |
| `--bg` `#080a10` | page ground, near-black with a blue cast |
| `--panel` / `--panel-solid` / `--panel-soft` | raised surfaces, in that order of elevation |
| `--line` / `--line-bright` | hairlines; the bright one only on focus/active |
| `--text` / `--muted` | body text and everything secondary |
| `--accent` `#52f6da` | the laser: one accent per screen region, never two competing |
| `--violet` `#9277ff` / `--blue` `#50a8ff` | ambient gradients and data accents only, never CTAs |

Type: **Space Grotesk** (`--display`) for headings, numbers and anything that
should read as machined; **Manrope** for body. Space Grotesk has no Cyrillic, so it
must not carry text that is translated into Ukrainian. The start page sets its poster
type in **Sofia Sans Extra Condensed** (Latin, Czech and Cyrillic; files in
`resources/fonts`); its lowercase Cyrillic uses Bulgarian letterforms, so use it in
capitals only.

## Rules that are not negotiable

1. **Photos get the light.** A product image sits on the darkest available
   surface with no border and no overlay tint. Gradients, glows and blurs
   belong behind panels, never on top of a photograph.
2. **One accent per region.** A card may glow, or its button may glow. Not
   both.
3. **Hairlines, not boxes.** Separation comes from a 1px `--line` and a change
   of elevation, not from heavy borders or drop shadows.
4. **Numbers are typeset.** Prices, specs and counts use `--display` with
   tabular figures so columns line up while filtering.
5. **Nothing moves without a reason.** In the catalog and on product pages motion
   explains a change of state — a filter applied, a page swapped, a card opened.
   The start page is the exception by decision of 2026-11-25: it is a scene the
   visitor's scroll drives (see "The start page" below).
6. **Empty states carry the same weight as full ones.** A catalog with no
   matches still shows structure, not a bare sentence.

## Motion

The library is GSAP (already a dependency) for in-page choreography, and the
browser's **View Transitions API** for anything that crosses a page boundary.

- Durations: 180–260ms for state, 420–520ms for entrance, never above 700ms.
- Easing: `power3.out` for entrances, `power2.out` for exits. Nothing bounces.
- Stagger between siblings: 40–50ms, capped so a 50-card grid does not take two
  seconds to appear.
- Respect `prefers-reduced-motion`: transitions collapse to a plain fade, and
  ambient loops stop entirely.

### View transitions

Inertia swaps the page component in place, so wrap navigation in
`document.startViewTransition` and give the elements that persist across pages a
shared `view-transition-name`. The product image is the anchor: it should fly
from its card into the product page rather than fade out and back in.

Names must be unique per document, so derive them from the product id
(`view-transition-name: product-photo-42`), and clear them once the transition
ends or the next navigation will find two elements claiming one name.

## Layout patterns

- Content max width 1540px, gutters `clamp(18px, 3.5vw, 54px)`.
- Catalog: sticky filter rail on the left from 1100px up, drawer below that.
- Home: a single strong statement above the fold, then proof (real products,
  real counts), then entry points into the catalog. No carousel of banners.
- Grid: cards are equal height with the photo in a fixed 4:3 box; text never
  pushes the image out of alignment between neighbours.

## Checklist before calling a screen done

- [ ] Reads correctly at 360px, 768px and 1920px.
- [ ] Nothing scrolls horizontally.
- [ ] Keyboard focus is visible on every interactive element, using
      `--line-bright`.
- [ ] `prefers-reduced-motion` produces a still, usable page.
- [ ] No text below 13px, no contrast below WCAG AA on `--bg`.
- [ ] A screen with one product and a screen with fifty both look deliberate.

## The start page

Decision of 2026-11-25: the start page is expressive where the catalog is restrained.
Decision of 2026-11-27: its one job is to open the catalog, and the hook is the price. Prices
and counts on it are the catalog's own (`StorefrontHome`), every way in opens the catalog
already filtered to exactly what it counted, and it promises nothing the shop did not decide.

- The scroll is the clock. Scenes are GSAP ScrollTrigger timelines with `scrub`; the only
  things that move by themselves are the entrance, the row of brands (which takes its speed
  and direction from the scroll) and the product row while a pointer rests near its edge.
- One drawing runs behind everything: the line laptop (`resources/js/laptopWire.js`,
  `Components/Home/LaptopWire.vue`). The page owns its pose and ties it to the scroll.
  It is plain canvas; do not add a 3D library for it.
- No scene holds the page in place (decision of 2026-11-27): every section is scrolled past
  like any other, so each scene below is measured where it stands. The row of new products is
  an ordinary slider in the flow (`resources/js/dragRow.js`): swiped, dragged with the mouse
  (a drag never opens a card, a click does), moved slowly toward a side while the pointer rests
  in its outer eighth, reached by the keyboard and by previous/next buttons. Should a pinned
  scene ever come back, create it before the scenes below it, or they fire early.
- Poster type is huge (10-25vw), capitals, one family. Each line is shrunk until it fits its
  box (`fitPoster` in `Pages/Home.vue`): Ukrainian and Czech words are longer than English
  ones. Outline text filling with colour as it crosses the screen is the page's one repeated
  device; do not add a second.
- Photos still sit on light plates and nothing is laid over them; a line never runs under a plate.
- The catalog is one press away: a fixed «Catalog» button appears once the first screen is
  gone and leaves when the last link is on screen (a bar across the bottom on a phone).
- Categories are shown by icons, not photos: one set of line icons on a 24-unit grid
  (`resources/js/categoryIcons.js`, `Components/CategoryIcon.vue`). A new icon joins that
  set in the same hand: one stroke, round joins, no fill. Do not mix in another icon style.
- `prefers-reduced-motion` gets a still page: no scrub, no running row, and the product row
  moves only when dragged, swiped or reached by the keyboard (no glide, no edge movement).
- All motion is set up inside `gsap.matchMedia` and reverted when the page is left.

## References

Ideas borrowed from other sites, with their sources, are in [references.md](references.md).
To collect new ones, screenshot the sites with `scripts/shoot-refs.mjs` and look before borrowing.
