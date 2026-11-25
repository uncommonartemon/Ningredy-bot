<?php

namespace App\Console\Commands;

use App\Jobs\TranslateProductText;
use App\Models\Product;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use Illuminate\Console\Command;
use Throwable;

/**
 * Translates the products that lack a shop language. The scheduler runs it with --due: it
 * queues the products not asked about in the last hours, so a translation that failed or
 * never ran is made without anyone pressing «Перевести недостающее».
 */
class TranslateMissingCatalogText extends Command
{
    protected $signature = 'catalog:translate-missing
        {--dry-run : Only count the products missing a translation}
        {--due : Queue only the products not asked about in the last hours (the scheduler\'s catch-up)}';

    protected $description = 'Translate catalog products into the enabled languages where a translation is missing';

    public function handle(CatalogTextTranslator $translator): int
    {
        $languages = CatalogLocales::contentTranslations();
        $due = (bool) $this->option('due');
        $ids = $due ? $translator->dueProductIds() : $translator->pendingProductIds();
        $this->info('Языки перевода: '.(implode(', ', $languages) ?: 'нет').'. Товаров без перевода'
            .($due ? ', которые пора спросить снова' : '').': '.count($ids).'.');
        if ($this->option('dry-run') || $ids === []) {
            return self::SUCCESS;
        }
        if ($due) {
            foreach ($ids as $id) {
                TranslateProductText::dispatch($id, onlyIfDue: true);
            }

            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($ids as $id) {
            $product = Product::query()->find($id);
            try {
                $this->line("#{$id} {$product?->title}: заполнено ".($product ? $translator->translate($product) : 0));
            } catch (Throwable $exception) {
                $failed++;
                $this->error("#{$id}: ".mb_substr($exception->getMessage(), 0, 200));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
