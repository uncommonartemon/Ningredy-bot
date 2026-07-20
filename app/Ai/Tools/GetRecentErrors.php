<?php

namespace App\Ai\Tools;

use App\Models\AiRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetRecentErrors implements Tool
{
    public function description(): Stringable|string
    {
        return 'Read recent AI and queue failures. Error text is shortened and secrets are not returned.';
    }

    public function handle(Request $request): Stringable|string
    {
        $limit = min(max($request->integer('limit', 5), 1), 10);
        $sanitize = fn (?string $value): string => mb_substr(preg_replace('/(sk-[A-Za-z0-9_-]+)/', '[redacted]', (string) $value), 0, 500);
        $ai = AiRun::query()->where('status', 'failed')->latest('id')->limit($limit)->get()
            ->map(fn (AiRun $run): array => ['id' => $run->id, 'error' => $sanitize($run->error), 'at' => $run->created_at?->toIso8601String()]);
        $jobs = DB::table('failed_jobs')->latest('id')->limit($limit)->get()
            ->map(fn ($job): array => ['id' => $job->id, 'uuid' => $job->uuid, 'error' => $sanitize($job->exception), 'at' => $job->failed_at]);

        return json_encode(['ai' => $ai, 'jobs' => $jobs], JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()];
    }
}
