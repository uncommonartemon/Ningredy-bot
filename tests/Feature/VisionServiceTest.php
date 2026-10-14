<?php

namespace Tests\Feature;

use App\Ai\Agents\GalleryTextLanguageAgent;
use App\Ai\Agents\ProductGalleryVisionAgent;
use App\Ai\Agents\ProductImageVisionAgent;
use App\Ai\Agents\TelegramPhotoTextAgent;
use App\Ai\Tools\InspectGalleryImages;
use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Models\AppSetting;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchTimeBudget;
use App\Services\Products\ProductImageResolver;
use App\Services\Products\ProductImageVisionVerifier;
use App\Services\Vision\VisionCallContext;
use App\Services\Vision\VisionImagePreparer;
use App\Services\Vision\VisionResponse;
use App\Services\Vision\VisionService;
use App\Services\Vision\VisionTimeBudgetExceeded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class VisionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_remain_distinct_and_each_logs_exactly_one_provider_call(): void
    {
        TelegramPhotoTextAgent::fake(fn () => ['text' => 'CPU label', 'has_text' => true])->preventStrayPrompts();
        ProductGalleryVisionAgent::fake(fn () => ['summary' => 'Side view; color unclear'])->preventStrayPrompts();
        ProductImageVisionAgent::fake(fn () => ['images' => []])->preventStrayPrompts();
        GalleryTextLanguageAgent::fake(fn () => ['foreign_text_frames' => []])->preventStrayPrompts();
        $service = app(VisionService::class);
        $context = new VisionCallContext(useSearchBudget: false);
        $frames = [app(VisionImagePreparer::class)->fromBytes($this->png(), 'frame')];

        $this->assertSame('CPU label', $service->readText($this->png(), $context)['text']);
        $this->assertSame(['summary' => 'Side view; color unclear'], $service->inspectGallery('observe', $frames, $context));
        $this->assertSame(['images' => []], $service->reviewGallery('review', $frames, $context, fn ($data) => $data));
        $this->assertSame(['foreign_text_frames' => []], $service->checkTextLanguage('language only', $frames, $context));

        $runs = AiRun::orderBy('id')->get();
        $this->assertSame(['read_text', 'inspect_gallery', 'review_gallery', 'check_text_language'], $runs->pluck('activity.vision_operation')->all());
        foreach ($runs as $run) {
            $this->assertSame('completed', $run->status);
            $this->assertSame(1, $run->activity['image_count']);
            $this->assertNotNull($run->usage);
        }
        ProductGalleryVisionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'observe');
        GalleryTextLanguageAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === 'language only');
    }

    public function test_deadline_is_recomputed_after_waiting_for_the_shared_gate(): void
    {
        $update = $this->update();
        $this->mock(ProductSearchTimeBudget::class, function ($mock) use ($update): void {
            $mock->shouldReceive('canStart')->with($update->id, 15)->twice()->andReturnTrue();
            $mock->shouldReceive('timeoutFor')->with($update->id, 90)->twice()->andReturn(50, 20);
        });
        $this->mock(OpenAiHeavyOperationGate::class)->shouldReceive('run')->once()
            ->with('openai', 50, \Mockery::type(\Closure::class))
            ->andReturnUsing(fn ($provider, $timeout, $operation) => $operation());
        ProductGalleryVisionAgent::fake(fn () => ['summary' => 'Observed'])->preventStrayPrompts();

        app(VisionService::class)->inspectGallery('observe', [], new VisionCallContext($update->id, 90, model: 'gpt-5-mini'));

        ProductGalleryVisionAgent::assertPrompted(fn (AgentPrompt $prompt) => $prompt->timeout === 20 && $prompt->model === 'gpt-5-mini');
        $this->assertSame($update->id, AiRun::sole()->telegram_update_id);
    }

    public function test_expired_deadline_after_waiting_does_not_call_the_model(): void
    {
        ProductGalleryVisionAgent::fake()->preventStrayPrompts();
        $this->mock(ProductSearchTimeBudget::class, function ($mock): void {
            $mock->shouldReceive('canStart')->twice()->andReturn(true, false);
            $mock->shouldReceive('timeoutFor')->once()->andReturn(30);
        });

        try {
            app(VisionService::class)->inspectGallery('observe', [], new VisionCallContext);
            $this->fail('Expired deadline must interrupt the call.');
        } catch (VisionTimeBudgetExceeded) {
            ProductGalleryVisionAgent::assertNeverPrompted();
            $this->assertSame('interrupted', AiRun::sole()->status);
            $this->assertNull(AiRun::sole()->usage);
        }
    }

    public function test_cancelled_paid_review_is_not_returned_as_an_accepted_result(): void
    {
        $update = $this->update();
        ProductImageVisionAgent::fake(function () use ($update): array {
            $update->update(['status' => 'cancelled']);

            return ['images' => []];
        })->preventStrayPrompts();

        try {
            app(VisionService::class)->reviewGallery('review', [], new VisionCallContext($update->id), function (): array {
                $this->fail('Validation must not run after cancellation.');
            });
            $this->fail('Cancellation must propagate.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('cancelled', AiRun::sole()->status);
            $this->assertNotNull(AiRun::sole()->usage);
            $this->assertSame(['images' => []], AiRun::sole()->response);
        }
    }

    public function test_empty_ocr_reply_keeps_paid_usage_but_is_not_success(): void
    {
        TelegramPhotoTextAgent::fake(fn () => ['text' => '', 'has_text' => false])->preventStrayPrompts();

        try {
            app(VisionService::class)->readText($this->png(), new VisionCallContext(useSearchBudget: false));
            $this->fail('An empty OCR reply must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Photo text extraction is empty.', $exception->getMessage());
            $this->assertSame('failed', AiRun::sole()->status);
            $this->assertNotNull(AiRun::sole()->usage);
        }
    }

    public function test_partial_review_data_survives_a_domain_validation_issue_without_an_implicit_retry(): void
    {
        $calls = 0;
        ProductImageVisionAgent::fake(function () use (&$calls): array {
            $calls++;

            return ['images' => [['index' => 1]]];
        })->preventStrayPrompts();
        $data = app(VisionService::class)->reviewGallery('review', [], new VisionCallContext,
            fn (array $data) => new VisionResponse($data, 'Caller must repair inconsistent verdict'));

        $this->assertSame(['images' => [['index' => 1]]], $data);
        $this->assertSame(1, $calls);
        $this->assertSame('failed', AiRun::sole()->status);
        $this->assertNotNull(AiRun::sole()->usage);
    }

    public function test_tool_inspection_inside_the_trainer_gate_does_not_wait_on_itself(): void
    {
        ProductGalleryVisionAgent::fake(fn () => ['summary' => 'Observed'])->preventStrayPrompts();

        $result = app(OpenAiHeavyOperationGate::class)->run('openai', 30,
            fn () => app(VisionService::class)->inspectGallery('observe', [], new VisionCallContext));

        $this->assertSame('Observed', $result['summary']);
        $this->assertSame('completed', AiRun::sole()->status);
    }

    public function test_pre_search_ocr_logging_does_not_start_or_exhaust_the_search_clock(): void
    {
        $this->travelTo(now()->startOfSecond());
        AppSetting::put('ai.search_max_seconds', '1200');
        AppSetting::put('ai.search_reserve_seconds', '120');
        $update = $this->update();
        TelegramPhotoTextAgent::fake(fn () => ['text' => 'CPU label', 'has_text' => true])->preventStrayPrompts();

        app(VisionService::class)->readText($this->png(), new VisionCallContext($update->id, useSearchBudget: false));
        $this->assertArrayNotHasKey('_product_search_started_at', $update->fresh()->payload);
        // The OCR worker finished, but the assistant queue was stopped for an hour.
        $this->travel(1)->hours();

        $this->assertSame(1080, app(ProductSearchTimeBudget::class)->remainingWorkingSeconds($update->id));
        $this->assertNotNull(AiRun::sole()->usage);
    }

    public function test_actual_format_wins_over_misleading_http_mime_without_reencoding_a_supported_image(): void
    {
        $bytes = $this->png();
        $prepared = app(VisionImagePreparer::class)->prepare(['bytes' => $bytes, 'mime_type' => 'image/jpeg']);

        $this->assertSame('image/png', $prepared['mime_type']);
        $this->assertSame($bytes, $prepared['bytes']);
    }

    public function test_avif_is_converted_even_when_the_http_header_claims_png(): void
    {
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imageavif($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $prepared = app(VisionImagePreparer::class)->prepare(['bytes' => $bytes, 'mime_type' => 'image/png']);

        $this->assertSame('image/webp', $prepared['mime_type']);
        $this->assertSame('image/webp', getimagesizefromstring($prepared['bytes'])['mime']);
    }

    public function test_invalid_bytes_do_not_buy_an_ocr_call(): void
    {
        TelegramPhotoTextAgent::fake()->preventStrayPrompts();
        try {
            app(VisionService::class)->readText('<html>not an image</html>', new VisionCallContext);
            $this->fail('Invalid bytes must be rejected before calling the provider.');
        } catch (\RuntimeException) {
            TelegramPhotoTextAgent::assertNeverPrompted();
            $this->assertSame(0, AiRun::count());
        }
    }

    public function test_thumbnail_keeps_the_original_pixels_and_dimensions_untouched(): void
    {
        $image = imagecreate(1280, 960);
        $color = imagecolorallocate($image, 20, 80, 120);
        imagefill($image, 0, 0, $color);
        $pixel = imagecolorat($image, 12, 12);
        $attachment = app(VisionImagePreparer::class)->thumbnail($image, 'candidate.webp', 'low');

        $size = getimagesizefromstring($attachment->content());
        $this->assertSame([640, 480], [$size[0], $size[1]]);
        $this->assertSame([1280, 960], [imagesx($image), imagesy($image)]);
        $this->assertSame($pixel, imagecolorat($image, 12, 12));
        $this->assertFalse(imageistruecolor($image));
        imagedestroy($image);
    }

    public function test_final_product_reviewer_does_not_retry_a_cancelled_review(): void
    {
        $update = $this->update();
        $calls = 0;
        ProductImageVisionAgent::fake(function () use ($update, &$calls): array {
            $calls++;
            $update->update(['status' => 'cancelled']);

            return ['images' => []];
        })->preventStrayPrompts();
        $image = imagecreatefromstring($this->png());

        try {
            app(ProductImageVisionVerifier::class)->select(
                new ProductDraft(['telegram_update_id' => $update->id]),
                [['image' => $image, 'source_url' => 'https://cdn.example/frame.png']], 1,
            );
            $this->fail('Cancellation must leave the reviewer without a repair call.');
        } catch (ProductSearchCancelled) {
            $this->assertSame(1, $calls);
            $this->assertSame('cancelled', AiRun::sole()->status);
            $this->assertNotNull(AiRun::sole()->usage);
        } finally {
            imagedestroy($image);
        }
    }

    public function test_trainer_tool_does_not_hide_cancellation_as_an_uncertain_observation(): void
    {
        $update = $this->update();
        $url = 'https://cdn.example/frame.png';
        $this->mock(ProductImageResolver::class)->shouldReceive('download')->once()
            ->andReturn(['bytes' => $this->png(), 'mime_type' => 'image/png']);
        ProductGalleryVisionAgent::fake(function () use ($update): array {
            $update->update(['status' => 'cancelled']);

            return ['summary' => 'Observed'];
        })->preventStrayPrompts();

        try {
            (new InspectGalleryImages([$url], $update->id))->handle(new ToolRequest([
                'image_urls' => [$url], 'product_context' => 'CPU',
            ]));
            $this->fail('A cancelled tool must not tell the trainer to keep working.');
        } catch (ProductSearchCancelled) {
            $this->assertSame('cancelled', AiRun::sole()->status);
            $this->assertNotNull(AiRun::sole()->usage);
        }
    }

    private function update(): TelegramUpdate
    {
        return TelegramUpdate::create(['update_id' => 918001, 'telegram_user_id' => '1', 'chat_id' => '1',
            'payload' => [], 'status' => 'processing', 'text' => 'Find laptop']);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(32, 16);
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }
}
