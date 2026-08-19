<?php

namespace App\Services\Ai;

use App\Models\AiRun;
use Illuminate\Http\Client\RequestException;
use Throwable;

class AiErrorPresenter
{
    public function present(Throwable|string|null $error, ?int $reference = null): array
    {
        $raw = mb_strtolower($error instanceof Throwable ? $error->getMessage() : (string) $error);
        $suffix = $reference ? " Код журнала: AI-{$reference}." : '';

        if ($reference && ($run = AiRun::query()->find($reference))) {
            $suffix .= " Провайдер: {$run->provider}, модель: {$run->model}.";
        }

        $providerDetail = $this->providerDetail($error);
        $suffix .= $providerDetail !== '' ? " Ответ провайдера: {$providerDetail}" : '';

        return match (true) {
            str_contains($raw, 'insufficient_quota'), str_contains($raw, 'billing'), str_contains($raw, 'credit'), str_contains($raw, 'quota') => [
                'retryable' => false, 'message' => 'У AI-провайдера закончилась квота или средства. Пополните баланс/лимит и повторите запрос.'.$suffix,
            ],
            str_contains($raw, 'does not exist'), str_contains($raw, 'model_not_found'), str_contains($raw, 'invalid model'), str_contains($raw, 'no such model'), str_contains($raw, 'unknown model') => [
                'retryable' => false, 'message' => 'AI-провайдер не знает такую модель. Проверьте имя модели: Настройки → AI в админке или .env.'.$suffix,
            ],
            str_contains($raw, '401'), str_contains($raw, 'unauthorized'), str_contains($raw, 'api key'), str_contains($raw, 'authentication') => [
                'retryable' => false, 'message' => 'AI-провайдер отклонил API-ключ. Проверьте ключ: Настройки → AI в админке или .env.'.$suffix,
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

    /**
     * Seconds the provider itself asked us to wait before retrying, when it
     * said so: OpenAI's 429 body carries "Please try again in 20.008s." (and
     * sometimes a Retry-After header). Used to schedule the retry at exactly
     * that moment instead of a blind fixed backoff that can fire while the
     * limit is still active (another guaranteed 429) or idle far too long.
     * Returns null when the provider gave no usable number - callers then
     * fall back to the job's fixed backoff.
     */
    public function retryAfterSeconds(Throwable|string|null $error): ?int
    {
        $seconds = null;
        $cause = $this->requestException($error);

        if ($cause?->response) {
            $header = $cause->response->header('Retry-After');
            $seconds = is_numeric($header) ? (float) $header : null;

            if ($seconds === null) {
                $seconds = $this->parseTryAgainIn($cause->response->json('error.message'));
            }
        }

        // Some wrappers embed the provider body in their own message instead
        // of keeping a RequestException in the chain.
        if ($seconds === null) {
            $seconds = $this->parseTryAgainIn($error instanceof Throwable ? $error->getMessage() : $error);
        }

        if ($seconds === null) {
            return null;
        }

        // Small buffer over the provider's number: its limit window resets at
        // a wall-clock boundary, and our clock/queue latency isn't exact.
        return (int) min(900, max(5, (int) ceil($seconds) + 2));
    }

    private function parseTryAgainIn(mixed $message): ?float
    {
        if (! is_string($message)) {
            return null;
        }

        if (preg_match('/try again in\s+(\d+(?:[.,]\d+)?)\s*(ms|milliseconds?|s|sec|seconds?|m|min|minutes?)?/i', $message, $match) !== 1) {
            return null;
        }

        $value = (float) str_replace(',', '.', $match[1]);
        $unit = mb_strtolower($match[2] ?? 's');

        return match (true) {
            str_starts_with($unit, 'ms'), str_starts_with($unit, 'millisecond') => $value / 1000,
            str_starts_with($unit, 'm') => $value * 60,
            default => $value,
        };
    }

    /**
     * Illuminate\Http\Client\RequestException truncates its own getMessage()
     * to 120 chars, discarding exactly the numbers an operator actually needs
     * (which limit, how much was used, retry-after) - but the full JSON body
     * is still on ->response, untouched. Laravel Ai wraps that exception as
     * ->getPrevious(), so dig it back out instead of showing only the generic
     * "rate limited"/"insufficient credits" wrapper text.
     */
    private function providerDetail(Throwable|string|null $error): string
    {
        $cause = $this->requestException($error);

        if (! $cause instanceof RequestException || ! $cause->response) {
            return '';
        }

        $detail = $cause->response->json('error.message');

        return is_string($detail) && $detail !== '' ? mb_substr($detail, 0, 300) : '';
    }

    private function requestException(Throwable|string|null $error): ?RequestException
    {
        $cause = $error instanceof Throwable ? $error : null;

        while ($cause !== null && ! $cause instanceof RequestException) {
            $cause = $cause->getPrevious();
        }

        return $cause;
    }
}
