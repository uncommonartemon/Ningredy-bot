<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Catalog\CardHighlights;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Which characteristics one product's catalog card shows, off the request that formed it. */
class ChooseCardHighlights implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $productId)
    {
        $this->onQueue('default');
    }

    public function handle(CardHighlights $highlights): void
    {
        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }
        try {
            $highlights->choose($product);
        } catch (Throwable $exception) {
            // The card keeps the usual order; «catalog:card-highlights --product=ID» asks again.
            report($exception);
        }
    }
}
