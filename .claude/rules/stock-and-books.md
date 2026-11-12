---
paths:
  - "app/Services/Catalog/**"
  - "app/Models/StockUnit.php"
  - "app/Models/Supplier.php"
  - "app/Models/ProductVariant.php"
  - "app/Models/Product.php"
  - "app/Filament/Resources/StockUnits/**"
  - "app/Filament/Resources/Suppliers/**"
  - "app/Filament/Widgets/Accounting/**"
---

# Stock by the item, and the books

The shop sells one-off items, and the same products come in again. The books are a history: they must stay true after a product is renamed, taken off sale or deleted.

## Invariants

- A configuration's quantity equals the number of its units in stock, whoever changed either. `StockUnits` is the only place that keeps them equal.
- A unit has a number of its own, given by a counter (`StockUnits::nextCode()`), never given twice. The form shows the number the moment a row appears, so numbers are taken before saving; an unused one is a gap and that is fine.
- A unit keeps its product's name, its currency and its supplier's name itself. It outlives its product: sold and written-off units stay, a described unit in stock is written off, a blank row goes.
- A position is one item in stock unless told otherwise. A card exists because the item does.
- Sold without a date is sold today, at the configuration's price. Any other state forgets the sale. This is the unit's own rule (`StockUnit::saving`).
- A supplier is one of the list; a name written by any path joins the list in one spelling (`Supplier::named()`).

## Two ways out of the storefront

- Off sale: the product sleeps. Nothing of it is lost; the same product coming in again wakes its card, with how many came and what they cost, in one step.
- Deleted: for a card that was a mistake. Sales stay in the books.
- Nothing goes to sleep by itself when the last item is sold.

## When adding to this area

- Put a rule that must always hold into the model or `StockUnits`, then call it from the form, the button and the chat. Do not repeat it in each.
- Money is summed in one currency: the one most units are priced in (`StockBooks`). A unit without a purchase price is counted apart, not as free.
- A migration that creates units writes them with plain queries and gives them their number, name and currency itself.
- Clearing the whole catalog clears the books too and shows the count before it does.
