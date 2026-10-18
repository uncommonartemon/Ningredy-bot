<?php

namespace App\Services\Telegram;

use App\Models\AiOperation;
use App\Models\TelegramUpdate;
use App\Services\Ai\ProductSearchCancellation;
use App\Services\DatabaseOperator\DatabaseOperatorService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class TelegramDatabaseOperator
{
    public function assertAuthorized(TelegramUpdate $update): void
    {
        $fresh = TelegramUpdate::query()->find($update->id);
        if (! $fresh || ! app(TelegramOperatorAccess::class)->allows($fresh->telegram_user_id)
            || (string) $fresh->telegram_user_id !== (string) $update->telegram_user_id
            || (string) $fresh->chat_id !== (string) $update->chat_id || ! filled($fresh->chat_id)) {
            throw new AuthorizationException('Управление базой доступно только разрешённому оператору Telegram.');
        }
        app(ProductSearchCancellation::class)->throwIfCancelled($update->id);
    }

    public function prepare(TelegramUpdate $update, array $commands): array
    {
        $this->assertAuthorized($update);
        $key = hash('sha256', json_encode([$update->id, 'database_changes', $commands], JSON_THROW_ON_ERROR));

        return Cache::lock('database-prepare:'.$key, 60)->block(10, function () use ($update, $commands, $key): array {
            $operation = AiOperation::query()->where('idempotency_key', $key)->first();
            if (! $operation) {
                $plan = app(DatabaseOperatorService::class)->prepare($commands, $update);
                $this->assertAuthorized($update);
                $empty = (int) ($plan['affected_count'] ?? 0) === 0;
                $operation = AiOperation::query()->create([
                    'telegram_update_id' => $update->id, 'telegram_user_id' => $update->telegram_user_id,
                    'tool' => 'ManageDatabase', 'action' => 'database_changes', 'target_type' => 'database_batch',
                    'idempotency_key' => $key, 'status' => $empty ? 'completed' : 'awaiting_confirmation',
                    'payload' => ['plan' => $plan, 'expires_at' => now()->addMinutes(10)->getTimestamp()],
                    'result' => $empty ? ['affected_count' => 0, 'message' => 'Подходящих записей нет. Изменения не требуются.'] : null,
                    'executed_at' => $empty ? now() : null,
                ]);
            }

            return [
                'ok' => in_array($operation->status, ['awaiting_confirmation', 'completed'], true),
                'confirmation_required' => $operation->status === 'awaiting_confirmation',
                'operation_id' => $operation->id, 'status' => $operation->status,
                'affected_count' => (int) data_get($operation->payload, 'plan.affected_count', 0),
                'message' => $operation->status === 'completed' ? ($operation->result['message'] ?? 'Операция уже выполнена.') : null,
                'preview' => $operation->status === 'awaiting_confirmation' ? mb_substr($this->preview($operation), 0, 3000) : null,
            ];
        });
    }

    public function preview(AiOperation $operation): string
    {
        $lines = ['Подтвердите изменения данных:', ''];
        foreach ((array) data_get($operation->payload, 'plan.commands', []) as $command) {
            $verb = match ($command['action'] ?? '') {
                'create' => 'Создать', 'update' => 'Изменить', 'delete' => 'Удалить', default => 'Обработать',
            };
            $table = (string) ($command['table'] ?? '');
            $count = (int) ($command['count'] ?? count($command['ids'] ?? []));
            $lines[] = "{$verb} · {$table}: {$count}";
            if (filled($command['scope_note'] ?? null)) {
                $lines[] = $command['scope_note'];
            }
            if (($command['ids'] ?? []) !== []) {
                $ids = array_slice($command['ids'], 0, 12);
                $lines[] = 'ID: '.implode(', ', $ids).(count($command['ids']) > count($ids) ? '…' : '');
            }
            foreach (array_slice((array) ($command['preview'] ?? []), 0, 3) as $row) {
                $label = $row['title'] ?? $row['name'] ?? $row['key'] ?? null;
                if (is_string($label)) {
                    $lines[] = '  '.mb_substr($label, 0, 120);
                }
            }
            if (($command['values'] ?? []) !== []) {
                $lines[] = 'Значения: '.json_encode($command['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            foreach ($command['effects'] ?? [] as $effect) {
                $effectVerb = $effect['action'] === 'delete' ? 'Также удалить связанные записи' : 'Также изменить связанные записи';
                $lines[] = $effectVerb.' · '.$effect['table'].': '.$effect['count'];
                if ($effect['values'] !== []) {
                    $lines[] = 'Значения: '.json_encode($effect['values'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                }
            }
            $lines[] = '';
        }
        $lines[] = '';
        $lines[] = 'Будут обработаны только записи, выбранные при подготовке. Удаление безвозвратное.';

        return implode("\n", $lines);
    }

    public function sendConfirmation(TelegramClient $telegram, string $chatId, AiOperation $operation, string $footnote = ''): void
    {
        $parts = mb_str_split($this->preview($operation), 3500);
        $last = count($parts) - 1;
        foreach ($parts as $index => $part) {
            $heading = count($parts) > 1 ? 'Операция #'.$operation->id.' · часть '.($index + 1).'/'.count($parts)."\n\n" : '';
            $telegram->sendMessage($chatId, $heading.$part.($index === $last ? $footnote : ''),
                $index === $last ? $this->keyboard($operation) : null);
        }
    }

    public function keyboard(AiOperation $operation): array
    {
        return ['inline_keyboard' => [[
            ['text' => 'Подтвердить удаление', 'callback_data' => "db:changes:confirm:{$operation->id}"],
            ['text' => 'Отмена', 'callback_data' => "db:changes:cancel:{$operation->id}"],
        ]]];
    }

    public function cancelPending(TelegramUpdate $update): void
    {
        AiOperation::query()->where('action', 'database_changes')->where('status', 'awaiting_confirmation')
            ->where('telegram_user_id', $update->telegram_user_id)
            ->whereHas('telegramUpdate', fn ($query) => $query->where('chat_id', $update->chat_id))
            ->update(['status' => 'cancelled', 'executed_at' => now()]);
    }

    public function resolve(int $operationId, string $action, TelegramUpdate $update): array
    {
        try {
            $this->assertAuthorized($update);
        } catch (Throwable) {
            return ['status' => 'rejected', 'message' => 'Нет доступа к этой операции.'];
        }
        if (! in_array($action, ['confirm', 'cancel'], true)) {
            return ['status' => 'rejected', 'message' => 'Неизвестное действие.'];
        }

        return Cache::lock('database-confirm:'.$operationId, 60)->block(10, function () use ($operationId, $action, $update): array {
            try {
                return DB::transaction(function () use ($operationId, $action, $update): array {
                    $operation = AiOperation::query()->with('telegramUpdate')->lockForUpdate()->find($operationId);
                    if (! $operation || $operation->action !== 'database_changes') {
                        return ['status' => 'not_found', 'message' => 'Операция не найдена.'];
                    }
                    if ((string) $operation->telegram_user_id !== (string) $update->telegram_user_id
                        || (string) $operation->telegramUpdate?->chat_id !== (string) $update->chat_id) {
                        return ['status' => 'rejected', 'message' => 'Операция принадлежит другому оператору или чату.'];
                    }
                    if ($operation->status !== 'awaiting_confirmation') {
                        return ['status' => 'already_processed', 'message' => 'Операция уже обработана: '.$operation->status.'.'];
                    }
                    $source = $operation->telegramUpdate;
                    if (! $source || $source->status === 'cancelled' || $source->cancel_requested_at !== null || $action === 'cancel') {
                        $operation->update(['status' => 'cancelled', 'executed_at' => now(),
                            'result' => ['cancelled_by_update_id' => $update->id]]);

                        return ['status' => 'completed', 'message' => 'Операция отменена.', 'notify' => true];
                    }
                    $expires = data_get($operation->payload, 'expires_at');
                    if (! is_numeric($expires) || (int) $expires <= now()->getTimestamp()) {
                        $operation->update(['status' => 'expired', 'executed_at' => now()]);

                        return ['status' => 'completed', 'message' => 'Подтверждение устарело. Повторите запрос.', 'notify' => true];
                    }
                    $result = app(DatabaseOperatorService::class)->execute((array) data_get($operation->payload, 'plan', []), $source);
                    $operation->update(['status' => 'completed', 'executed_at' => now(),
                        'result' => [...$result, 'confirmed_by_update_id' => $update->id]]);
                    $count = (int) ($result['affected_count'] ?? 0);

                    return ['status' => 'completed', 'message' => "Готово. Обработано записей: {$count}. Операция #{$operation->id}.", 'notify' => true];
                });
            } catch (Throwable $exception) {
                report($exception);
                $operation = AiOperation::query()->with('telegramUpdate')->find($operationId);
                // A file-cleanup dispatch failing after commit cannot replay a completed batch.
                if ($operation?->status === 'completed') {
                    return ['status' => 'completed', 'message' => 'Изменения сохранены. Операция #'.$operationId.'.', 'notify' => true];
                }
                if ($operation?->status === 'awaiting_confirmation'
                    && (string) $operation->telegram_user_id === (string) $update->telegram_user_id
                    && (string) $operation->telegramUpdate?->chat_id === (string) $update->chat_id) {
                    $stale = preg_match('/stale|changed|измен|устарел/iu', $exception->getMessage()) === 1;
                    $operation->update(['status' => $stale ? 'stale' : 'failed',
                        'error' => mb_substr($exception->getMessage(), 0, 2000), 'executed_at' => now()]);
                }

                return ['status' => 'failed', 'message' => 'Операция не применена. '.mb_substr($exception->getMessage(), 0, 700), 'notify' => true];
            }
        });
    }
}
