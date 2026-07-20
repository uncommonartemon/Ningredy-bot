<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\RecordsOperations;
use App\Models\TelegramUpdate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Stringable;

class RetryFailedJob implements Tool
{
    use RecordsOperations;

    public function __construct(private readonly TelegramUpdate $update) {}

    public function description(): Stringable|string
    {
        return 'Retry one failed Laravel queue job by failed_jobs numeric ID. This is audited.';
    }

    public function handle(Request $request): Stringable|string
    {
        $id = $request->integer('failed_job_id');
        $result = $this->recordOperation(
            $this->update,
            class_basename(self::class),
            'retry_failed_job',
            ['failed_job_id' => $id],
            function () use ($id): array {
                throw_unless(DB::table('failed_jobs')->where('id', $id)->exists(), RuntimeException::class, 'Failed job not found.');
                $exit = Artisan::call('queue:retry', ['id' => [$id]]);
                throw_if($exit !== 0, RuntimeException::class, trim(Artisan::output()) ?: 'Queue retry failed.');

                return ['failed_job_id' => $id, 'queued' => true];
            },
            'failed_job',
            $id,
        );

        return $this->json($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['failed_job_id' => $schema->integer()->required()];
    }
}
