---
paths:
  - "resources/js/**"
  - "resources/css/**"
---

# Storefront (Vue 3, Inertia)

- Look and motion follow the `web-design` skill; load it before building or restyling a page or component.
- Every name the storefront shows has a value in each shop language. Pick it with `pickName()` / `attributeName()` from `resources/js/i18n.js`, which fall back from the visitor's language to the shop's main one and then to English. Do not read a translation field directly.
- A signed-in customer of a group sees the group's price where one is set; the ordinary price stays the base for filters and sorting. Prices come ready from the server (`own_price`); the page does not compute them.
- The storefront never shows stock units, purchase prices or suppliers. Those are the shop's own books.
- After changing anything here, build (`npm run build`) and look at the page; `look-at-pages` says how.
