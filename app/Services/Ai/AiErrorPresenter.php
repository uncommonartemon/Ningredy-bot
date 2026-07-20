<?php

namespace App\Services\Ai;

use Throwable;

class AiErrorPresenter
{
    public function present(Throwable|string|null $error, ?int $reference = null): array
    {
        $raw = mb_strtolower($error instanceof Throwable ? $error->getMessage() : (string) $error);
        $suffix = $reference ? " Код журнала: AI-{$reference}." : '';

        return match (true) {
            str_contains($raw, 'insufficient_quota'), str_contains($raw, 'billing'), str_contains($raw, 'credit balance'), str_contains($raw, 'quota') => [
                'retryable' => false, 'message' => 'У AI-провайдера закончилась квота или средства. Пополните баланс/лимит и повторите запрос.'.$suffix,
            ],
            str_contains($raw, '401'), str_contains($raw, 'unauthorized'), str_contains($raw, 'api key'), str_contains($raw, 'authentication') => [
                'retryable' => false, 'message' => 'AI-провайдер отклонил API-ключ. Проверьте ключ в .env и конфигурацию провайдера.'.$suffix,
            ],
            str_contains($raw, 'context length'), str_contains($raw, 'maximum context'), str_contains($raw, 'too many tokens') => [
                'retryable' => false, 'message' => 'Запрос или история диалога слишком длинные для модели. Сформулируйте короче или начните новый диалог.'.$suffix,
            ],
            str_contains($raw, '429'), str_contains($raw, 'rate limit') => [
                'retryable' => true, 'message' => 'AI-провайдер временно ограничил частоту запросов. Повторю автоматически.'.$suffix,
            ],
            str_contains($raw, 'timeout'), str_contains($raw, 'timed out') => [
                'retryable' => true, 'message' => 'AI не успел ответить вовремя. Повторю запрос автоматически.'.$suffix,
            ],
            str_contains($raw, 'ssl'), str_contains($raw, 'connection'), str_contains($raw, 'could not resolve'), str_contains($raw, 'network') => [
                'retryable' => true, 'message' => 'Нет стабильного соединения с AI-провайдером. Повторю автоматически.'.$suffix,
            ],
            default => [
                'retryable' => false, 'message' => 'Не удалось выполнить запрос. Ошибка записана в журнал; можно открыть «Ошибки» в Telegram.'.$suffix,
            ],
        };
    }
}
