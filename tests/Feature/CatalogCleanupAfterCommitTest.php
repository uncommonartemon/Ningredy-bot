<?php

namespace Tests\Feature;

use App\Models\AiOperation;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Maintenance\CatalogCleanupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class CatalogCleanupAfterCommitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // This case needs a real commit, not RefreshDatabase's outer rollback.
        // TestCase has already verified that this PDO is isolated :memory:.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
    }

    public function test_after_commit_cleanup_failure_keeps_database_success_and_reports_a_separate_warning(): void
    {
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('public');
        config(['services.admin.name' => 'Cleanup Admin']);
        $actor = User::factory()->create(['name' => 'Cleanup Admin', 'is_admin' => true]);
        $product = Product::query()->create(['title' => 'After commit', 'category_id' => Category::query()->firstOrFail()->id]);
        $service = app(CatalogCleanupService::class);
        $plan = $service->prepare('catalog', [], $actor);
        Product::deleted(function (): void {
            DB::afterCommit(function (): void {
                throw new RuntimeException('Simulated cleanup dispatch error after commit.');
            });
        });
        $result = $service->execute($plan, $actor);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1, $result['deleted']['products']);
        $this->assertNotEmpty($result['cleanup_warning']);
        $this->assertModelMissing($product);
        $operation = AiOperation::query()->findOrFail($result['operation_id']);
        $this->assertSame('completed', $operation->status);
        $this->assertArrayNotHasKey('rolled_back', $operation->result);
        $this->assertSame($result, $service->execute($plan, $actor));
    }
}
