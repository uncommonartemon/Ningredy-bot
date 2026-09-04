<?php

namespace Tests\Feature;

use App\Ai\Agents\ProductGalleryPreflightAgent;
use App\Ai\Agents\ProductGalleryRecipeTrainerAgent;
use App\Services\Products\BrowserProductGalleryExtractor;
use App\Services\Products\ProductGalleryRecipeTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * The class of bug this closes: a field is added to the recipe contract, wired
 * into the prompt, the schema, the rules and the validator - and dropped by one
 * of the two whitelists it has to cross on the way to the browser. It happened
 * to after_each_selector, which was asked for, accepted and checked while the
 * runner never received it once, and it nearly happened again to when.
 */
class RecipeActionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ProductGalleryPreflightAgent::fake(fn (): array => [
            'decision' => 'train_playwright',
            'gallery_likely' => true,
            'hidden_images_likely' => true,
            'interaction_required' => true,
            'expected_image_count' => 2,
            'evidence' => ['gallery fixture'],
            'confidence' => 0.95,
            'reason' => 'Fixture requires browser interaction.',
        ])->preventStrayPrompts();
    }

    public function test_the_php_side_admits_exactly_the_agreed_fields(): void
    {
        $this->assertSame(
            $this->agreedFields(),
            app(ProductGalleryRecipeTrainer::class)->recipeActionFields(),
            'Adding an action field means declaring it in the validation rules and in tests/contracts/recipe-action-fields.json, so the JS runner is held to it too.',
        );
    }

    public function test_every_agreed_field_reaches_the_browser(): void
    {
        $executed = null;
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => array_merge($this->workingRecipe(), [
            'actions' => [$this->maximalAction()],
        ]))->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$executed): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->andReturnUsing(
                function (string $url, array $recipe) use (&$executed): array {
                    $executed ??= $recipe;

                    return ['images' => [
                        'https://storage.example/one.webp',
                        'https://storage.example/two.webp',
                        'https://storage.example/three.webp',
                    ]];
                },
            );
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertNotNull($executed, 'The recipe never reached the browser at all.');

        foreach ($this->agreedFields() as $field) {
            $this->assertArrayHasKey(
                $field,
                $executed['actions'][0],
                "The action field [{$field}] was dropped between validation and execution.",
            );
        }
    }

    public function test_an_invented_field_is_still_dropped(): void
    {
        // The reason the whitelist exists at all. Deriving it from the rules
        // must not turn it into a pass-through.
        $executed = null;
        ProductGalleryRecipeTrainerAgent::fake(fn (): array => array_merge($this->workingRecipe(), [
            'actions' => [array_merge($this->maximalAction(), ['evaluate' => 'fetch("https://evil.example")'])],
        ]))->preventStrayPrompts();
        $this->mock(BrowserProductGalleryExtractor::class, function (MockInterface $mock) use (&$executed): void {
            $mock->shouldReceive('scout')->andReturn([
                'scout' => [
                    'title' => 'MSI Katana 17 HX',
                    'fragments' => [],
                    'interactive_controls' => ['<a href="/Gallery">GALLERY</a>'],
                    'network_image_samples' => [],
                    'access_gate' => false,
                    'rate_limited' => false,
                ],
                'diagnostics' => [],
            ]);
            $mock->shouldReceive('executeRecipe')->andReturnUsing(
                function (string $url, array $recipe) use (&$executed): array {
                    $executed ??= $recipe;

                    return ['images' => [
                        'https://storage.example/one.webp',
                        'https://storage.example/two.webp',
                        'https://storage.example/three.webp',
                    ]];
                },
            );
        });

        app(ProductGalleryRecipeTrainer::class)->train(
            'https://us.msi.com/Laptop/Katana-17-HX-B14WX/Specification',
            force: true,
        );

        $this->assertArrayNotHasKey('evaluate', $executed['actions'][0]);
    }

    /** @return array<int, string> */
    private function agreedFields(): array
    {
        $contract = json_decode(
            (string) file_get_contents(base_path('tests/contracts/recipe-action-fields.json')),
            true,
        );

        return $contract['fields'];
    }

    /** @return array<string, mixed> */
    private function maximalAction(): array
    {
        return [
            'kind' => 'click_each',
            'when' => 'if_present',
            'selector' => '.gallery .thumb',
            'index' => 0,
            'limit' => 3,
            'wait_after_ms' => 200,
            'after_each_selector' => '.viewer .zoom',
            'after_each_limit' => 2,
            'after_each_wait_after_ms' => 300,
            'purpose' => 'walk the thumbnails and enlarge each frame',
        ];
    }

    /** @return array<string, mixed> */
    private function workingRecipe(): array
    {
        return [
            'gallery_present' => true,
            'content_confirmed_product' => true,
            'expected_image_count' => 3,
            'expected_count_evidence' => 'The same-product Gallery tab exposes three photos.',
            'pre_click_selectors' => ['a[href*="/Gallery"]'],
            'collect_selectors' => ['.gallery img'],
            'thumbnail_selectors' => [],
            'open_selectors' => [],
            'next_selectors' => [],
            'attributes' => ['src'],
            'max_thumbnail_clicks' => 0,
            'max_next_clicks' => 0,
            'wait_after_click_ms' => 200,
            'confidence' => 0.9,
            'reason' => 'Collect the gallery images.',
        ];
    }
}
