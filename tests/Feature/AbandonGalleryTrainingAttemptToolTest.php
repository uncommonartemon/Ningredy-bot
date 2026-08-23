<?php

namespace Tests\Feature;

use App\Ai\Tools\AbandonGalleryTrainingAttempt;
use App\Models\AiOperation;
use App\Models\ProductGalleryRecipe;
use App\Models\ProductGalleryRecipeVersion;
use App\Models\TelegramUpdate;
use App\Services\Products\GalleryTrainingAbandonSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class AbandonGalleryTrainingAttemptToolTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductGalleryRecipe, 1: ProductGalleryRecipeVersion} */
    private function recipeAndVersion(): array
    {
        $recipe = ProductGalleryRecipe::query()->create([
            'domain' => 'stuck.example',
            'path_pattern' => '*',
            'status' => 'learning',
        ]);
        $version = ProductGalleryRecipeVersion::query()->create([
            'product_gallery_recipe_id' => $recipe->id,
            'domain' => 'stuck.example',
            'product_url' => 'https://stuck.example/product',
            'trigger' => 'automatic',
            'status' => 'scouting',
            'provider' => 'openai',
            'model' => 'gpt-test',
        ]);

        return [$recipe, $version];
    }

    public function test_it_sets_the_signal_and_records_an_audited_operation(): void
    {
        [$recipe, $version] = $this->recipeAndVersion();
        $signal = new GalleryTrainingAbandonSignal;

        $result = json_decode(
            (string) (new AbandonGalleryTrainingAttempt($version, $recipe, 'https://stuck.example/product', null, $signal))
                ->handle(new ToolRequest([
                    'reason' => 'Every candidate fails at the download layer regardless of selector.',
                ])),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($signal->abandoned);
        $this->assertSame('Every candidate fails at the download layer regardless of selector.', $signal->reason);
        $this->assertSame('agent_abandoned', $signal->failureKind);
        $this->assertDatabaseHas('ai_operations', [
            'tool' => 'AbandonGalleryTrainingAttempt',
            'action' => 'abandon_training_attempt',
            'status' => 'completed',
            'target_type' => ProductGalleryRecipeVersion::class,
            'target_id' => $version->id,
            'telegram_update_id' => null,
        ]);
    }

    public function test_a_real_telegram_update_is_attributed_in_the_audit_row(): void
    {
        [$recipe, $version] = $this->recipeAndVersion();
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(1000, 9000),
            'telegram_user_id' => '111',
            'chat_id' => '222',
            'message_id' => 1,
            'payload' => [],
            'status' => 'processing',
        ]);

        (new AbandonGalleryTrainingAttempt($version, $recipe, 'https://stuck.example/product', $update, new GalleryTrainingAbandonSignal))
            ->handle(new ToolRequest(['reason' => 'Stuck.']));

        $operation = AiOperation::query()->where('tool', 'AbandonGalleryTrainingAttempt')->firstOrFail();
        $this->assertSame($update->id, $operation->telegram_update_id);
    }

    public function test_an_empty_reason_is_rejected(): void
    {
        [$recipe, $version] = $this->recipeAndVersion();

        $this->expectException(\RuntimeException::class);

        (new AbandonGalleryTrainingAttempt($version, $recipe, 'https://stuck.example/product', null, new GalleryTrainingAbandonSignal))
            ->handle(new ToolRequest(['reason' => '   ']));
    }

    public function test_an_invalid_failure_kind_falls_back_to_agent_abandoned(): void
    {
        [$recipe, $version] = $this->recipeAndVersion();
        $signal = new GalleryTrainingAbandonSignal;

        (new AbandonGalleryTrainingAttempt($version, $recipe, 'https://stuck.example/product', null, $signal))
            ->handle(new ToolRequest(['reason' => 'Stuck.', 'failure_kind' => 'not_a_real_kind']));

        $this->assertSame('agent_abandoned', $signal->failureKind);
    }
}
