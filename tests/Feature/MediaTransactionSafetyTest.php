<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnreferencedMedia;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class MediaTransactionSafetyTest extends TestCase
{
    // Exercise actual outer commit callbacks, not RefreshDatabase's outer rollback.
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    private function product(): Product
    {
        return Product::create(['title' => 'Test laptop', 'product_type' => 'laptop',
            'category_id' => Category::where('slug', 'laptops')->value('id'), 'status' => 'published']);
    }

    public function test_delete_rollback_preserves_product_and_file_then_commit_removes_both(): void
    {
        config(['queue.default' => 'sync']);
        $product = $this->product();
        $path = 'products/'.$product->id.'/photo.jpg';
        Storage::disk('public')->put($path, 'fixture');
        try {
            DB::transaction(function () use ($product, $path): void {
                $product->delete();
                Storage::disk('public')->assertExists($path);
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        Storage::disk('public')->assertExists($path);
        DB::transaction(fn () => Product::findOrFail($product->id)->delete());
        Storage::disk('public')->assertMissing($path);
    }

    public function test_replacing_media_does_not_delete_old_file_before_commit(): void
    {
        config(['queue.default' => 'sync']);
        $product = $this->product();
        $old = 'products/'.$product->id.'/old.jpg';
        $new = 'products/'.$product->id.'/new.jpg';
        Storage::disk('public')->put($old, 'old');
        Storage::disk('public')->put($new, 'new');
        $media = $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => $old]);
        DB::beginTransaction();
        $media->update(['path' => $new]);
        Storage::disk('public')->assertExists($old);
        DB::rollBack();
        $this->assertSame($old, $media->fresh()->path);
        Storage::disk('public')->assertExists($old);
        DB::transaction(fn () => $media->fresh()->update(['path' => $new]));
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_rolling_back_a_media_insert_keeps_the_original_upload(): void
    {
        config(['queue.default' => 'sync']);
        $product = $this->product();
        $path = 'uploads/original.jpg';
        Storage::disk('public')->put($path, 'original');
        DB::beginTransaction();
        $media = $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => $path]);
        $this->assertStringStartsWith('products/'.$product->id.'/', $media->path);
        Storage::disk('public')->assertExists($path);
        DB::rollBack();
        Storage::disk('public')->assertExists($path);
        $this->assertSame(0, $product->media()->count());
    }

    public function test_cleanup_does_not_delete_a_file_still_referenced_by_media(): void
    {
        $product = $this->product();
        $path = 'products/'.$product->id.'/shared.jpg';
        Storage::disk('public')->put($path, 'shared');
        $product->media()->create(['type' => 'image', 'disk' => 'public', 'path' => $path]);
        (new DeleteUnreferencedMedia('public', $path))->handle();
        Storage::disk('public')->assertExists($path);
    }
}
