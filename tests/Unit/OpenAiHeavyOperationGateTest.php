<?php

namespace Tests\Unit;

use App\Services\Ai\OpenAiHeavyOperationGate;
use Carbon\CarbonImmutable;
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
}
