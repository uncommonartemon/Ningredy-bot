<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Catalog\CatalogNames;
use App\Services\Products\CardTranslationState;
use App\Services\Products\CatalogTextTranslator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One product's missing translations, off the request that asked for them. */
class TranslateProductText implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    /**
     * @param  bool  $onlyIfDue  queued by the scheduler's catch-up: skipped when the same texts
     *                           were asked meanwhile, as by a copy queued earlier behind a long search
     */
    public function __construct(public int $productId, public bool $onlyIfDue = false)
    {
        // Behind the Telegram commands on the same worker (--queue=assistant,default).
        $this->onQueue('default');
    }

    public function handle(CatalogTextTranslator $translator): void
    {
        $product = Product::query()->find($this->productId);
        if ($product === null || ($this->onlyIfDue && ! $translator->isDue($product))) {
            return;
        }
        try {
            $translator->translate($product);
        } catch (Throwable $exception) {
            // The product keeps its English text and the translations it had; the
            // scheduler asks again after a wait, «Перевести недостающее» at once.
            report($exception);
        }
        // A new characteristic, group or category of this card is named in the shop's languages
        // now, not at the next round of the names' schedule. CatalogNames asks for every missing
        // name at once and keeps its own wait for names no answer filled.
        try {
            if (collect(app(CardTranslationState::class)->missing($product->fresh()))->contains('shared', true)) {
                app(CatalogNames::class)->fill();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
