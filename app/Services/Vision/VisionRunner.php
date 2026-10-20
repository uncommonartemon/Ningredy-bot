<?php

namespace App\Services\Vision;

use App\Exceptions\ProductSearchCancelled;
use App\Models\AiRun;
use App\Services\Ai\AiSettings;
use App\Services\Ai\OpenAiHeavyOperationGate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\Ai\ProductSearchTimeBudget;
use Closure;
use Laravel\Ai\Contracts\Agent;
use Throwable;

/** One provider call. Retry and publication decisions belong to the caller. */
class VisionRunner
{
    public function __construct(
        private readonly AiSettings $settings,
        private readonly OpenAiHeavyOperationGate $gate,
        private readonly ProductSearchTimeBudget $timeBudget,
        private readonly ProductSearchCancellation $cancellation,
    ) {}

    /** @param Closure(array): (array|VisionResponse)|null $validate */
    public function run(
        string $operation,
        Agent $agent,
        string $prompt,
        array $attachments,
        VisionCallContext $context,
        ?Closure $validate = null,
    ): array {
        $provider = $context->provider ?? $this->settings->providerFor('product_image_vision');
        $model = $context->model ?? $this->settings->modelFor('product_image_vision');
        $run = AiRun::query()->create([
            'telegram_update_id' => $context->telegramUpdateId,
            'provider' => $provider, 'model' => $model,
            'status' => 'running', 'prompt' => $prompt, 'started_at' => now(),
            'activity' => ['stage' => 'vision', 'vision_operation' => $operation, 'image_count' => count($attachments),
                'search_budget' => $context->useSearchBudget],
        ]);

        try {
            $this->checkScope($context);
            $response = $this->gate->run($provider, $this->timeout($context), function () use ($agent, $prompt, $attachments, $provider, $model, $context) {
                // The lock may have consumed the remaining deadline, or the
                // operator may have cancelled while another request was running.
                $this->checkScope($context);

                return $agent->prompt($prompt, attachments: $attachments, provider: $provider,
                    model: $model, timeout: $this->timeout($context));
            });
            // Save usage before decoding/validating the paid answer or checking
            // cancellation. A bad answer is not a free request.
            $run->update(['invocation_id' => $response->invocationId, 'usage' => $response->usage->toArray()]);
            $data = $response->toArray();
            $run->update(['response' => $data]);
            $this->cancellation->throwIfCancelled($context->telegramUpdateId);
            $validated = $validate ? $validate($data) : $data;
            $result = $validated instanceof VisionResponse ? $validated : new VisionResponse($validated);
            $run->update([
                'status' => $result->validationError === null ? 'completed' : 'failed',
                'response' => $result->data,
                'error' => $result->validationError === null ? null : mb_substr($result->validationError, 0, 5000),
                'completed_at' => now(),
            ]);

            return $result->data;
        } catch (Throwable $exception) {
            $run->update([
                'status' => match (true) {
                    $exception instanceof ProductSearchCancelled => 'cancelled',
                    $exception instanceof VisionTimeBudgetExceeded => 'interrupted',
                    default => 'failed',
                },
                'error' => mb_substr($exception->getMessage(), 0, 5000),
                'completed_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function checkScope(VisionCallContext $context): void
    {
        $this->cancellation->throwIfCancelled($context->telegramUpdateId);
        if ($context->useSearchBudget && ! $this->timeBudget->canStart($context->telegramUpdateId, 15)) {
            throw new VisionTimeBudgetExceeded('time_budget: Vision operation cannot start within the current search.');
        }
    }

    private function timeout(VisionCallContext $context): int
    {
        $timeout = max(1, $context->timeoutSeconds ?? $this->settings->imageVisionTimeoutSeconds());

        return $context->useSearchBudget ? $this->timeBudget->timeoutFor($context->telegramUpdateId, $timeout) : $timeout;
    }
}
