<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Products\CatalogLocales;
use App\Services\Products\CatalogTextTranslator;
use Illuminate\Console\Command;
use Throwable;

class TranslateMissingCatalogText extends Command
{
    protected $signature = 'catalog:translate-missing {--dry-run : Only count the products missing a translation}';

    protected $description = 'Translate catalog products into the enabled languages where a translation is missing';

    public function handle(CatalogTextTranslator $translator): int
    {
        $languages = CatalogLocales::contentTranslations();
        $ids = $translator->pendingProductIds();
        $this->info('Языки перевода: '.(implode(', ', $languages) ?: 'нет').'. Товаров без перевода: '.count($ids).'.');
        if ($this->option('dry-run') || $ids === []) {
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
