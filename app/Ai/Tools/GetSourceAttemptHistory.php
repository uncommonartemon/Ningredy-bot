<?php

namespace App\Ai\Tools;

use App\Models\ProductSourceAttempt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSourceAttemptHistory implements Tool
{
    public function __construct(
        private readonly string $url,
        private readonly string $domain,
    ) {}

    public function description(): Stringable|string
    {
        return 'Inspect this search\'s own recorded history of accepted/rejected candidates and decisions for '
            .'the current URL or domain. Use this when a previous round\'s outcome is unclear, or before '
            .'guessing at a correction, to see the actual reasons already recorded instead of re-deriving them '
            .'from the DOM alone.';
    }

    public function handle(Request $request): Stringable|string
    {
        $scope = (string) $request->string('scope', 'this_url');
        $scope = in_array($scope, ['this_url', 'this_domain'], true) ? $scope : 'this_url';
        $limit = max(1, min(20, $request->integer('limit', 10)));
        $phase = trim((string) $request->string('phase'));

        $attempts = ProductSourceAttempt::query()
            ->when(
                $scope === 'this_domain',
                fn ($query) => $query->where('domain', $this->domain),
                fn ($query) => $query->where('product_url', $this->url),
            )
            ->when($phase !== '', fn ($query) => $query->where('phase', $phase))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(function (ProductSourceAttempt $attempt): array {
                $output = is_array($attempt->output) ? $attempt->output : [];

                return [
                    'phase' => $attempt->phase,
                    'action' => $attempt->action,
                    'status' => $attempt->status,
                    'decision' => $attempt->decision,
                    'round' => $attempt->round,
                    'message' => $attempt->message,
                    'duration_ms' => $attempt->duration_ms,
                    'created_at' => $attempt->created_at?->toIso8601String(),
                    'failure_kind' => $output['failure_kind'] ?? null,
                    'error' => $output['error'] ?? null,
                    'rejected_candidates' => array_slice($output['rejected_candidates'] ?? [], 0, 5),
                ];
            })
            ->all();

        return json_encode(['attempts' => $attempts], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(['this_url', 'this_domain'])->description(
                'this_url (default) restricts to the exact current URL; this_domain includes every URL tried '
                .'for this domain in this search.',
            ),
            'limit' => $schema->integer()->min(1)->max(20)->description(
                'Maximum number of most recent attempts to return (default 10).',
            ),
            'phase' => $schema->string()->enum([
                'gallery_preflight', 'gallery_training', 'image_download', 'fallback_image_download',
            ])->description('Optional: restrict to one phase of the pipeline.'),
        ];
    }
}
