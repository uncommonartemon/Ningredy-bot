<?php

// Drafts and products on request: show a draft, correct it in words, look for more photos,
// search again or not, change a product. Run after changing the draft tools or the rules about
// when a paid search starts.

return function (array $fixture): array {
    ['product' => $product, 'draft' => $draft, 'other' => $other] = $fixture;
    [$d, $k, $p] = [$draft->id, $other->id, $product->id];

    return [
        ["дай черновик {$d}", 'ShowProductDraft, the card of that draft, no list'],
        ["что в черновике {$d}, какие характеристики и сколько фото", 'ShowProductDraft with a real answer'],
        ['какие есть черновики', 'ListPendingDrafts'],
        ["в черновике {$d} добавь TDP 115 Вт", 'EditProductDraft applied, reconciliation kept'],
        ["в черновике {$d} нет скорости записи, найди", 'EditProductDraft wanted: looks for the value'],
        ["убери из черновика {$d} характеристику Weight", 'EditProductDraft removes it'],
        ["в черновике {$d} нет TDP для другой конфигурации, допиши значение сам", 'no invented value: asks or searches'],
        ['Samsung MZALQ512HALU-000L1', 'the waiting draft is shown, no paid search'],
        ['найди заново Samsung MZALQ512HALU-000L1', 'ResearchProduct: asked to search again'],
        ["в черновике {$d} мало фото, найди ещё", 'ManageDraftPhotos find_more_photos'],
        ["в черновике {$k} эти фото не те, найди другие", 'ManageDraftPhotos find_other_photos'],
        ["покажи товар {$p}", 'catalog_results with the product'],
        ["в товаре {$p} неверная частота, поставь 2490 MHz", 'AddProductSpecifications replaces boost_clock, no second row'],
        ["поставь товару {$p} цену 15000 CZK и количество 3", 'UpdateVariant'],
        ["это б/у, укажи состояние у товара {$p}", 'UpdateVariant condition used'],
        ['сколько всего товаров и сколько черновиков', 'one product, two drafts'],
    ];
};
