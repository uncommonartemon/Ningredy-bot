<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnreferencedMedia;
use App\Jobs\RunProductFormAssistant;
use App\Models\User;
use App\Services\Products\ProductFormAssistant;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LocalAdminAiQueueRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_only_admin_mode_uses_an_isolated_database_queue_even_when_default_configuration_is_cached_as_sync(): void
    {
        Queue::fake();
        config(['services.admin.name' => 'local-admin', 'queue.default' => 'sync']);
        $admin = User::factory()->create(['name' => 'local-admin', 'is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $previous = getenv('NINGREDY_ADMIN_ONLY');

        try {
            putenv('NINGREDY_ADMIN_ONLY');
            app(ProductFormAssistant::class)->start($admin, null, 'normal-session', 'chat', 'Describe this product', ['title' => 'Normal mode'], []);
            DeleteUnreferencedMedia::dispatch('public', 'products/normal.webp');
            putenv('NINGREDY_ADMIN_ONLY=1');
            app(ProductFormAssistant::class)->start($admin, null, 'local-session', 'chat', 'Describe this product', ['title' => 'Local mode'], []);
            DeleteUnreferencedMedia::dispatch('public', 'products/local.webp');
        } finally {
            putenv($previous === false ? 'NINGREDY_ADMIN_ONLY' : 'NINGREDY_ADMIN_ONLY='.$previous);
        }

        Queue::assertPushedOn('assistant', RunProductFormAssistant::class, fn ($job): bool => $job->connection === null);
        Queue::assertPushedOn('admin-ai', RunProductFormAssistant::class, fn ($job): bool => $job->connection === 'database');
        Queue::assertPushed(RunProductFormAssistant::class, 2);
        Queue::assertPushedOn('media', DeleteUnreferencedMedia::class, fn ($job): bool => $job->connection === null && $job->afterCommit === true);
        Queue::assertPushedOn('admin-ai', DeleteUnreferencedMedia::class, fn ($job): bool => $job->connection === 'database'
            && $job->afterCommit === true && $job->tries === 3 && $job->backoff === [30, 120]);
        Queue::assertPushed(DeleteUnreferencedMedia::class, 2);
        $this->assertSame('sync', config('queue.default'));
    }
}
