---
paths:
  - "app/Filament/**"
---

# Admin panel (Filament)

The admin is built from Filament's own parts, on purpose (decision of 2026-11-11): no hand-made markup or styles where a native component exists. The compact product tables are the one older exception.

## Shape

- A resource extends `App\Filament\Resources\LocalizedResource`: it writes a page title with one capital letter, as Russian does, instead of capitalising every word.
- Labels are Russian strings passed through `__()`. Every new string needs its English, Czech and Ukrainian line in `lang/en.json`, `lang/cs.json`, `lang/uk.json`; `ship-module` has a script that adds them without touching existing lines.
- Lists that have natural states open on tabs with counts (`getTabs()`), not on a status filter.
- One definition per repeated control. A field or action used in several forms lives in `app/Filament/Support/` or `app/Filament/Schemas/` and is called from each place (`SupplierSelect`, `ProductSaleActions`, `ProductCommercialAction`).

## What the operator expects

- A feature is found on the screen it belongs to. Units are inside the product, with a button of their own; a separate page is an addition, never the only way in.
- Two different outcomes are two different buttons, side by side: take off sale and delete, sell and write off.
- A destructive action says what goes and what stays before it runs. A reversible one runs at once.

## Traps met in this codebase

- After a repeater's own button, Filament redraws only the repeater. A number or text in the heading of the section around it goes stale; keep such values out of headings.
- A header action that writes past an open edit form must refresh the form's fields and the page's edit revision, or the next save fails or undoes the action. See `EditProduct::saleChanged()`.
- A `Select` validates its value against its options. A value typed elsewhere must be in the list first (`Supplier::named()`), also in tests.
- A model object stays "recently created" for its whole life. Hooks that should react to the first save only need their own mark (`ProductVariant::$justInserted`).

## Tests

- Test a page with `Livewire::test()` on the page class: visibility of each action for each state, then the action itself and what it wrote.
- `AdminEveryButtonTest` presses every button of every resource. A new resource or page is covered by it automatically; run it after adding actions, because an action that moves a record to another tab changes what later presses see.
- Read an action's modal text from the action (`getModalDescription()`), not from rendered HTML.
