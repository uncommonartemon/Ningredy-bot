<?php

namespace Tests\Feature;

use App\Ai\Agents\RenditionRuleAgent;
use App\Ai\Tools\MeasureImageUrls;
use App\Models\AiRun;
use App\Models\ImageRenditionRule;
use App\Services\Photos\RenditionRuleLearner;
use App\Services\Photos\RenditionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

/** Operator, 2026-10-27: "if a site changes something, can the agent change the rule itself?" */
class RenditionRuleLearnerTest extends TestCase
{
    use RefreshDatabase;

    private const PAGE = 'https://93.184.216.37/notebook/acer-swift-go-14';

    protected function setUp(): void
    {
        parent::setUp();
        // The site keeps its thumbnails under /th/ with the size in the name, and the
        // photographs at the same path without both.
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_starts_with($path, '/th/') => Http::response($this->jpeg(470, 470), 200, ['Content-Type' => 'image/jpeg']),
                str_starts_with($path, '/product/') => Http::response($this->jpeg(1600, 1200), 200, ['Content-Type' => 'image/jpeg']),
                default => Http::response('Not found', 404),
            };
        });
    }

    public function test_a_rule_the_agent_measured_is_kept_once_the_server_measures_it_on_the_site_s_own_frames(): void
    {
        RenditionRuleAgent::fake(fn (): array => ['decision' => 'rule', 'find' => '/th/(.+)_w\d{2,4}_h\d{2,4}(\.[a-z]{3,4})$', 'flags' => 'i',
            'replace' => '/{1}{2}', 'reason' => 'The /th/ folder and the _w_h token mark the thumbnail; without both it is 1600 px.'])->preventStrayPrompts();

        $rule = app(RenditionRuleLearner::class)->learn('93.184.216.37', $this->thumbnails(), 600, self::PAGE, null);

        $this->assertNotNull($rule);
        $this->assertSame(['93.184.216.37', 'agent', 'active'], [$rule->host, $rule->origin, $rule->status]);
        $this->assertSame('https://93.184.216.37/product/acer/gallery-1.jpg', $rule->example_to);
        // From now on the probe asks this site for its photographs by the learned rule.
        $this->assertSame([$rule->id], array_values(array_filter(array_column(RenditionRules::forHost('93.184.216.37'), 'id'),
            fn (?int $id): bool => $id === $rule->id)));
        $this->assertDatabaseHas('product_source_attempts', ['action' => 'learn_rendition_rule', 'status' => 'info',
            'decision' => 'rule_learned', 'output->verified' => 3]);
        $this->assertSame('rendition_rule_learning', AiRun::query()->latest('id')->first()->activity['stage']);
    }

    public function test_a_rule_the_site_does_not_confirm_is_not_kept_and_the_site_is_not_asked_again_today(): void
    {
        $asked = 0;
        RenditionRuleAgent::fake(function () use (&$asked): array {
            $asked++;

            return ['decision' => 'rule', 'find' => '/th/', 'flags' => '', 'replace' => '/big/', 'reason' => 'A guess it never measured.'];
        })->preventStrayPrompts();
        $learner = app(RenditionRuleLearner::class);

        $this->assertNull($learner->learn('93.184.216.37', $this->thumbnails(), 600, self::PAGE, null));
        $this->assertNull($learner->learn('93.184.216.37', $this->thumbnails(), 600, self::PAGE, null));

        $this->assertSame(1, $asked);
        $this->assertSame(0, ImageRenditionRule::query()->where('origin', 'agent')->count());
        $this->assertDatabaseHas('product_source_attempts', ['action' => 'learn_rendition_rule', 'decision' => 'no_rule', 'output->verified' => 0]);
    }

    public function test_a_rule_proven_on_the_only_frame_it_applies_to_is_learned(): void
    {
        // Live #328 sintsec.ru: one product thumbnail beside a template icon; the agent
        // measured the rule, and "two frames" threw away the only photo the page had.
        RenditionRuleAgent::fake(fn (): array => ['decision' => 'rule', 'find' => '/th/(.+)_w\d{2,4}_h\d{2,4}(\.[a-z]{3,4})$', 'flags' => 'i',
            'replace' => '/{1}{2}', 'reason' => 'The one product thumbnail measures 1600 px without the /th/ folder and the size token.'])->preventStrayPrompts();

        $rule = app(RenditionRuleLearner::class)->learn('93.184.216.37', [
            ['url' => 'https://93.184.216.37/th/product/acer/gallery-1_w470_h470.jpg', 'width' => 470, 'height' => 470],
            ['url' => 'https://93.184.216.37/static/template-icon.png', 'width' => 100, 'height' => 100],
        ], 600, self::PAGE, null);

        $this->assertNotNull($rule);
        $this->assertDatabaseHas('product_source_attempts', ['action' => 'learn_rendition_rule', 'decision' => 'rule_learned', 'output->verified' => 1]);
    }

    public function test_the_agent_measures_addresses_by_size_within_its_budget(): void
    {
        $tool = new MeasureImageUrls(self::PAGE, budget: 2);

        $measured = json_decode((string) $tool->handle(new ToolRequest(['urls' => [
            'https://93.184.216.37/product/acer/gallery-1.jpg', 'https://93.184.216.37/missing/gallery-1.jpg', 'https://93.184.216.37/product/acer/gallery-2.jpg',
        ]])), true)['measured'];

        $this->assertSame(['url' => 'https://93.184.216.37/product/acer/gallery-1.jpg', 'status' => 'image', 'width' => 1600, 'height' => 1200], $measured[0]);
        $this->assertSame('failed', $measured[1]['status']);
        $this->assertCount(2, $measured);
        $this->assertSame([], json_decode((string) $tool->handle(new ToolRequest(['urls' => ['https://93.184.216.37/product/acer/gallery-3.jpg']])), true)['measured']);
    }

    /** @return list<array{url: string, width: int, height: int}> */
    private function thumbnails(): array
    {
        return array_map(fn (int $index): array => ['url' => "https://93.184.216.37/th/product/acer/gallery-{$index}_w470_h470.jpg",
            'width' => 470, 'height' => 470], [1, 2, 3]);
    }

    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 240, 240, 240));
        imagefilledrectangle($image, intdiv($width, 4), intdiv($height, 4), intdiv($width * 3, 4), intdiv($height * 3, 4), imagecolorallocate($image, 30, 60, 90));
        ob_start();
        imagejpeg($image, null, 85);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
