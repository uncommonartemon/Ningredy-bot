<?php

// What the chat can do with the shop: a product off sale and back, a repeat of a sleeping
// product, deleting, the books by the item, suppliers, customer groups, a look back at a search,
// photos from a named page. Run after changing any of those tools or the assistant's rules.

use App\Models\StockUnit;

return function (array $fixture): array {
    ['product' => $product, 'variant' => $variant, 'draft' => $draft, 'origin' => $origin] = $fixture;
    $variant->update(['quantity' => 2, 'price' => 3000]);
    $origin->update(['processed_at' => now()]);
    $units = StockUnit::query()->orderBy('id')->pluck('code')->all();
    [$p, $d] = [$product->id, $draft->id];

    return [
        ["сними с продажи товар {$p}", 'SetProductActive active=false'],
        ['покажи снятые с продажи товары', 'QueryDatabase is_active=false, catalog_results with the product'],
        ['TEST-14-A', 'a repeat by the bare code: SearchCatalog, the sleeping card, no ResearchProduct'],
        ["верни в продажу товар {$p}, пришло 2 штуки по 4500", 'SetProductActive active=true and UpdateVariant quantity and price'],
        ["убери товар {$p}", 'SetProductActive active=false (reversible), not a deletion'],
        ["удали товар {$p}", 'PrepareProductDeletion, delete_confirmation'],
        ["у единицы {$units[0]} закупка 900, купили 12 мая у Петра", 'ManageDatabase update stock_units; supplier written as a name'],
        ["отметь {$units[1]} проданной за 1400", 'ManageDatabase update stock_units: sold, 1400; the date is today'],
        ['сколько единиц в наличии и что продано', 'QueryDatabase stock_units, numbers'],
        ['создай группу клиентов Опт', 'ManageDatabase create customer_groups; the answer gives the sign-up link'],
        ["для группы Опт цена на товар {$p} — 2500", 'ManageDatabase create customer_group_prices'],
        ['почему в последнем поиске нет фото?', 'ExplainSearch last'],
        ["что ты делал по черновику {$d}?", 'ExplainSearch draft_id'],
        ["возьми фото для черновика {$d} с этой страницы https://semiconductor.samsung.com/ssd/pc-ssd/pm991/", 'ManageDraftPhotos photos_from_url'],
        ["пришло ещё 2 штуки товара {$p} по 4500, закупка 3000 у Петра", 'UpdateVariant quantity and price; the purchase written into the two new units'],
        ['добавь поставщика Mironet с заметкой опт', 'ManageDatabase create suppliers'],
    ];
};
