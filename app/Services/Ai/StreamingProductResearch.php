<?php

namespace App\Services\Ai;

use App\Ai\Agents\ProductResearchAgent;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Str;
use JsonException;
use Laravel\Ai\Ai;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use RuntimeException;
use Throwable;

class StreamingProductResearch
{
    /** @param (Closure(int): ActivityAwareOpenAiGateway)|null $gatewayFactory */
    public function __construct(
        private readonly ?Closure $gatewayFactory = null,
        private readonly ?OpenAiHeavyOperationGate $heavyOperationGate = null,
    ) {}

    /**
     * Run the same agent, Web Search tool and strict schema as prompt(), but
     * consume Responses API SSE events so network inactivity is observable.
     *
     * @param  (callable(StreamEvent): void)|null  $onActivity
     */
    public function prompt(
        ProductResearchAgent $agent,
        string $prompt,
        string $providerName,
        string $model,
        int $hardTimeoutSeconds,
        int $idleTimeoutSeconds,
        ?callable $onActivity = null,
        ?callable $onWait = null,
    ): AgentResponse {
        if (ProductResearchAgent::isFaked() || $providerName !== 'openai') {
            return $agent->prompt(
                $prompt,
                provider: $providerName,
                model: $model,
                timeout: $hardTimeoutSeconds,
            );
        }

        return ($this->heavyOperationGate ?? app(OpenAiHeavyOperationGate::class))->run(
            $providerName,
            $hardTimeoutSeconds,
            fn (): AgentResponse => $this->promptOpenAi(
                $agent,
                $prompt,
                $providerName,
                $model,
                $hardTimeoutSeconds,
                $idleTimeoutSeconds,
                $onActivity,
            ),
            $onWait,
        );
    }

    private function promptOpenAi(
        ProductResearchAgent $agent,
        string $prompt,
        string $providerName,
        string $model,
        int $hardTimeoutSeconds,
        int $idleTimeoutSeconds,
        ?callable $onActivity,
    ): AgentResponse {

        $provider = Ai::textProviderFor($agent, $providerName);

        if (! $provider instanceof Provider) {
            throw new RuntimeException("Provider [{$providerName}] cannot use the OpenAI streaming gateway.");
        }

        $invocationId = (string) Str::uuid7();
        $gateway = $this->makeGateway($idleTimeoutSeconds);
        $schema = $agent->schema(new JsonSchemaTypeFactory);
        $stream = $gateway->generateStreamStep(
            $invocationId,
            $provider,
            $model,
            (string) $agent->instructions(),
            [new UserMessage($prompt)],
            [...$agent->tools()],
            $schema,
            TextGenerationOptions::forAgent($agent),
            $hardTimeoutSeconds,
            new StepContext(stepNumber: 0, isFinalStep: true),
        );

        try {
            foreach ($stream as $event) {
                $onActivity?->__invoke($event);

                if ($event instanceof Error) {
                    throw new RuntimeException($event->message);
                }
            }

            $step = $stream->getReturn();
        } catch (Throwable $exception) {
            if ($this->isIdleTimeout($exception)) {
                throw new ProductResearchIdleTimeoutException(
                    "Web Search не присылал данных {$idleTimeoutSeconds} сек. и был остановлен как зависший.",
                    previous: $exception,
                );
            }

            throw $exception;
        }

        if (! $step instanceof StepResponse || $step->finishReason === FinishReason::Error) {
            throw new RuntimeException('OpenAI streaming response ended without a complete product research result.');
        }

        try {
            $structured = json_decode($step->text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('OpenAI returned invalid structured product research JSON.', previous: $exception);
        }

        if (! is_array($structured)) {
            throw new RuntimeException('OpenAI returned an empty structured product research result.');
        }

        return new StructuredAgentResponse(
            $invocationId,
            $structured,
            $step->text,
            $step->usage,
            $step->meta,
        );
    }

    protected function makeGateway(int $idleTimeoutSeconds): ActivityAwareOpenAiGateway
    {
        if ($this->gatewayFactory !== null) {
            return ($this->gatewayFactory)($idleTimeoutSeconds);
        }

        return new ActivityAwareOpenAiGateway(app(Dispatcher::class), $idleTimeoutSeconds);
    }

    private function isIdleTimeout(Throwable $exception): bool
    {
        $message = mb_strtolower($exception->getMessage());

        return str_contains($message, 'operation too slow')
            || str_contains($message, 'less than 1 bytes/sec')
            || str_contains($message, 'read timeout');
    }
}
