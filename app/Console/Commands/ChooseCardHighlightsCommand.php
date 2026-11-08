<?php

namespace App\Console\Commands;

use App\Jobs\ChooseCardHighlights;
use App\Models\Product;
use App\Services\Catalog\CardHighlights;
use Illuminate\Console\Command;

/**
 * Hands the products whose catalog card was never chosen to the queue, a few at a time;
 * the scheduler runs it, so products made before the choice existed and products made in
 * the admin get theirs too. With --product it chooses again for those products.
 */
class ChooseCardHighlightsCommand extends Command
{
    protected $signature = 'catalog:card-highlights
        {--product=* : Choose again for these product ids}
        {--limit=20 : How many products without a choice to queue at once}';

    protected $description = 'Queue the choice of characteristics shown on catalog cards';

    public function handle(CardHighlights $highlights): int
    {
        $requested = array_map('intval', (array) $this->option('product'));
        $ids = $requested !== []
            ? Product::query()->whereIn('id', $requested)->pluck('id')->all()
            : $highlights->pendingProductIds(max(1, (int) $this->option('limit')));
        foreach ($ids as $id) {
            ChooseCardHighlights::dispatch((int) $id);
        }
        $this->info('Карточек в очереди на выбор характеристик: '.count($ids).'.');

        return self::SUCCESS;
    }
}
