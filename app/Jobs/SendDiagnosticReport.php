<?php

namespace App\Jobs;

use App\Services\Support\DiagnosticReport;
use App\Services\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * /report: the diagnostic archive, built off the webhook and sent to the chat
 * as a document. A failure is told to the chat in words, never left silent.
 */
class SendDiagnosticReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public string $chatId, public int $days = 7) {}

    public function handle(DiagnosticReport $report, TelegramClient $telegram): void
    {
        try {
            $path = $report->build($this->days);
        } catch (Throwable $exception) {
            report($exception);
            $telegram->sendMessage($this->chatId, 'Отчёт собрать не удалось: '.mb_substr($exception->getMessage(), 0, 300));

            return;
        }
        try {
            $telegram->sendDocumentFile($this->chatId, $path,
                'Отчёт для разработчика за '.$this->days.' дн.: журнал поисков, вызовы AI, карточки, выученные правила сайтов, лог ошибок. Без ключей и паролей.');
        } catch (Throwable $exception) {
            report($exception);
            $telegram->sendMessage($this->chatId, 'Отчёт собран ('.round(filesize($path) / 1_048_576, 1).' МБ), но отправить файл не удалось: '
                .mb_substr($exception->getMessage(), 0, 300).' Файл лежит в storage/app/reports.');

            return;
        }
        @unlink($path);
    }
}
