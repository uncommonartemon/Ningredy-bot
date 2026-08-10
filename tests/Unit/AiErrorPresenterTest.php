<?php

namespace Tests\Unit;

use App\Models\AiRun;
use App\Models\TelegramUpdate;
use App\Services\Ai\AiErrorPresenter;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Tests\TestCase;

class AiErrorPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_model_error_points_to_model_settings(): void
    {
        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('404 The model `deepseek-chet` does not exist'));

        $this->assertFalse($result['retryable']);
        $this->assertStringContainsString('модель', $result['message']);
        $this->assertStringContainsString('Настройки → AI', $result['message']);
    }

    public function test_rejected_key_mentions_admin_panel(): void
    {
        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('401 Unauthorized: invalid api key'));

        $this->assertStringContainsString('Настройки → AI', $result['message']);
    }

    public function test_reference_appends_provider_and_model_context(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(2000, 9000),
            'telegram_user_id' => '12345',
            'chat_id' => '98765',
            'message_id' => 55,
            'text' => 'test',
            'payload' => ['update_id' => 2001],
            'status' => 'received',
        ]);
        $run = AiRun::query()->create([
            'telegram_update_id' => $update->id,
            'provider' => 'deepseek',
            'model' => 'deepseek-chat',
            'status' => 'failed',
            'prompt' => 'test',
            'started_at' => now(),
        ]);

        $result = app(AiErrorPresenter::class)
            ->present(new RuntimeException('401 Unauthorized'), $run->id);

        $this->assertStringContainsString("AI-{$run->id}", $result['message']);
        $this->assertStringContainsString('Провайдер: deepseek', $result['message']);
        $this->assertStringContainsString('модель: deepseek-chat', $result['message']);
    }

    public function test_rate_limit_message_surfaces_the_providers_own_untruncated_reason(): void
    {
        // Real gap (2026-08-05): RequestException::getMessage() truncates the
        // response body to 120 chars, discarding exactly the numbers an
        // operator needs (which limit, how much was used, retry-after) -
        // but the full JSON body survives on ->response. Laravel Ai wraps
        // that RequestException as ->getPrevious() on its own exception, so
        // present() must dig it back out instead of showing only the generic
        // "AI-провайдер временно ограничил частоту запросов" wrapper text.
        $psrResponse = new Psr7Response(429, [], json_encode([
            'error' => [
                'message' => 'Rate limit reached for gpt-5-mini in organization org-xxx on tokens per min (TPM): '
                    .'Limit 200000, Used 195000, Requested 8000. Please try again in 2.4s.',
            ],
        ]));
        $requestException = new RequestException(new Response($psrResponse));
        $rateLimited = new RuntimeException('Application rate limited by AI provider [openai].', 429, $requestException);

        $result = app(AiErrorPresenter::class)->present($rateLimited);

        $this->assertTrue($result['retryable']);
        $this->assertStringContainsString('Rate limit reached for gpt-5-mini', $result['message']);
        $this->assertStringContainsString('tokens per min (TPM)', $result['message']);
    }

    public function test_retry_after_seconds_is_parsed_from_the_provider_body(): void
    {
        // OpenAI's 429 body tells exactly when the TPM window resets
        // ("Please try again in 20.008s."); the queue retry should wait
        // precisely that instead of a blind fixed backoff.
        $psrResponse = new Psr7Response(429, [], json_encode([
            'error' => [
                'message' => 'Rate limit reached for gpt-5-mini on tokens per min (TPM): '
                    .'Limit 200000, Used 195000, Requested 8000. Please try again in 20.008s.',
            ],
        ]));
        $requestException = new RequestException(new Response($psrResponse));
        $rateLimited = new RuntimeException('Application rate limited by AI provider [openai].', 429, $requestException);

        $this->assertSame(23, app(AiErrorPresenter::class)->retryAfterSeconds($rateLimited));
    }

    public function test_retry_after_seconds_prefers_the_retry_after_header(): void
    {
        $psrResponse = new Psr7Response(429, ['Retry-After' => '30'], json_encode([
            'error' => ['message' => 'Rate limit reached. Please try again in 99s.'],
        ]));
        $requestException = new RequestException(new Response($psrResponse));
        $rateLimited = new RuntimeException('Application rate limited by AI provider [openai].', 429, $requestException);

        $this->assertSame(32, app(AiErrorPresenter::class)->retryAfterSeconds($rateLimited));
    }

    public function test_retry_after_seconds_has_a_sane_floor_and_ceiling(): void
    {
        $presenter = app(AiErrorPresenter::class);
        $tooFast = new RequestException(new Response(new Psr7Response(429, [], json_encode([
            'error' => ['message' => 'Rate limit reached. Please try again in 0.4s.'],
        ]))));
        $tooLong = new RequestException(new Response(new Psr7Response(429, [], json_encode([
            'error' => ['message' => 'Rate limit reached. Please try again in 30m.'],
        ]))));

        $this->assertSame(5, $presenter->retryAfterSeconds($tooFast));
        $this->assertSame(900, $presenter->retryAfterSeconds($tooLong));
    }

    public function test_retry_after_seconds_is_null_without_a_provider_number(): void
    {
        $presenter = app(AiErrorPresenter::class);

        $this->assertNull($presenter->retryAfterSeconds(new RuntimeException('Request timed out.')));
        $this->assertNull($presenter->retryAfterSeconds('plain string error'));
        $this->assertNull($presenter->retryAfterSeconds(null));
    }
}
