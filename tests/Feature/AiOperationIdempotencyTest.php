<?php

namespace Tests\Feature;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiOperationIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_tool_operation_is_executed_only_once(): void
    {
        $update = TelegramUpdate::query()->create([
            'update_id' => 9001,
            'telegram_user_id' => '123',
            'chat_id' => '123',
            'text' => 'update product',
            'payload' => [],
            'status' => 'processing',
        ]);
        $calls = 0;
        $runner = new class
        {
            use RecordsOperations;

            public function execute(TelegramUpdate $update, int &$calls): array
            {
                return $this->recordOperation(
                    $update,
                    'TestTool',
                    'test_action',
                    ['value' => 42],
                    function () use (&$calls): array {
                        $calls++;

                        return ['value' => 42];
                    },
                );
            }
        };

        $first = $runner->execute($update, $calls);
        $second = $runner->execute($update, $calls);

        $this->assertSame(1, $calls);
        $this->assertSame($first['operation_id'], $second['operation_id']);
        $this->assertDatabaseCount('ai_operations', 1);
    }
}
