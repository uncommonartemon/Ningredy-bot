<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiHeavyOperationGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class OpenAiHeavyOperationGateTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_rate_limit_creates_a_shared_cooldown_for_the_next_openai_operation(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $gate = new OpenAiHeavyOperationGate;

        try {
            $gate->run('openai', 30, fn () => throw new RuntimeException(
                'Rate limit reached. Please try again in 11s.',
            ));
            $this->fail('The provider exception must be re-thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Rate limit reached', $exception->getMessage());
        }

        // 11 seconds from the provider plus the existing two-second buffer.
        $this->assertSame(13, $gate->cooldownRemainingSeconds());

        $slept = [];
        $waitingGate = new OpenAiHeavyOperationGate(function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
            CarbonImmutable::setTestNow(now()->addSeconds($seconds));
        });

        $this->assertSame('done', $waitingGate->run('openai', 30, fn (): string => 'done'));
        $this->assertSame([13], $slept);
    }

    public function test_non_openai_operations_do_not_wait_on_the_openai_cooldown(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $slept = [];
        $gate = new OpenAiHeavyOperationGate(function (int $seconds) use (&$slept): void {
            $slept[] = $seconds;
        });
        $gate->cooldown(30);

        $this->assertSame('done', $gate->run('anthropic', 30, fn (): string => 'done'));
        $this->assertSame([], $slept);
    }

    public function test_rate_limit_without_retry_after_uses_the_fixed_thirty_second_cooldown(): void
    {
        CarbonImmutable::setTestNow('2026-08-09 12:00:00');
        $gate = new OpenAiHeavyOperationGate;

        try {
            $gate->run('openai', 30, fn () => throw new RuntimeException('HTTP request returned status code 429'));
            $this->fail('The provider exception must be re-thrown.');
        } catch (RuntimeException) {
            // Expected: the gate records cooldown and preserves the error.
        }

        $this->assertSame(30, $gate->cooldownRemainingSeconds());

        // A later shorter signal must never erase a longer active cooldown.
        $gate->cooldown(5);
        $this->assertSame(30, $gate->cooldownRemainingSeconds());
    }

    public function test_a_nested_call_in_the_same_process_does_not_wait_on_its_own_lock(): void
    {
        // The gallery trainer holds this lock for the duration of its own
        // prompt() call, including the provider's own tool-calling loop
        // inside it. When that loop calls InspectGalleryImages, that tool
        // calls run() again for 'openai' before the outer call has
        // returned - reproduced here with two independently-resolved gate
        // instances (as app(OpenAiHeavyOperationGate::class) would give at
        // each call site), a short timeout, and no real AI request. Before
        // the fix this timed out on its own outer call every time.
        $outerGate = new OpenAiHeavyOperationGate;
        $innerGate = new OpenAiHeavyOperationGate;
        $innerRan = false;

        $startedAt = microtime(true);
        $result = $outerGate->run('openai', 1, function () use ($innerGate, &$innerRan): string {
            return $innerGate->run('openai', 1, function () use (&$innerRan): string {
                $innerRan = true;

                return 'inner-result';
            });
        });
        $elapsed = microtime(true) - $startedAt;

        $this->assertTrue($innerRan);
        $this->assertSame('inner-result', $result);
        // A real lock-wait would take the whole configured timeout (>= 1s
        // here); completing well under that proves no wait happened at all,
        // not merely that it eventually recovered.
        $this->assertLessThan(0.5, $elapsed);
    }

    public function test_the_lock_is_released_after_an_exception_so_the_next_call_can_proceed(): void
    {
        $gate = new OpenAiHeavyOperationGate;

        try {
            $gate->run('openai', 5, fn () => throw new RuntimeException('boom'));
            $this->fail('The operation exception must be re-thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }

        // Acquired directly, non-blocking: only succeeds if nothing is still
        // holding the real lock after the failed call above.
        $probe = Cache::lock(OpenAiHeavyOperationGate::LOCK_KEY, 1);
        $this->assertTrue($probe->get(), 'The lock must be released even when the guarded operation throws.');
        $probe->release();
    }

    public function test_a_lock_genuinely_held_by_another_owner_still_blocks_this_call(): void
    {
        // The reentrancy fix must only skip the real lock for a call nested
        // inside this same process's own outer run() - never for an
        // unrelated call that happens to find the lock held by someone
        // else. Acquired directly here (not through the gate) to stand in
        // for a different worker process, with this gate's own depth still
        // at its normal zero.
        $foreignHolder = Cache::lock(OpenAiHeavyOperationGate::LOCK_KEY, 10);
        $this->assertTrue($foreignHolder->get());

        try {
            $gate = new OpenAiHeavyOperationGate;

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('OpenAI heavy operation queue timed out');
            $gate->run('openai', 1, fn (): string => 'should not run');
        } finally {
            $foreignHolder->release();
        }
    }
}
