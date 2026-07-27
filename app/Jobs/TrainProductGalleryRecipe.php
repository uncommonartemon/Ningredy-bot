<?php

namespace App\Jobs;

use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TrainProductGalleryRecipe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 420;

    public function __construct(
        public string $url,
        public string $trigger = 'manual_filament',
    ) {
        $this->onQueue('media');
    }

    public function handle(ProductGalleryRecipeTrainer $trainer): void
    {
        $trainer->train($this->url, $this->trigger, force: true);
    }
}
