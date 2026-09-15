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

    /**
     * How many OpenAI heavy operations this PHP process is already inside,
     * on this same call stack. Not per-instance: this class is resolved
     * fresh from the container on every call site, so instance state would
     * not survive from an outer run() to a nested one.
     *
     * The gallery trainer holds the lock for the whole duration of its own
     * prompt() call - including the provider's own tool-calling loop inside
     * it. When that loop invokes InspectGalleryImages, that tool's handler
     * calls run() again, for the same 'openai' provider, before the outer
     * call has returned. A second Cache::lock() for the same key has a
     * fresh random owner and cannot see that the process asking is the one
     * already holding it, so it would block on its own outer call and time
     * out - reproduced locally with no real AI request involved. This
     * counter is what lets that nested call skip re-acquiring: it does not
     * touch the distributed lock at all, so a different worker process
     * (whose own copy of this counter starts at zero) still blocks on it
     * exactly as before.
     */
    private static int $depth = 0;

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

        // Already serialized by an outer run() further up this same call
        // stack - see the property doc above. Running directly here is what
        // a call already inside that outer critical section is entitled to;
        // it is never reached by an unrelated call in a fresh process/stack,
        // which starts at depth 0 and takes the real lock below instead.
        if (self::$depth > 0) {
            return $this->executeGuarded($operation);
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

                    return $this->executeGuarded($operation);
                },
            );
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException(
                'OpenAI heavy operation queue timed out while waiting for the previous request.',
                previous: $exception,
            );
        }
    }

    /**
     * Run one already-serialized operation, tracking this process's own
     * reentrancy depth around it so a nested run() call further down this
     * same stack can see it never needs the distributed lock a second time.
     * The increment/decrement is symmetric under every exit path (normal
     * return, thrown exception) so one failed nested call can never leave a
     * later, unrelated top-level call in the same long-lived queue worker
     * process mistakenly believing it is still inside someone else's lock.
     *
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function executeGuarded(Closure $operation): mixed
    {
        self::$depth++;

        try {
            return $operation();
        } catch (Throwable $exception) {
            $retryAfter = app(AiErrorPresenter::class)->retryAfterSeconds($exception);

            if ($retryAfter !== null || $this->isRateLimit($exception)) {
                $this->cooldown($retryAfter ?? 30);
            }

            throw $exception;
        } finally {
            self::$depth--;
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
