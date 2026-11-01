<?php

namespace App\Jobs;

use App\Models\Product;
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

    public function __construct(public int $productId)
    {
        // Behind the Telegram commands on the same worker (--queue=assistant,default).
        $this->onQueue('default');
    }

    public function handle(CatalogTextTranslator $translator): void
    {
        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }
        try {
            $translator->translate($product);
        } catch (Throwable $exception) {
            // The product keeps its English text and the translations it had; the
            // next «Перевести недостающее» tries again.
            report($exception);
        }
    }
}
