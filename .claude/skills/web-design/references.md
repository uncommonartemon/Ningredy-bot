# References: what was borrowed and from where

Collected 2026-11-23 by looking at the sites themselves (`scripts/shoot-refs.mjs`) and at
Awwwards' technology and e-commerce galleries. Each idea names what it does for this shop;
an idea that only decorates is not on the list.

| Idea | Seen at | Used for |
| --- | --- | --- |
| One real product, large, on a plain plate, one action under it | analogue.co | the bench on the start page |
| Specifications as an instrument's readout: hairline rows, machined type | teenage.engineering | the bench readout |
| Values that decode character by character | Awwwards "scramble text" elements, Codrops/CodePen text-scramble demos | readout values and price settle like a display reading a label |
| Search as a launcher: one field, answers while typing, keyboard first | raycast.com | the start page search (`/`, arrows, Enter) |
| Left-aligned statement, then the product itself as proof; hairline columns | linear.app | the hero copy and the status line |
| Thumbnail flies into the detail page | Chrome's View Transitions guidance (listing → product page) | photo of the clicked card or bench lands on the gallery |
| Scroll-driven reveals with `animation-timeline: view()` | Chrome scroll-driven animation case studies (redBus, Tokopedia) | not used yet: candidate for the catalog grid refactor |

## Seen and not taken

- Banner carousels and campaign art over products (rog.asus.com): the skill forbids banner carousels, and our photos are the product.
- WebGL distortion of images on hover (Codrops): it would cover and bend the photographs, which rule 1 keeps clean.
- Cookie walls and region pop-ups over the first screen (most large brand sites): nothing should sit between the visitor and the first screen.

## Going for more

```bash
node .claude/skills/web-design/scripts/shoot-refs.mjs <scratch folder> https://site.one/ https://site.two/
```

Open the pictures with the Read tool and look. Write down what an idea does for the shop
before taking it, and add it to the table above with its source.
