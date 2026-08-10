<?php

namespace App\Services\Ai;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class OpenAiHeavyOperationGate
{
    public const string LOCK_KEY = 'ai:openai-heavy-operation';

    public const string COOLDOWN_KEY = 'ai:openai-heavy-operation-cooldown-until';

    /** @param (Closure(int): void)|null $sleeper */
    public function __construct(private readonly ?Closure $sleeper = null) {}

    /**
     * Serialize expensive OpenAI stages across workers. The outer Telegram
     * assistant is deliberately not wrapped: it may invoke one of these
     * stages as a tool and would otherwise deadlock on its own lock.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @param  (callable(int): void)|null  $onWait
     * @return T
     */
    public function run(
        string $provider,
        int $operationTimeoutSeconds,
        Closure $operation,
        ?callable $onWait = null,
    ): mixed {
        if (mb_strtolower($provider) !== 'openai') {
            return $operation();
        }

        $this->waitForCooldown($onWait);
        $lock = Cache::lock(self::LOCK_KEY, max(60, $operationTimeoutSeconds + 60));

        try {
            return $lock->block(
                max(1, min(900, $operationTimeoutSeconds)),
                function () use ($operation, $onWait): mixed {
                    // A different worker may have registered a provider
                    // cooldown while this worker was waiting for the lock.
                    $this->waitForCooldown($onWait);

                    try {
                        return $operation();
                    } catch (Throwable $exception) {
                        $retryAfter = app(AiErrorPresenter::class)->retryAfterSeconds($exception);

                        if ($retryAfter !== null || $this->isRateLimit($exception)) {
                            $this->cooldown($retryAfter ?? 30);
                        }

                        throw $exception;
                    }
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'OpenAI heavy operation queue timed out while waiting for the previous request.',
                previous: $exception,
            );
        }
    }

    public function cooldown(int $seconds): void
    {
        $seconds = max(1, min(900, $seconds));
        $until = now()->addSeconds($seconds)->getTimestamp();
        $existing = (int) Cache::get(self::COOLDOWN_KEY, 0);
        $effectiveUntil = max($until, $existing);
        $ttlSeconds = max(1, $effectiveUntil - now()->getTimestamp()) + 5;

        Cache::put(
            self::COOLDOWN_KEY,
            $effectiveUntil,
            now()->addSeconds($ttlSeconds),
        );
    }

    public function cooldownRemainingSeconds(): int
    {
        return max(0, (int) Cache::get(self::COOLDOWN_KEY, 0) - now()->getTimestamp());
    }

    /** @param (callable(int): void)|null $onWait */
    private function waitForCooldown(?callable $onWait): void
    {
        $remaining = $this->cooldownRemainingSeconds();

        if ($remaining <= 0) {
            return;
        }

        $onWait?->__invoke($remaining);
        ($this->sleeper ?? static fn (int $seconds): int => sleep($seconds))($remaining);
    }

    private function isRateLimit(Throwable $exception): bool
    {
        $cause = $exception;

        do {
            $message = mb_strtolower($cause->getMessage());

            if (str_contains($message, 'rate limit') || str_contains($message, 'status code 429')) {
                return true;
            }

            $cause = $cause->getPrevious();
        } while ($cause !== null);

        return false;
    }
}
