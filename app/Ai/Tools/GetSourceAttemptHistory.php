<?php

namespace App\Ai\Tools;

use App\Models\ProductSourceAttempt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetSourceAttemptHistory implements Tool
{
    private const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly string $url,
        private readonly string $domain,
        private readonly ?int $telegramUpdateId = null,
        private readonly ?int $recipeVersionId = null,
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
            ->when(
                $this->telegramUpdateId !== null,
                fn ($query) => $query->where('telegram_update_id', $this->telegramUpdateId),
            )
            ->when(
                $this->telegramUpdateId === null && $this->recipeVersionId !== null,
                fn ($query) => $query->where(
                    'product_gallery_recipe_version_id',
                    $this->recipeVersionId,
                ),
            )
            ->when($phase !== '', fn ($query) => $query->where('phase', $phase))
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ProductSourceAttempt $attempt): array => $this->summary($attempt))
            ->all();

        $omitted = 0;
        do {
            $result = json_encode(['attempts' => $attempts, 'omitted_attempts' => $omitted,
                'details_truncated' => $omitted > 0 || in_array(true, array_column($attempts, 'details_truncated'), true)], self::JSON_FLAGS);
            if (strlen($result) <= 32768) {
                return $result;
            }
            array_pop($attempts); // Keep the newest observations and their failure reasons.
            $omitted++;
        } while ($attempts !== []);

        return json_encode(['attempts' => [], 'omitted_attempts' => $omitted, 'details_truncated' => true], self::JSON_FLAGS);
    }

    private function summary(ProductSourceAttempt $attempt): array
    {
        $output = is_array($attempt->output) ? $attempt->output : [];
        $input = is_array($attempt->input) ? $attempt->input : [];
        $truncated = false;
        // Put the recorded failure ahead of bulky evidence; truncation must not hide the cause.
        $summary = ['id' => $attempt->id, 'product_url' => $attempt->product_url,
            'phase' => $attempt->phase, 'action' => $attempt->action, 'status' => $attempt->status,
            'decision' => $attempt->decision, 'round' => $attempt->round,
            'message' => $attempt->message, 'failure_kind' => $output['failure_kind'] ?? null,
            'error' => $output['error'] ?? null, 'reason' => $output['reason'] ?? null,
            'duration_ms' => $attempt->duration_ms, 'created_at' => $attempt->created_at?->toIso8601String()];
        foreach ($summary as $key => &$value) {
            $value = $key === 'product_url'
                ? $this->bounded($value, $truncated, 1200, stringLimit: 1198)
                : $this->bounded($value, $truncated, 200);
        }
        unset($value);

        $repair = is_array($output['repair_context'] ?? null) ? $output['repair_context'] : [];
        $recovery = is_array($output['recovery'] ?? null) ? $output['recovery'] : [];
        $checks = $output['physical_checks'] ?? data_get($output, '_photo_applicability.physical_checks')
            ?? data_get($output, 'photo_applicability.physical_checks', []);
        $checks = collect(is_array($checks) ? $checks : [])->filter(fn ($check): bool => is_array($check)
            && in_array($check['status'] ?? null, ['unknown', 'conflicting'], true))
            ->map(fn (array $check): array => Arr::only($check, ['status', 'evidence']))->all();
        $counts = Arr::only($output, ['candidate_urls', 'downloaded_images', 'unique_images', 'accepted_images', 'saved_file_count', 'candidate_file_count']);
        foreach (['candidate_urls', 'downloaded_images'] as $key) {
            if (! array_key_exists($key, $counts) && array_key_exists($key, $input) && (is_int($input[$key]) || $input[$key] === null)) {
                $counts[$key] = $input[$key];
            }
        }
        $details = [
            'counts' => array_filter($counts, fn ($count): bool => is_int($count) || $count === null),
            ...(is_bool($output['made_progress'] ?? null) ? ['made_progress' => $output['made_progress']] : []),
            'repair_context' => Arr::only($repair, ['failure_kind', 'validation_errors', 'unresolved_fields', 'recovery_action', 'photo_applicability_conflict', 'photo_result_preserved']),
            'recovery' => Arr::only($recovery, ['stage', 'failure_kind', 'message', 'semantic_verdict', 'available_actions']),
            'physical_checks' => $checks,
            'rejected_candidates' => collect(is_array($output['rejected_candidates'] ?? null) ? $output['rejected_candidates'] : [])
                ->filter(fn ($candidate): bool => is_array($candidate))
                ->map(fn (array $candidate): array => Arr::only($candidate, ['url', 'reason']))->all(),
        ];
        foreach ($details as $key => $value) {
            $remaining = 4000 - strlen(json_encode($summary, self::JSON_FLAGS)) - strlen($key) - 5;
            if ($remaining < 16) {
                $truncated = true;
                break;
            }
            $summary[$key] = $this->bounded($value, $truncated, min(1200, $remaining));
        }

        return [...$summary, 'details_truncated' => $truncated];
    }

    /** Bound only explicitly selected diagnostics, never expose a raw prompt/DOM/card blob. */
    private function bounded(mixed $value, bool &$truncated, int $bytes, int $depth = 0, int $stringLimit = 400): mixed
    {
        if (is_array($value)) {
            if ($depth >= 4) {
                $truncated = true;

                return [];
            }
            $result = [];
            $truncated = $truncated || count($value) > 8;
            foreach (array_slice($value, 0, 8, true) as $key => $child) {
                $safeKey = is_int($key) ? $key : mb_strcut($key, 0, 80, 'UTF-8');
                $truncated = $truncated || $safeKey !== $key;
                $remaining = $bytes - strlen(json_encode($result, self::JSON_FLAGS)) - strlen(json_encode($safeKey, self::JSON_FLAGS)) - 4;
                if ($remaining < 8) {
                    $truncated = true;
                    break;
                }
                $result[$safeKey] = $this->bounded($child, $truncated, $remaining, $depth + 1);
            }

            return $result;
        }
        if (is_string($value)) {
            $length = min($stringLimit, $bytes - 2);
            $result = mb_strcut($value, 0, $length, 'UTF-8');
            while (strlen(json_encode($result, self::JSON_FLAGS)) > $bytes) {
                $result = mb_strcut($result, 0, max(0, strlen($result) - 8), 'UTF-8');
            }
            $truncated = $truncated || $result !== $value;

            return $result;
        }

        return $value;
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
                'source_selection', 'source_resolution', 'image_verification', 'card_assembly', 'gallery_cycle',
            ])->description('Optional: restrict to one phase of the pipeline.'),
        ];
    }
}
