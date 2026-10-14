<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Deferred filesystem work: database rollback must never destroy live photos. */
class DeleteUnreferencedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $disk, public string $path, public ?int $deletedProductId = null)
    {
        $this->onQueue('media');
        $this->afterCommit();
    }

    public function handle(): void
    {
        // Never accept an absolute path, traversal or a disk root for cleanup.
        if ($this->path === '' || str_contains($this->path, '\\') || str_starts_with($this->path, '/')
            || str_contains($this->path, ':') || in_array('..', explode('/', $this->path), true)) {
            throw new RuntimeException('Unsafe media cleanup path.');
        }

        $disk = Storage::disk($this->disk);
        if ($this->deletedProductId !== null) {
            if ($this->deletedProductId < 1 || $this->path !== 'products/'.$this->deletedProductId) {
                throw new RuntimeException('Invalid product cleanup target.');
            }
            if (Product::query()->whereKey($this->deletedProductId)->exists()) {
                return;
            }
            // References belonging to another record protect shared files too.
            foreach ([ProductMedia::class, ProductDraftMedia::class] as $model) {
                if ($model::query()->where('disk', $this->disk)->where('path', 'like', $this->path.'/%')->exists()) {
                    return;
                }
            }
            throw_unless($disk->deleteDirectory($this->path), RuntimeException::class, 'Product media cleanup failed.');

            return;
        }

        foreach ([ProductMedia::class, ProductDraftMedia::class] as $model) {
            if ($model::query()->where('disk', $this->disk)->where('path', $this->path)->exists()) {
                return;
            }
        }
        if ($disk->exists($this->path)) {
            throw_unless($disk->delete($this->path), RuntimeException::class, 'Media cleanup failed.');
        }
    }
}
