<?php

namespace App\Ai\Tools\Concerns;

use App\Models\AiOperation;
use App\Models\TelegramUpdate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

trait RecordsOperations
{
    protected function recordOperation(
        ?TelegramUpdate $update,
        string $tool,
        string $action,
        array $payload,
        callable $callback,
        ?string $targetType = null,
        ?int $targetId = null,
    ): array {
        $key = hash('sha256', $this->json([
            'update_id' => $update?->id,
            'tool' => $tool,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload' => $payload,
        ]));

        return Cache::lock('ai-operation:'.$key, 300)->block(10, function () use (
            $update, $tool, $action, $payload, $callback, $targetType, $targetId, $key,
        ): array {
            $existing = AiOperation::query()->where('idempotency_key', $key)->first();

            if ($existing?->status === 'completed') {
                return ['operation_id' => $existing->id, 'ok' => true, ...($existing->result ?? [])];
            }

            return DB::transaction(function () use (
                $update, $tool, $action, $payload, $callback, $targetType, $targetId, $key, $existing,
            ): array {
                $operation = $existing ?: AiOperation::query()->create([
                    'telegram_update_id' => $update?->id,
                    'telegram_user_id' => $update?->telegram_user_id,
                    'tool' => $tool,
                    'action' => $action,
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'idempotency_key' => $key,
                    'payload' => $payload,
                    'status' => 'running',
                ]);

                $operation->update(['status' => 'running', 'error' => null, 'executed_at' => null]);

                try {
                    $result = $callback();
                    $result = is_array($result) ? $result : ['value' => $result];
                    $operation->update([
                        'result' => $result,
                        'status' => 'completed',
                        'executed_at' => now(),
                    ]);

                    return ['operation_id' => $operation->id, 'ok' => true, ...$result];
                } catch (Throwable $exception) {
                    $operation->update([
                        'status' => 'failed',
                        'error' => mb_substr($exception->getMessage(), 0, 5000),
                        'executed_at' => now(),
                    ]);

                    throw $exception;
                }
            });
        });
    }

    protected function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
