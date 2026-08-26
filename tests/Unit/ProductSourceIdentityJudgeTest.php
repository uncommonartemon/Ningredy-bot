<?php

namespace Tests\Unit;

use App\Ai\Agents\ProductSourceIdentityAgent;
use App\Models\AiRun;
use App\Models\ProductDraft;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiSettings;
use App\Services\Products\ProductSourceIdentityJudge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSourceIdentityJudgeTest extends TestCase
{
    use RefreshDatabase;

    private function telegramUpdateId(): int
    {
        return TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000, 9_000_000),
            'telegram_user_id' => '12345',
            'chat_id' => '12345',
            'text' => 'test',
            'payload' => ['update_id' => 1],
            'status' => 'received',
        ])->id;
    }

    public function test_it_returns_uncertain_without_calling_the_agent_when_disabled(): void
    {
        app(AiSettings::class)->saveSourceIdentityAgentEnabled(false);
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'confirmed',
            'confidence' => 0.9,
            'reason' => 'should never be reached',
        ])->preventStrayPrompts();

        $draft = new ProductDraft(['model' => 'MacBook Air 15 (M4)']);

        $result = app(ProductSourceIdentityJudge::class)->judge($draft, ['url' => 'https://example.com/x'], null);

        $this->assertSame('uncertain', $result);
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_it_returns_uncertain_without_calling_the_agent_when_the_draft_has_no_identifier(): void
    {
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'confirmed',
            'confidence' => 0.9,
            'reason' => 'should never be reached',
        ])->preventStrayPrompts();

        $draft = new ProductDraft(['model' => '']);

        $result = app(ProductSourceIdentityJudge::class)->judge($draft, ['url' => 'https://example.com/x'], null);

        $this->assertSame('uncertain', $result);
        $this->assertSame(0, AiRun::query()->count());
    }

    public function test_it_returns_the_agents_confirmed_match_and_records_a_completed_ai_run(): void
    {
        ProductSourceIdentityAgent::fake(function (string $prompt): array {
            $payload = json_decode($prompt, true);
            $this->assertSame('MacBook Air 15 (M4)', $payload['requested_model']);
            $this->assertSame(
                'https://www.bhphotovideo.com/c/product/1883955-REG/apple_mc7a4ll_a_15_macbook_air_m4.html',
                $payload['evidence_url'],
            );

            return [
                'match' => 'confirmed',
                'confidence' => 0.92,
                'reason' => 'Тот же SKU, порядок слов другой.',
            ];
        })->preventStrayPrompts();

        $draft = new ProductDraft([
            'model' => 'MacBook Air 15 (M4)',
            'specifications' => [['key' => 'sku', 'value' => 'MC7A4LL/A']],
        ]);

        $result = app(ProductSourceIdentityJudge::class)->judge($draft, [
            'url' => 'https://www.bhphotovideo.com/c/product/1883955-REG/apple_mc7a4ll_a_15_macbook_air_m4.html',
            'title' => 'Apple 15" MacBook Air (M4, Sky Blue)',
        ], $this->telegramUpdateId());

        $this->assertSame('confirmed', $result);
        $this->assertSame(1, AiRun::query()->count());
        $this->assertSame('completed', AiRun::query()->first()->status);
    }

    public function test_it_returns_the_agents_conflicting_match(): void
    {
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'conflicting',
            'confidence' => 0.8,
            'reason' => 'Другая модификация.',
        ])->preventStrayPrompts();

        $draft = new ProductDraft(['model' => 'MacBook Air 15 (M4)']);

        $result = app(ProductSourceIdentityJudge::class)->judge($draft, ['url' => 'https://example.com/x'], null);

        $this->assertSame('conflicting', $result);
    }

    public function test_an_invalid_agent_response_degrades_to_uncertain_and_records_a_failed_ai_run(): void
    {
        ProductSourceIdentityAgent::fake(fn (): array => [
            'match' => 'not-a-real-option',
            'confidence' => 0.5,
            'reason' => 'x',
        ])->preventStrayPrompts();

        $draft = new ProductDraft(['model' => 'MacBook Air 15 (M4)']);

        $result = app(ProductSourceIdentityJudge::class)->judge($draft, ['url' => 'https://example.com/x'], $this->telegramUpdateId());

        $this->assertSame('uncertain', $result);
        $this->assertSame('failed', AiRun::query()->first()->status);
    }
}
