<?php

namespace Tests\Feature;

use App\Ai\Tools\GetRecipeHealth;
use App\Models\ProductGalleryRecipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GetRecipeHealthToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_not_found_for_a_domain_with_no_recipe_yet(): void
    {
        $result = json_decode(
            (string) (new GetRecipeHealth('never-seen.example'))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertFalse($result['found']);
    }

    public function test_exposes_the_recipes_own_failure_and_degrade_history(): void
    {
        ProductGalleryRecipe::query()->create([
            'domain' => 'stuck.example',
            'path_pattern' => '*',
            'status' => 'learning',
            'failure_count' => 2,
            'last_failure_kind' => 'download_unreachable',
            'last_error' => 'Подтверждённые в браузере фото не скачались 3 раз(а) подряд.',
        ]);
        Cache::put('gallery-recipe-download-degrade-cycles:stuck.example', 2, now()->addDays(7));

        $result = json_decode(
            (string) (new GetRecipeHealth('stuck.example'))->handle(new ToolRequest),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($result['found']);
        $this->assertSame('learning', $result['status']);
        $this->assertSame(2, $result['failure_count']);
        $this->assertSame('download_unreachable', $result['last_failure_kind']);
        $this->assertSame(2, $result['download_degrade_cycles']);
    }
}
