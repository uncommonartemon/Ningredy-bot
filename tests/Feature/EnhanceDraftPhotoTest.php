<?php

namespace Tests\Feature;

use App\Jobs\EnhanceDraftPhoto;
use App\Models\AiOperation;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Products\ProductPhotoEnhancer;
use App\Services\Telegram\DraftTelegramPresenter;
use App\Services\Telegram\TelegramClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class EnhanceDraftPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_enhances_only_the_selected_photo_and_reopens_the_same_draft(): void
    {
        Storage::fake('public');
        Cache::flush();
        [$draft, $update, $operation] = $this->draft();
        $selected = $draft->media()->orderBy('sort_order')->get()[1];
        Cache::put("draft-photo-enhance:{$draft->id}:queued", $update->id, 300);

        $enhancer = $this->mock(ProductPhotoEnhancer::class, function (MockInterface $mock) use ($selected, $update): void {
            $mock->shouldReceive('enhance')
                ->once()
                ->withArgs(fn ($media, int $telegramUpdateId, ProductDraft $draft): bool => $media->id === $selected->id
                    && $telegramUpdateId === $update->id
                    && $draft->id === $selected->product_draft_id)
                ->andReturnUsing(function ($media) use ($update): array {
                    $media->update([
                        'verification_notes' => "[AI-enhanced by Telegram update {$update->id}]",
                    ]);

                    return ['ok' => true, 'media_id' => $media->id, 'width' => 1200, 'height' => 900];
                });
        });
        $presenter = $this->mock(DraftTelegramPresenter::class, function (MockInterface $mock) use ($draft): void {
            $mock->shouldReceive('sendReview')
                ->once()
                ->withArgs(fn (TelegramClient $telegram, string $chatId, ProductDraft $shownDraft): bool => $chatId === '98765'
                    && $shownDraft->id === $draft->id);
        });

        (new EnhanceDraftPhoto(
            $draft->id,
            $selected->id,
            $update->id,
            '98765',
            $operation->id,
        ))->handle($enhancer, $presenter, app(TelegramClient::class));

        $this->assertSame('pending_review', $draft->fresh()->status);
        $this->assertStringContainsString(
            "[AI-enhanced by Telegram update {$update->id}]",
            (string) $selected->fresh()->verification_notes,
        );
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertFalse(Cache::has("draft-photo-enhance:{$draft->id}:queued"));
    }

    /** @return array{ProductDraft, TelegramUpdate, AiOperation} */
    private function draft(): array
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 4101,
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 90,
            'text' => 'draft:enhance-photo:1:2',
            'payload' => [],
            'status' => 'completed',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'status' => 'completed',
            'prompt' => 'research',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $draft = ProductDraft::query()->create([
            'telegram_update_id' => $update->id,
            'ai_run_id' => $run->id,
            'requested_by_telegram_user_id' => '12345',
            'title' => 'MSI Aegis Exact',
            'brand' => 'MSI',
            'model' => 'Aegis Exact',
            'color' => 'Black',
            'description' => 'Exact product.',
            'specifications' => [],
            'sources' => [],
            'image_urls' => [],
            'images_staged_at' => now(),
            'confidence' => 0.95,
        ]);

        foreach (range(1, 3) as $position) {
            $path = "drafts/{$draft->id}/photo-{$position}.webp";
            Storage::disk('public')->put($path, "photo-{$position}");
            $draft->media()->create([
                'disk' => 'public',
                'path' => $path,
                'source_url' => "https://images.example/photo-{$position}.jpg",
                'role' => $position === 1 ? 'primary' : 'secondary',
                'mime_type' => 'image/webp',
                'checksum' => hash('sha256', "photo-{$position}"),
                'verification_status' => 'verified',
                'sort_order' => $position - 1,
                'is_primary' => $position === 1,
            ]);
        }

        $operation = AiOperation::query()->create([
            'telegram_update_id' => $update->id,
            'telegram_user_id' => '12345',
            'tool' => 'TelegramCallback',
            'action' => 'enhance_draft_photo',
            'target_type' => ProductDraft::class,
            'target_id' => $draft->id,
            'payload' => [],
            'status' => 'running',
        ]);

        return [$draft, $update, $operation];
    }
}
