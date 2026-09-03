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
should read as machined; **Manrope** for body. Never a third family.

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
5. **Nothing moves without a reason.** Motion explains a change of state — a
   filter applied, a page swapped, a card opened. Decorative loops are limited
   to the ambient background glows that already exist.
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
