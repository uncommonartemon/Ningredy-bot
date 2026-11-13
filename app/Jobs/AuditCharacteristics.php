<?php

namespace App\Jobs;

use App\Services\Catalog\CharacteristicAudit;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** The characteristic audit asked for in Telegram: the result and the doubtful questions go back to that chat. */
class AuditCharacteristics implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $chatId)
    {
        $this->onQueue('default');
    }

    public function handle(CharacteristicAudit $audit, TelegramClient $telegram): void
    {
        try {
            $result = $audit->run($this->chatId);
        } catch (Throwable $exception) {
            report($exception);
            $telegram->sendMessage($this->chatId, 'Аудит характеристик не выполнен: модель не ответила ('
                .mb_substr($exception->getMessage(), 0, 200).'). Ничего не изменено, можно повторить: /audit');

            return;
        }
        $telegram->sendMessage($this->chatId, $audit->summaryText($result));
        foreach ($result['questions'] as $question) {
            $sent = $telegram->sendMessage($this->chatId, $audit->questionText($question), $audit->questionButtons($question));
            $question->update(['chat_id' => $this->chatId, 'message_id' => data_get($sent, 'result.message_id')]);
        }
    }
}
