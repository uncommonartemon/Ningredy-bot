<?php

namespace App\Jobs;

use App\Ai\Agents\ProductFormAssistantAgent;
use App\Models\AiRun;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductFormAssistant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunProductFormAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 330;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(ProductFormAssistant $assistant): void
    {
        $run = AiRun::find($this->runId);
        if (! $run || $run->status !== 'queued' || ($run->activity['kind'] ?? null) !== ProductFormAssistant::KIND) {
            return;
        }
        try {
            $user = User::findOrFail($run->activity['user_id']);
            $product = isset($run->activity['product_id']) ? Product::findOrFail($run->activity['product_id']) : null;
            $assistant->authorize($user, $product);
            // Atomic claim makes duplicate delivery harmless. Cancelled rows cannot be reclaimed.
            if (! AiRun::whereKey($run->id)->where('status', 'queued')->update(['status' => 'running', 'started_at' => now(), 'last_activity_at' => now()])) {
                return;
            }
            $agent = new ProductFormAssistantAgent($run->activity['mode']);
            $response = $agent->prompt($assistant->prompt($run), provider: $run->provider, model: $run->model, timeout: ProductFormAssistant::TIMEOUT);
            $normalized = $assistant->normalize($response->toArray());
            $assistant->authorize($user, $product);
            $activity = $run->activity;
            $activity['provider_tools'] = $response->toolCalls->map(fn ($call): string => (string) $call->name)->values()->all();
            $activity['provider_citations'] = $response->meta->citations->map(fn ($citation): array => [
                'url' => $citation->url ?? null, 'title' => $citation->title ?? null,
            ])->filter(fn (array $citation): bool => is_string($citation['url']) && $assistant->safeUrl($citation['url']))->values()->all();
            $audit = [
                'activity' => json_encode($activity, JSON_THROW_ON_ERROR),
                'usage' => json_encode($response->usage->toArray(), JSON_THROW_ON_ERROR), 'invocation_id' => $response->invocationId,
                'completed_at' => now(), 'last_activity_at' => now(),
            ];
            AiRun::whereKey($run->id)->where('status', 'running')->update([
                ...$audit, 'status' => 'completed', 'response' => json_encode($normalized, JSON_THROW_ON_ERROR),
            ]);
            // Cancellation suppresses the proposal, not the audit of an already billed response.
            AiRun::whereKey($run->id)->where('status', 'cancelled')->update($audit);
        } catch (Throwable $exception) {
            $this->failed($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        AiRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'error' => $exception ? mb_substr($exception->getMessage(), 0, 2000) : 'Worker stopped',
            'completed_at' => now(),
        ]);
    }
}
