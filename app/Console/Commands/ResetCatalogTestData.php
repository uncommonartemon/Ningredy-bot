<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDraftMedia;
use App\Models\ProductMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetCatalogTestData extends Command
{
    protected $signature = 'catalog:reset-test-data
        {--force : Run without an interactive confirmation}';

    protected $description = 'Clear catalog test results and learned gallery recipes without resetting IDs or settings';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to clear catalog data in production without --force.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Delete catalog products, drafts, learned gallery recipes and transient AI conversation state?',
        )) {
            return self::SUCCESS;
        }

        $before = $this->counts();

        // Use model deletes for rows that own local files. The remaining
        // database rows can then be removed in FK-safe order. Deliberately do
        // not TRUNCATE and never touch sqlite_sequence/auto increment state:
        // Telegram messages and serialized queue jobs may still contain an
        // old numeric draft id.
        ProductDraftMedia::query()->eachById(fn (ProductDraftMedia $media) => $media->delete());
        ProductMedia::query()->eachById(fn (ProductMedia $media) => $media->delete());
        Product::query()->eachById(fn (Product $product) => $product->delete());

        DB::transaction(function (): void {
            $this->deleteIfPresent('product_source_page_rules');
            $this->deleteIfPresent('product_gallery_recipe_versions');
            $this->deleteIfPresent('product_gallery_recipes');
            $this->deleteIfPresent('product_source_stats');
            $this->deleteIfPresent('product_drafts');
            $this->deleteIfPresent('agent_conversation_messages');
            $this->deleteIfPresent('agent_conversations');
            $this->deleteIfPresent('telegram_chat_states');
        });

        Cache::flush();

        $after = $this->counts();
        $this->info(sprintf(
            'Catalog test data cleared: products %d->%d, drafts %d->%d, recipes %d->%d.',
            $before['products'],
            $after['products'],
            $before['product_drafts'],
            $after['product_drafts'],
            $before['product_gallery_recipes'],
            $after['product_gallery_recipes'],
        ));
        $this->line('Preserved settings, API keys, users, categories, brands, Telegram updates and AI/source audit history. IDs were not reset.');

        return self::SUCCESS;
    }

    /** @return array{products: int, product_drafts: int, product_gallery_recipes: int} */
    private function counts(): array
    {
        return [
            'products' => $this->countIfPresent('products'),
            'product_drafts' => $this->countIfPresent('product_drafts'),
            'product_gallery_recipes' => $this->countIfPresent('product_gallery_recipes'),
        ];
    }

    private function countIfPresent(string $table): int
    {
        return Schema::hasTable($table) ? DB::table($table)->count() : 0;
    }

    private function deleteIfPresent(string $table): void
    {
        if (Schema::hasTable($table)) {
            DB::table($table)->delete();
        }
    }
}
