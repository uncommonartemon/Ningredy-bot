<?php

namespace Tests\Unit;

use App\Services\Products\BrowserProductGalleryExtractor;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Live, 2026-09-06: a search on lenovo.com printed "this domain has no AI
 * recipe yet" one line after printing that the domain's recipes were being
 * probed for compatibility. The domain had a working recipe from twenty
 * minutes earlier. A shop the bot already knows how to open, described as one
 * it has never seen, is what makes a log stop being worth reading.
 */
class RecipeSituationLabelTest extends TestCase
{
    public function test_a_domain_with_recipes_is_never_called_unknown(): void
    {
        $label = $this->label('www.lenovo.com', 3);

        $this->assertStringNotContainsString('ещё нет', $label);
        $this->assertStringContainsString('3', $label);
        $this->assertStringContainsString('не подошли', $label);
    }

    public function test_a_domain_with_none_says_so(): void
    {
        $this->assertStringContainsString('ещё нет AI-рецепта', $this->label('shop.example', 0));
    }

    private function label(string $host, int $recipes): string
    {
        $method = new ReflectionMethod(BrowserProductGalleryExtractor::class, 'recipeSituationLabel');

        return $method->invoke(app(BrowserProductGalleryExtractor::class), $host, $recipes);
    }
}
